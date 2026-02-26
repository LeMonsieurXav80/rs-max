# Meta/Facebook API Permissions - Investigation (26 Feb 2026)

## Probleme Actuel

Le token a `pages_read_engagement` dans ses scopes (confirme par `debug_token`), mais l'API renvoie l'erreur #10 :
```
"(#10) This endpoint requires the 'pages_read_engagement' permission
or the 'Page Public Content Access' feature."
```

### Symptomes
- `/{page_id}/posts?fields=likes.summary(true),comments.summary(true),shares` -> **400 error #10**
- `/{page_id}/posts?fields=id,message,full_picture,permalink_url,created_time` -> **200 OK** (fonctionne)
- `/{post_id}/comments` -> **400 error #10**
- `/{post_id}/reactions` -> **400 error #10**
- `/{post_id}/insights/post_reactions_like_total` -> **200 mais data: []**
- `debug_token` confirme : `pages_read_engagement` EST dans les scopes avec les bons `target_ids`

### Contexte
- Token obtenu via **Facebook Login for Business** (config_id)
- `/me/accounts` retourne vide -> fallback via `debug_token` + `/{page_id}?fields=access_token`
- L'import a fonctionne une fois (10:34:07) avec un ancien token, puis a cesse apres re-authentification

---

## Bug Connu

Le **meme probleme exact** est rapporte sur le Meta Community Forum :
- **Post** : https://communityforums.atmeta.com/discussions/Questions_Discussions/pages-read-engagement-permission-present-in-token-scopes-but-api-returns-error/1365694
- **Statut** : Non resolu (pas de reponse officielle Meta)
- **Description** : Token via Facebook Login for Business, `pages_read_engagement` dans les scopes, API refuse avec erreur #10
- **Autres developpeurs** : Au moins 2 developpeurs avec le meme probleme

---

## Architecture des Permissions Meta (2025-2026)

### Systeme "Use Cases" (Cas d'utilisation)
Meta a remplace le choix de "type d'app" par des **cas d'utilisation** qui regroupent les permissions :

1. **"Tout gerer sur votre Page"** (Facebook Pages API)
   - `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `pages_manage_metadata`
   - Permet aussi de gerer du contenu Instagram (via `instagram_basic`, etc.)

2. **"Gerer les messages et les contenus sur Instagram"** (Instagram API)
   - `instagram_basic`, `instagram_content_publish`
   - Peut aussi inclure `instagram_manage_insights`, `instagram_manage_comments`
   - Ajoute aussi `pages_read_engagement` comme dependance !

### Hypothese du Conflit
`pages_read_engagement` apparait dans **2 cas d'utilisation** differents. Quand des permissions Instagram
sont activees/modifiees dans le cas d'utilisation Instagram, cela pourrait affecter le comportement de
`pages_read_engagement` dans le cas d'utilisation Pages.

**Chronologie du probleme :**
1. Import FB fonctionne (10:34) -> token ancien, `pages_read_engagement` OK
2. Activation `instagram_manage_insights` + `instagram_manage_comments` dans le dashboard Meta
3. Re-authentification avec scopes invalides (`instagram_business_manage_insights` -> Invalid Scopes)
4. Plusieurs re-auth avec differentes combinaisons de scopes
5. Import FB casse -> `pages_read_engagement` plus honore par l'API

### Niveaux d'Acces
- **Acces standard** : Fonctionne uniquement pour les admins/developpeurs/testeurs de l'app
- **Acces avance** : Fonctionne pour tous les utilisateurs, necessite App Review

`pages_read_engagement` en "Acces standard" devrait fonctionner pour l'admin de l'app,
mais le bug semble lier a Facebook Login for Business specifiquement.

---

## Tests API Effectues (26 Feb 2026)

### Avec le page token stocke en base
| Endpoint | Resultat |
|---|---|
| `/{page_id}/posts?fields=id,message,created_time` | 200 OK |
| `/{page_id}/posts?fields=...likes.summary(true)...` | 400 #10 |
| `/{post_id}?fields=likes.summary(true)` | 400 #10 |
| `/{post_id}/reactions?summary=total_count` | 400 #10 |
| `/{post_id}/comments?limit=1` | 400 #10 |
| `/{post_id}/insights/post_reactions_like_total?period=lifetime` | 200, data: [] |
| `/{post_id}/insights/post_impressions_unique` | 200, data: [] |
| `/{post_id}?fields=id,shares` | 200, champ `shares` omis |

### Avec un fresh token regenere a la volee
| Endpoint | Resultat |
|---|---|
| `/{page_id}/posts?fields=...likes.summary(true)...` | 400 #10 (meme erreur) |

### Avec le user token
| Endpoint | Resultat |
|---|---|
| `/{page_id}/posts?fields=...` | 400 "Page token requis" |

### Avec API v25.0
| Endpoint | Resultat |
|---|---|
| `/{page_id}/posts?fields=...likes.summary(true)...` | 400 #10 (meme erreur) |

---

## Scopes Instagram - Noms Invalides

Les permissions Instagram ont des noms specifiques qui **ne se passent PAS** dans l'URL OAuth :

| Scope tente | Resultat |
|---|---|
| `instagram_manage_insights` | "Invalid Scopes" dans l'URL OAuth |
| `instagram_business_manage_insights` | "Invalid Scopes" dans l'URL OAuth |
| `instagram_business_manage_comments` | "Invalid Scopes" dans l'URL OAuth |

**Solution** : Ces permissions sont activees au **niveau de l'app** dans le dashboard Meta,
pas dans l'URL OAuth. Elles sont automatiquement incluses si l'app a la permission
et que l'utilisateur l'accepte lors de l'OAuth.

Le `debug_token` confirme que `instagram_manage_insights` et `instagram_manage_comments`
SONT bien dans les scopes du token actuel.

---

## RESOLUTION (26 Feb 2026)

### Fix : Ajouter `pages_read_user_content` aux scopes OAuth

Le probleme a ete resolu en ajoutant `pages_read_user_content` aux scopes OAuth.
Cette permission est necessaire en complement de `pages_read_engagement` pour que
l'API honore effectivement la permission d'engagement.

**Avant** (ne fonctionnait pas) :
```
scope: pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish
```

**Apres** (fonctionne) :
```
scope: pages_show_list,pages_read_engagement,pages_read_user_content,pages_manage_posts,instagram_basic,instagram_content_publish
```

**Resultat** : `/{page_id}/posts?fields=likes.summary(true),comments.summary(true),shares` retourne maintenant Status 200 avec les donnees d'engagement.

### Fix Instagram : Metric `views` remplace `impressions`/`plays`

A partir de v22.0+, les metrics `impressions` (FEED) et `plays` (REELS) sont deprecies.
Le metric unifie `views` les remplace pour tous les types de media.

**Avant** (ne fonctionnait plus) :
```php
$metric = $isReel ? 'plays,reach,...' : 'impressions,reach,...';
```

**Apres** (fonctionne) :
```php
$metric = 'views,reach,likes,comments,shares,saved';
```

### Resultats confirmes
- **Facebook** : 50 posts importes avec likes/comments/shares
- **Instagram** : 50 posts importes avec views/likes/comments/shares (insights complets)
- Reel exemple : 6665 views, 497 likes, 37 comments, 41 shares

---

## Solutions Precedemment Envisagees

### 1. Supprimer le cas d'utilisation Instagram qui fait doublon (NON NECESSAIRE)
Le fix `pages_read_user_content` a resolu le probleme sans avoir a modifier les cas d'utilisation.

### 2. ~~Ajouter `pages_read_user_content` aux scopes OAuth~~ -> **APPLIQUE ET FONCTIONNE**

### 3. Demander "Page Public Content Access" (POUR LA PRODUCTION)
La feature "Page Public Content Access" est une alternative a `pages_read_engagement`.
Necessite App Review (1-2 mois de delai). A considerer pour la production.

### 4. Accepter l'import sans engagement (GARDE EN FALLBACK)
Le code conserve un fallback : si l'appel avec engagement echoue, il retente sans.
Utile en cas de regression Meta.

---

## Etat Actuel du Code

### Scopes OAuth (`FacebookOAuthController::redirect()`)
```php
'scope' => 'pages_show_list,pages_read_engagement,pages_read_user_content,pages_manage_posts,instagram_basic,instagram_content_publish'
```

### FacebookImportService
- Essaie d'abord avec `likes.summary(true),comments.summary(true),shares`
- Fallback sur champs basiques si erreur #10
- Posts importes avec engagement reel (confirm 26 Feb 2026)

### InstagramImportService
- Recupere les champs basiques : `like_count`, `comments_count`
- `fetchMediaInsights()` avec metric `views,reach,likes,comments,shares,saved`
- Insights fonctionnels (views, shares confirmes 26 Feb 2026)

### Cooldowns
- Desactives pour la preprod (TODO: reactiver pour la production)

---

## Prochaines Etapes

1. [x] ~~Tester `pages_read_user_content`~~ -> RESOLU
2. [x] ~~Instagram insights deprecated metrics~~ -> RESOLU (views remplace impressions/plays)
3. [ ] **Considerer App Review** : Pour la production, soumettre les permissions a l'App Review pour "Acces avance"
4. [ ] **Reactiver les cooldowns** pour la production
