# API RS-Max — Documentation complète

API REST d'orchestration multi-plateformes : publication, planification, génération IA, stats.

> **Fabrication d'images et de carrousels** (templates HTML/CSS, briques, thème,
> polices) : voir le document dédié [`carousel-api.md`](carousel-api.md).
> Il partage la base URL, l'authentification et les conventions décrites ici.

---

## Table des matières

1. [Base URL et authentification](#1-base-url-et-authentification)
2. [Conventions](#2-conventions)
3. [Comprendre les 3 modes de production de contenu](#3-comprendre-les-3-modes-de-production-de-contenu)
4. [Le persona : quand il s'applique, quand il s'applique pas](#4-le-persona--quand-il-sapplique-quand-il-sapplique-pas)
5. [Les traductions](#5-les-traductions)
6. [Endpoints — Utilisateur et comptes](#6-endpoints--utilisateur-et-comptes)
7. [Endpoints — Posts (contenu simple)](#7-endpoints--posts-contenu-simple)
8. [Endpoints — Threads (multi-segments)](#8-endpoints--threads-multi-segments)
9. [Endpoints — Génération IA (preview, ne persiste pas)](#9-endpoints--génération-ia-preview-ne-persiste-pas)
10. [Endpoints — Génération IA + planification (bulk, persiste)](#10-endpoints--génération-ia--planification-bulk-persiste)
11. [Endpoints — Personas](#11-endpoints--personas)
12. [Endpoints — Partenaires (marques)](#12-endpoints--partenaires-marques)
13. [Endpoints — Statistiques et calendrier](#13-endpoints--statistiques-et-calendrier)
14. [Annexes : plateformes, langues, statuts](#14-annexes--plateformes-langues-statuts)
15. [Endpoints — Extension Chrome (RS-Max Companion)](#15-endpoints--extension-chrome-rs-max-companion)
16. [Endpoints en session web (PAS accessibles par token)](#16-endpoints-en-session-web-pas-accessibles-par-token)
17. [Endpoints — Meta Ads (pilotage des campagnes)](#17-endpoints--meta-ads-pilotage-des-campagnes)

---

## 1. Base URL et authentification

**Base URL** : `https://<votre-domaine>/api`

**Authentification** : Laravel Sanctum — token Bearer dans le header.

```
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

### Générer un token

Il n'y a pas d'endpoint de login public pour l'API : les tokens se génèrent en ligne de commande depuis le serveur.

```bash
php artisan api:token --user=dixsupps@gmail.com --name=zapier
# ou en mode interactif :
php artisan api:token
```

Options :
- `--user=<id|email>` — utilisateur cible
- `--name=<string>` — libellé du token (défaut : `claude-code`)
- `--revoke` — révoque tous les tokens de l'utilisateur

Le token en clair n'est affiché qu'**une seule fois**. Copiez-le immédiatement.

### Permissions

- **Admin** : accède à tous les posts, threads et comptes.
- **Manager** : droits de config (personas, sources, bot, settings).
- **User** : ne voit que ses propres posts/threads et les comptes sociaux qui lui sont rattachés.

Toutes les routes sont sous le middleware `auth:sanctum` — un token est obligatoire.

---

## 2. Conventions

### Format

- Requêtes et réponses : **JSON uniquement**.
- Dates : **ISO 8601** en sortie (`2026-04-23T14:30:00+00:00`), `YYYY-MM-DD` ou ISO 8601 en entrée.
- Identifiants : entiers auto-incrémentés.

### Pagination

Les endpoints de listage (`GET /posts`, `GET /threads`) renvoient :

```json
{
  "posts": [ ... ],
  "pagination": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 25,
    "total": 67
  }
}
```

Paramètres : `?page=2&per_page=50` (max 100).

### Codes HTTP

| Code | Signification |
|------|---------------|
| `200` | OK |
| `201` | Ressource créée |
| `403` | Accès refusé (compte non lié à l'utilisateur, permission manquante) |
| `404` | Ressource introuvable |
| `422` | Erreur de validation ou état incompatible (ex : éditer un post déjà publié) |
| `500` | Erreur serveur ou échec de la génération IA |

### Format d'erreur

```json
{
  "error": "Message d'erreur en français."
}
```

Les erreurs de validation Laravel renvoient en plus un objet `errors` :

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "content_fr": ["Le champ content fr est requis."]
  }
}
```

---

## 3. Comprendre les 3 modes de production de contenu

C'est le point clé de l'API. **Trois endpoints** peuvent servir à produire du contenu, et ils ne font **pas** la même chose. Voici le tableau récapitulatif :

| Endpoint | Qui rédige le texte ? | Consomme des crédits IA ? | Crée un post en DB ? | Persona appliqué ? |
|----------|----------------------|---------------------------|----------------------|---------------------|
| `POST /api/posts` | **Vous** (texte fourni dans le body) | Non | **Oui** — `source_type: manual` | **Non** — publié tel quel |
| `POST /api/generate` | **L'IA** | **Oui** (1 appel minimum) | **Non** — retourne seulement le texte généré | **Oui** — injecté dans le prompt OpenAI |
| `POST /api/bulk-schedule` | **L'IA** | **Oui** (N appels, un par post) | **Oui** — N posts planifiés | **Oui** — injecté dans le prompt OpenAI |

### Workflows typiques

**A. Vous avez déjà votre contenu (rédaction manuelle, autre outil, autre IA)**
→ `POST /api/posts` directement avec `content_fr`. Votre texte est publié mot pour mot. Aucun appel IA côté RS-Max, aucune réécriture par persona.

**B. Vous voulez tester un prompt avant de publier (preview)**
→ `POST /api/generate` avec vos instructions et un `persona_id` → vous recevez le texte dans la réponse → vous l'inspectez → si ça vous va, vous le renvoyez via `POST /api/posts` pour créer le post. Rien n'est persisté tant que vous n'avez pas fait ce second appel.

**C. Vous voulez générer et planifier automatiquement un calendrier complet**
→ `POST /api/bulk-schedule` avec `count: 30`, une période, une plage horaire, et des instructions. L'IA génère 30 posts différents, les traduit dans les langues du compte, et les planifie en une seule requête.

### Ce que veut dire « preview »

Dans `POST /api/generate` (et `POST /api/generate-thread`), **« preview »** signifie :
- Le texte est généré par l'IA et renvoyé dans la réponse JSON.
- **Aucun post n'est créé en base de données.** Rien n'est planifié, rien n'est publié.
- Si vous ne rappelez pas l'API pour créer le post, le résultat est perdu.

C'est l'équivalent de l'aperçu dans un éditeur de contenu : vous voyez ce que l'IA produit, vous décidez ensuite quoi en faire. Le coût IA est bien consommé, mais il n'y a aucun effet de bord persistant.

---

## 4. Le persona : quand il s'applique, quand il s'applique pas

Un **persona** est un `system_prompt` OpenAI qui définit le ton, le style et la voix d'une marque. Il est stocké dans la table `personas` et optionnellement rattaché à un compte social (`social_accounts.persona_id`).

### Il est appliqué UNIQUEMENT dans les endpoints de génération IA

- `POST /api/generate` (requis)
- `POST /api/generate-thread` (requis)
- `POST /api/bulk-schedule` (requis)
- `POST /api/bulk-schedule-threads` (requis)

Résolution du persona dans ces endpoints :
1. Si `persona_id` est fourni dans le body → utilise celui-là.
2. Sinon, si un `account_id` est fourni et que le compte a un persona rattaché → utilise celui du compte.
3. Sinon → **erreur 422** : « Persona requise ».

### Il n'est JAMAIS appliqué dans ces cas

- **À la création d'un post via `POST /api/posts`** : le `content_fr` que vous envoyez est stocké tel quel.
- **À la publication via `POST /api/posts/{id}/publish`** : le contenu en DB est envoyé aux plateformes sans passer par l'IA.
- **À la lecture via `GET /api/posts/{id}`** : le contenu retourné est celui stocké en DB, jamais réécrit.
- **À la traduction automatique** : le prompt de traduction est neutre (`"You are a professional translator"`), sans style persona.

**Conséquence pratique** : si vous avez déjà généré le texte avec une IA externe ayant son propre persona, envoyez-le via `POST /api/posts` — il ne sera jamais ré-interprété par le persona de RS-Max.

---

## 5. Les traductions

RS-Max utilise **OpenAI** pour les traductions (pas DeepL ni Google Translate). Le modèle est configurable via `Settings.ai_model_translation` (défaut : `gpt-4o-mini`, température `0.3`). Le system prompt est neutre : *« You are a professional translator. Only output the translation. »* — **aucun persona n'est injecté lors de la traduction**.

### Quand la traduction a-t-elle lieu ?

| Scénario | Moment de la traduction |
|----------|------------------------|
| `POST /api/posts` (statut draft ou scheduled) | **Pas de traduction immédiate.** Les autres langues seront traduites à la publication si nécessaire. |
| `POST /api/bulk-schedule` | **Pré-traduites immédiatement** dans toutes les langues des comptes cibles, stockées dans `posts.translations`. |
| `POST /api/generate` avec `account_id` | Traduites et renvoyées dans la réponse (`translations`), **mais non persistées** tant que vous ne créez pas le post. |
| À la publication d'un post vers un compte multilingue | Si la traduction n'est pas déjà en cache (`translations`), elle est générée à la volée et mise en cache pour les prochaines publications. |

### Structure des traductions en base

Sur un post, deux colonnes existent :

- `content_fr` : texte source français (source de vérité).
- `content_en` : colonne legacy, rétro-compatibilité uniquement — préférez `translations`.
- `translations` : JSON `{ "en": "...", "es": "...", "pt": "..." }`.
- `platform_contents` : JSON `{ "twitter": "version courte", "facebook": "version longue" }` pour surcharger le texte selon la plateforme.

Lorsqu'un compte a plusieurs langues (ex : `["fr", "en"]`), le texte final concaténé est affiché avec un drapeau 🇫🇷 / 🇬🇧 en séparateur.

---

## 6. Endpoints — Utilisateur et comptes

### `GET /api/me`

Retourne les infos de l'utilisateur courant.

**Réponse**
```json
{
  "id": 1,
  "name": "Xavier",
  "email": "dixsupps@gmail.com",
  "role": "admin"
}
```

### `GET /api/accounts`

Liste les comptes sociaux actifs liés à l'utilisateur courant (admin = tous).

**Réponse**
```json
{
  "accounts": [
    {
      "id": 3,
      "name": "Mon compte Twitter",
      "platform": "twitter",
      "platform_name": "Twitter/X",
      "platform_account_id": "1234567890",
      "persona": { "id": 1, "name": "Tech Writer" },
      "languages": ["fr", "en"],
      "followers_count": 1250
    }
  ]
}
```

`platform_account_id` est l'identifiant côté plateforme (Facebook = `page_id`,
Instagram = `account_id`, Telegram = `chat_id`). L'extension Chrome
([§15](#15-endpoints--extension-chrome-rs-max-companion)) s'en sert pour rattacher
toute seule la page consultée au bon compte, sans choix manuel.

---

## 7. Endpoints — Posts (contenu simple)

Un **post** est un contenu unique publié sur un ou plusieurs comptes sociaux.

### `GET /api/posts`

Liste paginée des posts de l'utilisateur.

**Query params** : `status`, `account_id`, `from`, `to`, `source_type`, `per_page`.

**Exemple** : `GET /api/posts?status=scheduled&account_id=3&from=2026-04-01&per_page=50`

**Réponse** : voir [§2 Pagination](#pagination). Chaque post contient `id`, `content_preview`, `status`, `scheduled_at`, `accounts[]`, etc.

### `GET /api/posts/{id}`

Détail d'un post, incluant `content_fr`, `content_en`, `platform_contents`, `media`, `link_url`, `accounts[]` et `logs[]`.

**Important** : le `content_fr` retourné est celui stocké en DB, tel que vous l'avez envoyé. Jamais réécrit par l'IA ou le persona.

### `POST /api/posts`

Crée un post **avec contenu fourni par vous** (pas d'IA).

**Body**
```json
{
  "content_fr": "Texte du post en français",
  "content_en": null,
  "platform_contents": {
    "twitter": "Version courte pour Twitter",
    "facebook": "Version longue pour Facebook"
  },
  "hashtags": "#seo #marketing",
  "media": [{"type": "image", "url": "https://..."}],
  "link_url": "https://exemple.com/article",
  "status": "scheduled",
  "scheduled_at": "2026-05-01T14:00:00+02:00",
  "accounts": [3, 5]
}
```

**Champs**
- `content_fr` *(requis)* — texte source, max 10000 caractères.
- `platform_contents` *(optionnel)* — surcharge par plateforme.
- `status` *(requis)* — `draft` ou `scheduled`.
- `scheduled_at` — requis si `status=scheduled`, doit être dans le futur.
- `accounts` *(requis)* — IDs des comptes sociaux cibles.

**Réponse** : `201 Created` + objet post formatté. `source_type` = `manual`.

### `PUT /api/posts/{id}`

Modifie un post. **Seuls les posts `draft` ou `scheduled` sont modifiables** (erreur 422 sinon).

Tous les champs sont optionnels (patch partiel).

### `DELETE /api/posts/{id}`

Supprime un post. Impossible si `status=publishing` (422).

### `POST /api/posts/{id}/publish`

Force la publication immédiate. Statuts autorisés : `draft`, `scheduled`, `failed`.

**Réponse**
```json
{ "success": true, "message": "Publication lancée.", "post_id": 42 }
```

La publication est asynchrone (dispatch d'un `PublishToPlatformJob` par compte cible).

### `POST /api/bulk-cancel`

Annule ou supprime tous les posts planifiés d'une période.

**Body**
```json
{
  "from": "2026-05-01",
  "to": "2026-05-31",
  "account_id": 3,
  "delete": false
}
```

- `delete: false` (défaut) → passe les posts en `draft` et efface `scheduled_at`.
- `delete: true` → suppression définitive.

---

## 8. Endpoints — Threads (multi-segments)

Un **thread** est une suite ordonnée de segments. Selon la plateforme cible, il est publié :
- **Mode thread** (Twitter, Threads, Bluesky) : un tweet par segment, chaînés.
- **Mode compiled** (Facebook, Telegram) : tous les segments concaténés en un seul post.

### `GET /api/threads`

Liste paginée des threads. Params : `status`, `account_id`, `from`, `to`, `per_page`.

### `GET /api/threads/{id}`

Détail complet avec tous les segments (`position`, `content_fr`, `platform_contents`, `media`), et le statut par plateforme.

### `POST /api/threads`

Crée un thread **avec segments fournis** (pas d'IA).

**Body**
```json
{
  "title": "Guide SEO local en 5 étapes",
  "source_url": "https://...",
  "accounts": [3, 7],
  "status": "scheduled",
  "scheduled_at": "2026-05-10T09:00:00+02:00",
  "segments": [
    {
      "content_fr": "1/ Introduction au SEO local...",
      "platform_contents": {
        "twitter": "1/ Intro SEO local 🧵"
      },
      "media": [{"type": "image", "url": "..."}]
    },
    { "content_fr": "2/ Optimiser sa fiche Google..." },
    { "content_fr": "3/ ..." }
  ]
}
```

Le `publish_mode` (thread vs compiled) est déterminé automatiquement selon la plateforme du compte.

### `PUT /api/threads/{id}`

Modifie un thread `draft` ou `scheduled`. Les segments fournis **remplacent** entièrement les anciens.

### `DELETE /api/threads/{id}`

Suppression. Interdite si `status=publishing`.

### `POST /api/threads/{id}/publish`

Force la publication. Statuts autorisés : `draft`, `scheduled`, `failed`, `partial`.

---

## 9. Endpoints — Génération IA (preview, ne persiste pas)

> ⚠️ **Rappel** : ces endpoints consomment des crédits IA et retournent le texte généré, **sans créer de post en base**. Pour persister, enchaînez avec `POST /api/posts` ou `POST /api/threads`.

### `POST /api/generate`

Génère un texte selon un prompt et un persona. Retourne le résultat sans le stocker.

**Body**
```json
{
  "instructions": "Un tweet sur les bienfaits du SEO local pour un restaurant",
  "account_id": 3,
  "persona_id": 1,
  "platforms": ["twitter", "facebook"]
}
```

**Champs**
- `instructions` *(requis)* — prompt utilisateur, max 5000 caractères.
- `account_id` *(optionnel)* — résout la persona par défaut et les langues cibles.
- `persona_id` *(optionnel)* — override du persona. **Au moins un des deux doit résoudre un persona, sinon 422.**
- `platforms` *(optionnel)* — si fourni, génère aussi des variantes adaptées par plateforme.

**Réponse**
```json
{
  "generated": {
    "content_fr": "Texte généré en français...",
    "translations": {
      "en": "Text translated to English..."
    },
    "platform_contents": {
      "twitter": "Version Twitter < 280 chars",
      "facebook": "Version Facebook plus longue"
    },
    "platform_translations": {
      "twitter_en": "Twitter version in English"
    }
  }
}
```

Le champ `content_fr` est **toujours généré en français** (quelle que soit la langue du compte), puis traduit dans les langues du compte. Cela permet une source de vérité unique.

### `POST /api/generate-thread`

Génère un thread complet (plusieurs segments).

**Body — depuis une URL source**
```json
{
  "source_url": "https://blog.exemple.com/article",
  "account_id": 3,
  "persona_id": 1,
  "platforms": ["twitter", "threads"]
}
```

**Body — depuis des instructions**
```json
{
  "instructions": "Thread en 5 tweets sur les erreurs SEO courantes",
  "account_id": 3,
  "platforms": ["twitter"]
}
```

Au moins un des deux (`source_url` ou `instructions`) est requis.

**Réponse**
```json
{
  "generated": {
    "title": "Les 5 erreurs SEO les plus fréquentes",
    "segments": [
      {
        "position": 1,
        "content_fr": "1/ ...",
        "platform_contents": { "twitter": "..." },
        "translations": { "en": "..." }
      }
    ]
  }
}
```

---

## 10. Endpoints — Génération IA + planification (bulk, persiste)

> ⚠️ Ces endpoints **créent des posts/threads planifiés** en base. Ils consomment des crédits IA proportionnellement au `count` demandé (1 appel OpenAI par post, ~500 ms de pause entre chaque pour éviter le rate limiting).

### `POST /api/bulk-schedule`

Génère `count` posts uniques et les planifie entre `start_date` et `end_date`, aux heures comprises entre `time_from` et `time_to`.

**Body**
```json
{
  "account_id": 3,
  "count": 30,
  "start_date": "2026-05-01",
  "end_date": "2026-05-31",
  "time_from": "09:00",
  "time_to": "18:00",
  "instructions": "Tweets sur le SEO local, ton décontracté et pédagogique",
  "weekdays_only": false,
  "hashtags": "#seo #local",
  "persona_id": null
}
```

**Champs**
- `account_id` *(requis)* — un seul compte cible.
- `count` *(requis)* — nombre de posts, entre 1 et 100.
- `start_date` *(requis)* — aujourd'hui ou futur.
- `end_date` *(optionnel)* — défaut : `start_date + count` jours.
- `time_from` / `time_to` *(requis, `HH:MM`)* — fenêtre horaire. `time_to` doit être postérieur à `time_from`.
- `instructions` *(requis)* — thème général, max 2000 caractères.
- `weekdays_only` *(optionnel)* — exclut samedi/dimanche.
- `hashtags` *(optionnel)* — hashtags ajoutés à chaque post.
- `persona_id` *(optionnel)* — override du persona du compte.

**Comportement interne**
- Les créneaux sont répartis uniformément sur les jours disponibles, avec une heure aléatoire dans la plage donnée.
- Le prompt est enrichi pour forcer la variété : chaque appel reçoit un numéro d'ordre et l'instruction de varier style, angle et ton.
- Chaque post généré est **pré-traduit** dans toutes les langues du compte (hors français) et stocké dans `translations`.

**Réponse**
```json
{
  "success": true,
  "account": "Mon compte Twitter",
  "platform": "twitter",
  "total_requested": 30,
  "total_created": 29,
  "total_errors": 1,
  "posts": [
    {
      "id": 142,
      "scheduled_at": "2026-05-01T10:23:17+02:00",
      "content_preview": "Saviez-vous que 46 % des recherches Google sont à..."
    }
  ],
  "errors": [
    { "index": 17, "error": "Échec génération IA" }
  ]
}
```

Code HTTP : `201` si au moins un post créé, `500` si tous ont échoué.

### `POST /api/bulk-schedule-threads`

Même principe, mais pour des **threads multi-comptes**.

**Body**
```json
{
  "account_ids": [3, 5, 7],
  "count": 10,
  "start_date": "2026-05-01",
  "end_date": "2026-05-31",
  "time_from": "10:00",
  "time_to": "16:00",
  "instructions": "Threads pédagogiques sur le SEO technique",
  "weekdays_only": true,
  "persona_id": null
}
```

**Différences avec `bulk-schedule`**
- `account_ids` : tableau (même thread publié sur plusieurs comptes).
- `count` max 50 (les threads sont plus coûteux que les posts simples).
- Le `publish_mode` est déterminé par plateforme (thread natif ou compilé).
- Pause de 800 ms entre appels IA (threads = plusieurs segments).

---

## 11. Endpoints — Personas

### `GET /api/personas`

Liste toutes les personas avec leurs détails complets (system_prompt, tone, language, is_active).

### `GET /api/personas/{id}`

Détail d'une persona.

### `POST /api/personas`

Créer une persona.

**Body**
```json
{
  "name": "Tech Writer",
  "description": "Ton professionnel, pédagogique, orienté SEO",
  "system_prompt": "Tu es un expert SEO qui rédige...",
  "tone": "professionnel",
  "language": "fr",
  "is_active": true
}
```

**Champs requis** : `name`, `system_prompt`. Max longueur `system_prompt` : 10000.

### `PUT /api/personas/{id}`

Modification (patch partiel).

### `DELETE /api/personas/{id}`

Impossible si la persona est utilisée par un ou plusieurs comptes (422 avec le décompte).

---

## 12. Endpoints — Partenaires (marques)

Un **partenaire** est une marque taguée en interne, sur les photos et sur les
publications. Sert aux comptes rendus ; **rien n'est jamais publié** sur les réseaux.

### Le tag se propage tout seul

Une photo est taguée d'un ou plusieurs partenaires (médiathèque, ou champ `brands`
de l'API média). Toute publication **et tout fil de discussion** qui utilise cette
photo hérite du tag — pour un fil, les photos de tous ses segments comptent.

Chaque tag de publication porte une **origine** :

| `source` | Sens | Cycle de vie |
|---|---|---|
| `auto` | Hérité d'une photo taguée | **Recalculé à chaque enregistrement** du post d'après les photos réellement attachées. Retirer la photo retire le tag. |
| `manual` | Posé explicitement via `partners[]` | Jamais écrasé par le recalcul. |

Un partenaire présent des deux côtés est enregistré `manual`.

> **Piège — le recalcul `auto` part du POST, jamais de la PHOTO.**
> Le tag `auto` est recalculé à l'enregistrement d'une publication. Retirer une
> marque d'une photo ne suffit donc pas : les publications qui l'utilisent gardent
> leur ligne pivot jusqu'à leur prochain enregistrement — **qui n'arrivera jamais
> pour un contenu déjà publié**. Le nettoyage serait alors invisible là où il
> compte, dans les comptes rendus.
>
> Depuis août 2026, tous les chemins qui modifient les marques d'une photo
> déclenchent le report automatiquement : édition en masse de la médiathèque,
> fiche photo, `POST /api/media/{id}/validate` et `/enrich`, classification Vision,
> et les deux routes `detach`/`attach` ci-dessous. L'écriture se fait directement
> sur le pivot, sans passer par `PUT /api/posts/{id}` — un post publié il y a six
> mois reste donc nettoyable.
>
> Pour le contenu désaligné **avant** cette date :
> `php artisan partners:resync-content [--partner=holafly] [--commit]`
> (dry-run par défaut, les tags `manual` ne sont jamais touchés).
>
> À noter : l'héritage ne passe **pas** par la filiation des images générées. Une
> slide de carrousel ne reprend pas les partenaires de ses photos sources, donc un
> fil illustré d'un carrousel n'a jamais porté leurs tags — il n'y a rien à y
> nettoyer.

### `GET /api/partners`

Liste complète (non paginée). `?active=1` pour ne garder que les partenaires actifs.

```json
{"partners": [
  {"id": 3, "name": "Nike", "slug": "nike", "color": "#f59e0b",
   "is_active": true, "origin": "manual", "media_count": 42, "posts_count": 17}
]}
```

`origin` vaut `manual` (créé à la main), `import` (repris des anciennes marques texte
ou de `/api/media/ingest`) ou `vision` (détecté par l'IA sur une photo) — les deux
derniers sont à vérifier avant d'en faire un compte rendu.

### `GET /api/partners/{id}`

Détail : ajoute `contact_name`, `contact_email`, `website`, `notes`, `created_at`.

### `POST /api/partners`

**Body** : `name` (requis, ≤ 80 car., unique), `contact_name`, `contact_email`,
`website`, `notes`, `color` (hex), `is_active`.

Réponse `201`. Le `slug` est dérivé du nom et sert de clé de dédup : « Coca-Cola »,
« coca cola » et « COCA COLA » désignent la même fiche.

### `PUT|PATCH /api/partners/{id}`

Mêmes champs, tous facultatifs. Renommer met à jour le miroir `brands` de toutes les
photos taguées.

### `DELETE /api/partners/{id}`

Supprime la fiche et retire le tag de toutes les photos et publications.

### `GET /api/partners/{id}/posts`

**Le compte rendu.** Publications taguées de ce partenaire, paginées.

**Query params** : `status` (`draft|scheduled|publishing|published|failed`),
`source` (`auto|manual`), `from`, `to` (dates), `per_page` (défaut 25, max 100).

**Exemple** : `GET /api/partners/3/posts?status=published&from=2026-01-01&to=2026-06-30`

```json
{
  "partner": {"id": 3, "name": "Nike", "slug": "nike"},
  "posts": [
    {"id": 812, "content_preview": "…", "status": "published",
     "tag_source": "auto", "media_count": 3,
     "scheduled_at": null, "published_at": "2026-03-14T09:00:00+00:00",
     "created_at": "2026-03-13T18:22:00+00:00",
     "accounts": [{"id": 5, "name": "…", "platform": "instagram",
                   "status": "published", "external_id": "…",
                   "published_at": "2026-03-14T09:00:04+00:00"}]}
  ],
  "pagination": {"current_page": 1, "last_page": 1, "per_page": 25, "total": 1}
}
```

### `GET /api/partners/{id}/threads`

Symétrique de `/posts`, pour les fils de discussion. Mêmes filtres et même pagination.
Un fil hérite des partenaires des photos de **tous** ses segments (segment de boost inclus).

### `GET /api/partners/{id}/emv`

**Valorisation des retombées** (Earned Media Value) des publications taguées.
Mêmes filtres que `/posts` : `status`, `source`, `from`, `to`.

Deux méthodes sont rendues côte à côte, elles ne mesurent pas la même chose :

- `cpm` — `(vues ÷ 1000) × CPM de référence`, soit ce qu'aurait coûté cette
  audience en publicité payante ;
- `ayzenberg` — `Σ (nombre d'actions × valeur unitaire)`, la valeur de
  l'engagement. La vue y vaut `0` par défaut, sans quoi `total` compterait
  deux fois la même audience.

Les tarifs viennent de `config/emv.php`, surchargeables depuis `/settings`
(onglet Statistiques). **Le calcul est fait à la volée, jamais persisté** :
changer un tarif recalcule tout l'historique.

**Reddit et Bluesky n'exposent aucune vue** : la méthode `cpm` y est
structurellement impossible. Elles ne sont pas comptées à zéro, elles sont
listées dans `coverage.uncovered_platforms` — lire ce bloc avant de présenter
un total à un partenaire. Les fils de discussion sont hors périmètre :
`thread_segment_platform` ne porte aucune métrique.

```json
{
  "partner": {"id": 3, "name": "Nike", "slug": "nike"},
  "emv": {
    "currency": "EUR",
    "cpm": 425.5,
    "ayzenberg": 88.2,
    "total": 513.7,
    "by_platform": [
      {"slug": "instagram", "items": 12, "views": 50000,
       "cpm": 425.5, "ayzenberg": 74.2, "views_available": true},
      {"slug": "bluesky", "items": 3, "views": 0,
       "cpm": 0, "ayzenberg": 14.0, "views_available": false}
    ],
    "coverage": {"items": 15, "valued": 12, "measurable": 12,
                 "uncovered_platforms": ["bluesky"]}
  }
}
```

`GET /api/stats/overview` porte le même bloc `emv` pour l'ensemble des comptes
de l'utilisateur.

### Taguer rétroactivement une publication déjà publiée

`PUT /api/posts/{id}` et `PUT /api/threads/{id}` **refusent** tout contenu déjà publié
(`422`). Le tag partenaire, lui, est une métadonnée interne de reporting, pas du
contenu : il dispose donc de sa propre route, acceptée **quel que soit le statut**.

```
PUT /api/posts/{id}/partners
PUT /api/threads/{id}/partners
```

**Body** : `{"partners": [3, 7]}` — le champ est **requis** (`present`). Une liste vide
efface les tags manuels. Les tags `auto` sont recalculés depuis les photos dans tous
les cas et ne se pilotent pas d'ici.

```json
{"success": true, "post_id": 812,
 "partners": [{"id": 3, "name": "Nike", "slug": "nike", "source": "auto"},
              {"id": 7, "name": "Decathlon", "slug": "decathlon", "source": "manual"}]}
```

Côté web, le même geste se fait depuis la fiche du post ou du fil (bouton « Modifier »
du bloc Partenaires), y compris sur un contenu publié.

### `POST /api/partners/{partner}/media/detach`
### `POST /api/partners/{partner}/media/attach`

Retire (ou pose) un partenaire sur **un lot de photos** en une requête, et reporte
l'effet sur les publications qui les utilisent. Ne touche que des pivots : la fiche
partenaire n'est **jamais** supprimée — pour ça, `DELETE /api/partners/{id}`.

Ici, et seulement ici, `{partner}` accepte l'**id numérique ou le slug**
(`/api/partners/holafly/media/detach`). Les autres routes partenaires restent en id.

**Sélection** — par ids explicites **ou** par filtres, jamais les deux (422 sinon) :

```json
{ "media_ids": [586, 519, 514], "dry_run": true }
```

```json
{ "filters": { "folder": "pdc", "city": "Essaouira" }, "dry_run": true }
```

Les filtres sont aussi acceptés à plat (`{"folder": "pdc", "city": "Essaouira"}`).

| Filtre | Comportement |
|---|---|
| `folder` | Slug. Descente récursive **tant que la chaîne reste publique**, comme `/api/media/search`. |
| `city`, `region`, `country` | Match exact, insensible à la casse. |
| `event` | Idem. |
| `taken_at_from`, `taken_at_to` | Bornes incluses. Une photo sans `taken_at` n'est jamais retenue dès qu'une borne est posée. |

Ce sont exactement les filtres de `/api/media/search`, même implémentation
(`MediaSelectionFilter`) : un lot se désigne de la même façon des deux côtés.

**`dry_run` vaut `true` par défaut** — l'écriture se demande explicitement. Le
dry-run exécute réellement l'opération puis la rembobine : les compteurs annoncés
sont donc ceux du run réel, pas une estimation.

**Réponse** :

```json
{
  "partner": {"id": 1, "name": "Holafly", "slug": "holafly"},
  "action": "detach",
  "dry_run": true,
  "media_matched": 180,
  "media_detached": 174,
  "media_skipped_private": 6,
  "posts_recalculated": 4,
  "threads_recalculated": 8,
  "posts_now_untagged": [812, 831],
  "threads_now_untagged": [88, 92, 94, 96, 98]
}
```

- `media_matched` : photos retenues par la sélection.
- `media_detached` / `media_attached` : celles réellement modifiées — au détachement
  celles qui portaient le tag, à l'attachement celles qui ne l'avaient pas. C'est ce
  qui rend l'opération **idempotente** : rejouer la même requête ne gonfle rien.
- `media_skipped_private` : photos dans un dossier privé (ou sous un ancêtre privé),
  jamais touchées — **y compris quand elles sont désignées par leur id**. Un skip
  compté plutôt qu'un 403 global, pour qu'une photo mal rangée ne fasse pas échouer
  tout le lot.
- `*_now_untagged` : contenus qui, après recalcul, ne portent plus du tout ce
  partenaire. Vide en dry-run après rembobinage.

Le niveau d'intimité (`intimacy_level`) n'entre pas en jeu : une photo semi-privée
est détachée normalement. Le tag est une métadonnée interne et la route ne renvoie
aucune image — la sauter laisserait du sur-taguage hors de portée de l'API.

### Taguer depuis les autres endpoints

| Endpoint | Champ | Comportement |
|---|---|---|
| `POST /api/posts`, `POST /api/threads` | `partners[]` (ids) | Tags `manual`, en plus des `auto` hérités des photos. |
| `PUT /api/posts/{id}`, `PUT /api/threads/{id}` | `partners[]` (ids) | **Absent** = les tags manuels existants sont conservés. **Présent** = remplace la liste manuelle (`[]` les efface). Les `auto` sont recalculés dans tous les cas. Refusé si le contenu est publié — utiliser alors `/partners` (ci-dessus). |
| `GET /api/posts` | `?partner=3` ou `?partner=nike` | Filtre les publications taguées. |
| `GET /api/posts`, `GET /api/posts/{id}` | — | Chaque post expose `partners[]` avec `id`, `name`, `slug`, `source`. |
| `GET /api/threads`, `GET /api/threads/{id}` | — | Idem pour les fils. |
| `POST /api/media/ingest`, `POST /api/media/{id}/validate`, `POST /api/media/{id}/enrich` | `brands[]` (noms) | Les noms sont résolus en fiches partenaires, créées à la volée si inconnues. |
| `GET /api/media/{id}` | — | Expose `partners[]` (`id`, `name`, `slug`) à côté de `brands[]`. |
| `GET /api/media/search` | `?partners[]=nike&partners[]=3` | ET logique : la photo doit porter tous les partenaires demandés. |

---

## 13. Endpoints — Statistiques et calendrier

Tous ces endpoints acceptent `?period=<jours>` (défaut 30, `all` possible) et `?accounts[]=3&accounts[]=5` pour filtrer.

### `GET /api/stats/overview`

KPIs globaux : décomptes par statut (posts et threads), engagement agrégé, followers totaux.

**Réponse**
```json
{
  "posts": { "scheduled": 12, "published": 84, "failed": 2, "draft": 3 },
  "threads": { "scheduled": 4, "published": 18 },
  "engagement": {
    "posts_count": 84,
    "total_views": 125430,
    "total_likes": 4210,
    "total_comments": 188,
    "total_shares": 92,
    "total_engagement": 4490,
    "engagement_rate": 3.58,
    "avg_views_per_post": 1493,
    "avg_likes_per_post": 50
  },
  "followers": { "total": 24500, "accounts_count": 7 }
}
```

### `GET /api/stats/audience`

Évolution des followers par compte sur une période. Inclut les deltas (+7j, +14j, +28j) et l'historique quotidien.

**Query** : `?period=90&accounts[]=3`

### `GET /api/stats/top-posts`

Top des posts par engagement. `?period=30&limit=10&accounts[]=3`. Max `limit=50`.

Inclut les posts RS-Max **et** les `ExternalPost` (posts non publiés via RS-Max mais importés via les webhooks de stats) déduplés par `platform_id + external_id`.

### `GET /api/stats/platforms`

Agrégats par plateforme et par compte.

**Réponse**
```json
{
  "by_platform": [
    { "platform": "twitter", "name": "Twitter/X", "count": 42, "views": 81000, "likes": 2100, "comments": 95, "shares": 48 }
  ],
  "by_account": [
    { "account_id": 3, "account_name": "...", "platform": "twitter", "count": 42, "views": 81000, ... }
  ]
}
```

### `GET /api/calendar`

Vue calendrier d'un mois donné. Regroupe posts et threads par date.

**Query** : `?month=2026-04` (défaut : mois courant).

**Réponse**
```json
{
  "month": "2026-04",
  "calendar": {
    "2026-04-12": [
      {
        "type": "post",
        "id": 142,
        "content_preview": "...",
        "status": "scheduled",
        "time": "14:30",
        "accounts": [{ "name": "...", "platform": "twitter" }]
      },
      {
        "type": "thread",
        "id": 88,
        "title": "Guide SEO local",
        "segments_count": 5,
        "status": "published",
        "time": "09:00",
        "accounts": [...]
      }
    ]
  }
}
```

---

## 14. Annexes : plateformes, langues, statuts

### Plateformes supportées

`facebook`, `instagram`, `threads`, `twitter`, `telegram`, `youtube`, `bluesky`, `reddit`, `linkedin`, `pinterest`.

**Mode de publication des threads** :
- Thread natif : `twitter`, `threads`, `bluesky`.
- Compilé (segments concaténés) : `facebook`, `telegram`, `linkedin`, etc.

### Langues supportées (avec drapeau concaténé)

`fr` 🇫🇷, `en` 🇬🇧, `pt` 🇵🇹, `es` 🇪🇸, `de` 🇩🇪, `it` 🇮🇹.

D'autres codes sont acceptés (tout ce qu'OpenAI peut traduire), mais seuls ceux ci-dessus reçoivent un préfixe drapeau en publication multilingue.

### Statuts d'un post

| Statut | Signification |
|--------|---------------|
| `draft` | Brouillon, non planifié |
| `scheduled` | Planifié, en attente du cron |
| `publishing` | Job de publication en cours |
| `published` | Publié sur au moins une plateforme |
| `failed` | Échec sur toutes les plateformes |
| `partial` | Publié sur certaines plateformes, échec sur d'autres (threads uniquement) |

### Statuts d'un `PostPlatform` / `ThreadSegmentPlatform`

`pending`, `publishing`, `published`, `failed`.

### Source types

- `manual` — créé via UI ou `POST /api/posts`.
- `bulk_generated` — créé via `POST /api/bulk-schedule` (les posts gardent cependant `source_type: manual` actuellement).
- `rss`, `wordpress`, `youtube`, `reddit` — générés automatiquement depuis une source de contenu.

### Limites

- Text content : 10 000 caractères max.
- Instructions IA : 2 000 (bulk) / 5 000 (generate).
- `bulk-schedule` : 100 posts max par requête.
- `bulk-schedule-threads` : 50 threads max par requête.
- Pagination : 100 items max par page.

---

## 15. Endpoints — Extension Chrome (RS-Max Companion)

L'extension Chrome (`DEV/Chrome/rs-max-companion`) effectue dans le navigateur de
l'utilisateur les actions que les API officielles interdisent (inviter à aimer une
Page, remplir un composer natif…), puis les remonte ici pour qu'elles comptent
dans les statistiques.

Les actions atterrissent dans **`bot_action_logs`** avec `source = 'extension'` —
la même table que le bot de prospection (`source = 'bot'`).

### `POST /api/extension/actions`

Remontée en lot. L'extension bufferise puis pousse ; si l'API est injoignable,
elle conserve localement et réessaie.

**Corps**
```json
{
  "actions": [
    {
      "social_account_id": 3,
      "action_type": "fb_invite_to_like",
      "target_uri": "https://facebook.com/…",
      "target_author": "Jean Dupont",
      "target_text": null,
      "success": true,
      "error": null,
      "metadata": {"post_id": 42},
      "performed_at": "2026-08-03T10:00:00Z"
    }
  ]
}
```

- `actions` : 200 max par requête. Throttle : 120 req/min.
- `performed_at` : horodatage **réel** de l'action côté navigateur. La remontée
  pouvant être différée, `created_at` ne suffit pas.
- `success: false` sert à tracer les actions **non confirmées** (le réseau a
  limité le débit). C'est délibéré : compter les clics au lieu des effets donne
  des statistiques fausses.

**Réponse `201`**
```json
{ "stored": 12, "rejected": 0 }
```

`rejected` compte les actions dont le `social_account_id` n'est pas rattaché à
l'utilisateur du token — elles sont ignorées, jamais enregistrées. Le
`social_account_id` venant du navigateur n'est **jamais** cru sur parole.

### `GET /api/extension/summary`

Compte rendu agrégé des actions de l'extension.

**Query params** : `days` (défaut 30, max 365), `account_id`.

**Réponse**
```json
{
  "days": 30,
  "total": 214,
  "actions": [
    { "action_type": "fb_invite_to_like", "success": 198, "failed": 14 }
  ]
}
```

---

## 16. Endpoints en session web (PAS accessibles par token)

Quatre endpoints commencent par `/api/` alors qu'ils sont déclarés dans
`routes/web.php` : ils tournent sous le middleware `web` (cookie de session +
CSRF) et **ne répondent pas à un token Bearer**. Vérifié avec un vrai token :
`401` avec `Accept: application/json`, redirection `302` vers `/login` sans cet
en-tête — alors que le même token répond `200` sur `/api/me`.

Ce sont des endpoints AJAX internes à l'interface. Ils sont listés ici pour
qu'on cesse de les confondre avec l'API à token.

| Endpoint | Rôle requis | Ce qu'il fait |
|---|---|---|
| `GET /api/hashtags` | authentifié | 20 hashtags les plus utilisés **par l'utilisateur connecté**. Renvoie un tableau de chaînes. |
| `GET /api/locations/search?q=` | authentifié | Autocomplete de lieux Facebook (Graph v21.0 `/pages/search`, 10 max, pages ayant une `location`). `q` requis, min 2. |
| `GET /api/source-items/sources?type=` | **manager** | Sources de contenu actives d'un type (`rss`, `wordpress`, `youtube`, `reddit`). |
| `GET /api/source-items/items?type=&source_id=` | **manager** | Éléments d'une source (50 max, tri `published_at` desc). `search` filtre le titre uniquement. |

> ⚠️ `GET /api/locations/search` **échoue en silence** : sans token Facebook
> disponible, ou si Graph renvoie une erreur, il répond `200` avec un tableau
> vide. Un résultat vide ne distingue donc pas « aucun lieu » de « token
> expiré » — vérifier les logs `LocationController:` avant de conclure.

Documentation détaillée (réponses complètes, champ `extra`, pièges) : note
`RS-Max API` du vault Obsidian, section 20.

---

## 17. Endpoints — Meta Ads (pilotage des campagnes)

Lecture et pilotage du compte publicitaire Meta, **conçus pour être appelés par une IA**.
Configuration du jeton : `docs/meta-ads-cpm-constate.md`.

> **Ces endpoints dépensent de l'argent réel.** La lecture est ouverte ; l'écriture est
> fermée par défaut et en simulation par défaut. Les garde-fous ci-dessous ne sont pas
> du confort : ils sont la dernière barrière entre une hallucination et une facture.

### Permissions du jeton

| Usage | Scope requis |
|---|---|
| Lecture seule (campagnes, insights, CPM constaté) | `ads_read` |
| Pilotage (pause, budget) | **`ads_management`** |

Un jeton généré avec `ads_read` seul renverra une erreur de permission sur toute
écriture — il faut le régénérer, on n'élargit pas un jeton existant.

### Lecture

```
GET /api/meta-ads/campaigns?active=1
GET /api/meta-ads/campaigns/{id}?days=30
GET /api/meta-ads/insights?level=campaign&days=30&object_id=…
GET /api/meta-ads/logs?limit=50
```

`GET /campaigns/{id}` renvoie la campagne, ses ad sets et ses performances en un
seul appel — de quoi décider sans enchaîner les requêtes.

**Les budgets sont exprimés dans la devise du compte** (`25.5`), jamais en centimes.
La conversion depuis les unités mineures de Meta est faite une seule fois, dans
`MetaAdsService`.

`effective_status` prime sur `status` : une campagne `ACTIVE` dont le compte a
atteint son plafond ne diffuse pas, et seul le statut effectif le dit.

`GET /logs` rend le journal des gestes déjà passés. **À lire avant de proposer une
action** : c'est ce qui évite de reproposer en boucle une modification déjà tentée.

### Écriture

```
POST /api/meta-ads/{id}/status    {"status": "PAUSED", "dry_run": false}
POST /api/meta-ads/{id}/budget    {"amount": 25.50, "dry_run": false}
```

| Paramètre | Défaut | Rôle |
|---|---|---|
| `dry_run` | **`true`** | Renvoie le plan sans rien envoyer à Meta |
| `force` | `false` | Autorise une hausse de budget au-delà du plafond relatif |
| `lifetime` | `false` | Vise `lifetime_budget` au lieu de `daily_budget` |
| `object_type` | `campaign` | `campaign` ou `adset`, pour le journal |

`status` accepte `ACTIVE` et `PAUSED` uniquement. `DELETED` et `ARCHIVED` sont
volontairement absents : gestes destructifs, ils se font dans le Gestionnaire de
publicités, sous les yeux d'un humain. **Créer ou supprimer une campagne n'est pas
exposé** — une campagne qui se crée toute seule dépense sans qu'un humain ait vu le
ciblage ni le créatif.

### Les cinq garde-fous

1. **Interrupteur général** — `META_ADS_WRITE_ENABLED`, à `false` par défaut. Toute
   écriture répond `403`, `dry_run: false` compris.
2. **Manager requis** — un compte `user` reçoit `403`.
3. **`dry_run` à `true` par défaut** — sans le passer explicitement à `false`, on
   obtient le plan (`previous` → `requested`), pas l'exécution.
4. **Plafond de hausse** — au-delà de `max_budget_increase_pct` (50 %) en une fois :
   `422`. Contournable par `force: true`. Attrape l'erreur d'unité et le raisonnement
   qui dérape.
5. **Plafond absolu** — `max_daily_budget` (100) n'est **jamais** contournable, `force`
   compris. Le relever se fait dans `config/meta_ads.php`.

Tout est journalisé dans `meta_ads_action_logs`, **dry-run compris**, avec l'état
précédent — sans lui, un retour arrière se ferait à l'aveugle.

### Réponse type (simulation)

```json
{
  "dry_run": true,
  "applied": false,
  "object_id": "120000",
  "object_name": "Campagne Test",
  "action": "budget",
  "previous": {"daily_budget": 20},
  "requested": {"daily_budget": 25.5},
  "note": "Simulation : rien n'a été envoyé à Meta. Renvoyer avec `dry_run: false` pour appliquer."
}
```

### Méthode conseillée pour un agent

1. `GET /campaigns` et `GET /logs` — lire l'état **live** et ce qui a déjà été tenté ;
   l'API fait foi, pas un état mémorisé.
2. Appeler la mutation **sans `dry_run: false`** pour obtenir le plan.
3. Présenter le plan à un humain.
4. Ne rejouer avec `dry_run: false` qu'après accord explicite.

Deux rappels qui évitent des conclusions fausses : les chiffres de performance Meta
ne sont **fiables qu'à J+4**, et des impressions à zéro signalent le plus souvent une
campagne qui ne diffuse pas (plafond, ciblage vide), pas une enchère trop basse.

### Sponsoriser une publication existante

```
POST /api/meta-ads/boost   {"post_platform_id": 812, "budget": 10, "days": 5, "dry_run": false}
GET  /api/meta-ads/boosts?post_platform_id=812
```

Sponsorise une publication **déjà publiée**, sans la republier : le post d'origine est
promu **avec ses likes et ses commentaires**. On désigne une diffusion RS-Max
(`post_platform_id`), pas un identifiant Meta brut — RS-Max connaît déjà la Page et
l'id du post.

Meta n'a pas d'endpoint « booster » : RS-Max monte les quatre objets
(campagne → ad set → créatif → annonce). Le créatif ne porte aucun contenu, seulement
une **référence** :

| Réseau | Champ de référence | Source RS-Max |
|---|---|---|
| Facebook | `object_story_id` = `{page_id}_{post_id}` | `platform_account_id` + `external_id` |
| Instagram | `instagram_user_id` + `source_instagram_media_id` | `platform_account_id` + `external_id` |

**Trois garde-fous spécifiques au boost**, en plus des cinq généraux :

1. **Budget borné dans le temps ET en montant** — `budget` est un total, `days` une
   durée (max 30). Traduit en `lifetime_budget` + `end_time`, jamais en budget
   quotidien sans fin, qui tournerait indéfiniment.
2. **Le plafond s'applique au quotidien équivalent** (`budget / days`) : « 300 € sur
   3 jours » est refusé comme 100 €/jour, malgré un total d'apparence raisonnable.
3. **Tout est créé en `PAUSED`.** Monter la structure ne coûte rien, l'activer dépense.
   Le lancement se fait ensuite par `POST /api/meta-ads/{adset_id}/status`
   `{"status":"ACTIVE","dry_run":false}` — donc via les garde-fous et le journal.

En `dry_run` (défaut), RS-Max demande à **Meta** de valider le créatif
(`execution_options=['validate_only']`) : la publication et les droits sont testés pour
de vrai, et `promotable` dit si le boost passera. Rien n'est créé.

Si une étape échoue en cours de route, la campagne déjà créée est supprimée —
sans quoi le compte se remplirait d'orphelins qu'aucun écran RS-Max ne montre.
Le lien publication ↔ campagne est conservé dans `meta_ads_boosts`, ce qui permet
d'ajuster ou d'arrêter ensuite depuis le post.

> **Prérequis Facebook** : l'utilisateur système doit avoir la **Page** en actif
> (pas seulement le compte publicitaire) et le jeton doit porter `pages_manage_ads`.
> Sans ça, Meta renvoie le sous-code `1885557` (« publication indisponible »), qui ne
> dit pas que le vrai problème est l'accès à la Page — RS-Max ajoute la précision.
> **Instagram fonctionne sans accès Page.**

### Choisir l'objectif, l'audience, le budget et la durée

Ces quatre réglages étaient en dur (`OUTCOME_ENGAGEMENT`, ciblage Portugal, budget total
sur N jours). Ils se choisissent désormais — à l'API comme dans l'interface (`/ads`).

```
GET  /api/meta-ads/objectives                     couples objectif → optimisation valides
GET  /api/meta-ads/targeting/search?type=interest&q=surf
GET  /api/meta-ads/targeting/custom-audiences
POST /api/meta-ads/targeting/estimate             {"audience_id": 3}
GET|POST|PUT|DELETE /api/meta-ads/audiences[/{id}]
POST /api/meta-ads/{adset_id}/adset               ciblage et dates d'un ad set existant
```

Paramètres supplémentaires de `POST /api/meta-ads/boost` :

| Paramètre | Défaut | Rôle |
|---|---|---|
| `objective` | `OUTCOME_ENGAGEMENT` | Voir `GET /objectives` |
| `optimization_goal` | défaut de l'objectif | Le couple est **vérifié avant l'appel** |
| `billing_event` | `IMPRESSIONS` | Facturer au clic assèche la diffusion sur petit budget |
| `audience_id` | — | Audience enregistrée ; sinon `targeting` brut, sinon ciblage large |
| `budget_type` | `lifetime` | `daily` pour un budget quotidien |
| `days` | — | Obligatoire, **même en budget quotidien** |
| `start_date` | maintenant | Une date passée est repoussée à +5 min |
| `bid_strategy` | volume max | `COST_CAP` / `LOWEST_COST_WITH_BID_CAP` exigent `bid_amount` |
| `special_ad_categories` | `[]` | Logement, crédit, emploi, politique |
| `with_estimate` | `false` | Ajoute la taille d'audience estimée au plan |

Trois points qui coûtent cher quand on les rate :

1. **Le couple objectif / optimisation est validé localement** contre le catalogue.
   Envoyé de travers, Graph répond « Invalid parameter » sans dire lequel des deux.
2. **`days` reste obligatoire en budget quotidien** : un budget quotidien sans date de
   fin tourne indéfiniment. Le plafond porte toujours sur le **quotidien équivalent**,
   et le plan expose `depense_maximale` — « 20/jour sur 30 jours » ne se lit pas comme 600.
3. **Une catégorie publicitaire spéciale interdit** le ciblage par âge, genre et centres
   d'intérêt. RS-Max les retire de la spec plutôt que de laisser Meta rejeter l'annonce.

### Audiences (objets RS-Max, pas des objets Meta)

Meta enterre le ciblage dans l'ad set : il meurt avec lui. Les audiences vivent donc
dans `meta_audiences` et se déroulent en `targeting` à chaque campagne.

- Les identifiants (intérêt, comportement, lieu, langue) **viennent tous de
  `GET /targeting/search`** — un id fabriqué ne renvoie pas d'erreur, il donne une
  campagne qui ne touche personne.
- Les intérêts partent en `flexible_spec` (OU), jamais à plat (déprécié, et sémantique ET).
- Une position (`facebook_positions`…) n'est envoyée que si sa régie est cochée.
- `targeting_automation.advantage_audience` est **toujours explicite** : omis, Meta
  élargit parfois l'audience de lui-même.
- Le ciblage est **copié figé** dans `meta_ads_boosts.targeting` à la création : l'audience
  pourra changer sans qu'on perde ce qui a réellement été diffusé.
- Enregistrer une audience ne dépense rien : ce chemin n'est **pas** derrière
  `META_ADS_WRITE_ENABLED` (seul le rôle `manager` est exigé).

### Écrans (session web, rôle manager)

| Écran | Rôle |
|---|---|
| `/ads` | Campagnes, budgets, dépenses, journal, sponsorisations |
| `/ads/{campaign}` | Ad sets : budget, audience, dates, lancement / pause |
| `/ads/boost/{postPlatform}` | Sponsoriser une publication : objectif, audience, budget, durée |
| `/ads/audiences` | Audiences enregistrées (recherche Meta + estimation de portée) |

L'interface passe par **les mêmes garde-fous** (`MetaAdsGuard`, `MetaBoostService`) :
un écran qui contournerait un plafond serait une porte dérobée. Le bouton « Simuler »
appelle le même `validate_only` que `dry_run` côté API.
