# API RS-Max — Documentation complète

API REST d'orchestration multi-plateformes : publication, planification, génération IA, stats.

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
12. [Endpoints — Statistiques et calendrier](#12-endpoints--statistiques-et-calendrier)
13. [Annexes : plateformes, langues, statuts](#13-annexes--plateformes-langues-statuts)

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
      "persona": { "id": 1, "name": "Tech Writer" },
      "languages": ["fr", "en"],
      "followers_count": 1250
    }
  ]
}
```

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

## 12. Endpoints — Statistiques et calendrier

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

## 13. Annexes : plateformes, langues, statuts

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
