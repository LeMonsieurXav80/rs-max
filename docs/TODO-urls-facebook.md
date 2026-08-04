# À FAIRE — `PostUrlBuilder` fabrique des URLs Facebook invalides

**Statut** : reporté le 3 août 2026. Découvert en branchant l'extension Chrome
(RS-Max Companion), non corrigé.

**Portée** : plus large que l'API. La même fonction alimente `platform_url` stocké
à la publication — tous les liens de publication Facebook de rs-max sont
potentiellement faux depuis l'origine.

---

## Le problème

[`app/Services/PostUrlBuilder.php`](../app/Services/PostUrlBuilder.php) construit
l'URL Facebook ainsi :

```php
'facebook' => "https://www.facebook.com/{$externalId}",
```

Or les `external_id` en base ont **deux formes distinctes** :

| Forme | Exemple | Origine |
|---|---|---|
| `{page_id}_{post_id}` | `100211409661498_1039073149088895` | majorité — `$body['post_id']` |
| id nu | `1066306682551042` | posts photo — `$body['id']` |
| id nu | — | reels — `$videoId` |

Voir [`FacebookAdapter::publish()`](../app/Services/Adapters/FacebookAdapter.php)
(`'external_id' => (string) ($body['post_id'] ?? $body['id'])`, et `$videoId`
pour les reels).

## Ce qui est prouvé

Test différentiel, **même état d'authentification** (client non connecté) :

```
https://www.facebook.com/1066306682551042              → 400
https://www.facebook.com/photo/?fbid=1066306682551042  → 200
```

Deux requêtes identiques en tout sauf la forme de l'URL, deux résultats
différents : la forme construite est bien **invalide pour les publications photo**.

## Ce qui n'est PAS prouvé

Pour la forme avec underscore, les trois variantes testées renvoient toutes 400 :

```
https://www.facebook.com/100211409661498_1039073149088895
https://www.facebook.com/100211409661498/posts/1039073149088895
https://www.facebook.com/permalink.php?story_fbid=1039073149088895&id=100211409661498
```

Aucun différentiel → **non concluant**. Un 400 uniforme ressemble davantage à de
l'anti-robot qu'à une URL invalide, d'autant que `/{page}/posts/{id}` est une
forme canonique connue.

**À vérifier dans un navigateur connecté** — 10 secondes :

```
https://www.facebook.com/100211409661498_1039073149088895
```

- Tombe sur la bonne publication → seul le cas des photos est à corriger
- Erreur → tout est à revoir, le correctif ci-dessous devient prioritaire

## Le correctif propre (à moitié déjà présent)

**Ne plus fabriquer l'URL : la demander à l'API Graph.**

- [`FacebookImportService`](../app/Services/Import/FacebookImportService.php) demande
  déjà `permalink_url` dans ses champs (ligne ~47) — le savoir-faire est là
- La colonne `platform_url` **existe déjà** sur `post_platforms` et est renseignée
  par [`PublishToPlatformJob`](../app/Jobs/PublishToPlatformJob.php) (~ligne 88)

Il reste à :

1. Faire remonter `permalink_url` par `FacebookAdapter::publish()` (le demander dans
   la réponse Graph, ou un `GET /{id}?fields=permalink_url` juste après)
2. Le stocker dans `platform_url` au lieu du résultat de `PostUrlBuilder`
3. Exposer `platform_url` dans l'API plutôt que de reconstruire — voir le champ
   `accounts[].url` ajouté dans `PostApiController::formatPost()`
4. **Rattraper les lignes existantes** : commande de backfill qui interroge Graph
   pour chaque `post_platforms` Facebook publié
5. Vérifier aussi `ThreadPublishingService` (~lignes 137, 255, 603), qui appelle le
   même constructeur

Les autres plateformes de `PostUrlBuilder` (Twitter, Threads, Bluesky, Instagram,
LinkedIn, Reddit) n'ont pas été auditées.

## Pourquoi ça compte maintenant

L'extension Chrome rediffuse une publication existante dans les groupes Facebook en
partageant **le lien de la publication d'origine** plutôt qu'en dupliquant le texte
— l'engagement reste ainsi sur la publication initiale. Ce mode est le mode par
défaut, et il dépend entièrement de la justesse de cette URL.

Contournement en attendant : dans la recette « Rediffuser dans ce groupe »,
choisir **« Le texte seul »**.
