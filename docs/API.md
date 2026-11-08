# API RS-Max — Documentation complète

API REST d'orchestration multi-plateformes : publication, planification, inbox, stats.

> **Fabrication d'images et de carrousels** (templates HTML/CSS, briques, thème,
> polices) : voir le document dédié [`carousel-api.md`](carousel-api.md).
> Il partage la base URL, l'authentification et les conventions décrites ici.

---

## Table des matières

1. [Base URL et authentification](#1-base-url-et-authentification)
2. [Conventions](#2-conventions)
3. [Produire du contenu : le texte vient de vous](#3-produire-du-contenu--le-texte-vient-de-vous)
4. [Le persona : quand il s'applique, quand il s'applique pas](#4-le-persona--quand-il-sapplique-quand-il-sapplique-pas)
5. [Les traductions](#5-les-traductions)
6. [Endpoints — Utilisateur et comptes](#6-endpoints--utilisateur-et-comptes)
7. [Endpoints — Posts (contenu simple)](#7-endpoints--posts-contenu-simple)
8. [Endpoints — Threads (multi-segments)](#8-endpoints--threads-multi-segments)
9. [Génération IA — retirée](#9-génération-ia--retirée)
10. [Génération IA + planification — retirée](#10-génération-ia--planification--retirée)
11. [Personas — plus d'API dédiée](#11-personas--plus-dapi-dédiée)
12. [Endpoints — Partenaires (marques)](#12-endpoints--partenaires-marques)
13. [Endpoints — Statistiques et calendrier](#13-endpoints--statistiques-et-calendrier)
14. [Annexes : plateformes, langues, statuts](#14-annexes--plateformes-langues-statuts)
15. [Endpoints — Extension Chrome (RS-Max Companion)](#15-endpoints--extension-chrome-rs-max-companion)
16. [Endpoints en session web (PAS accessibles par token)](#16-endpoints-en-session-web-pas-accessibles-par-token)
17. [Endpoints — Meta Ads (pilotage des campagnes)](#17-endpoints--meta-ads-pilotage-des-campagnes)
18. [Endpoint — Dépôt de média sans compression](#18-endpoint--dépôt-de-média-sans-compression)
19. [Inbox — commentaires et réponses avec persona](#19-inbox--commentaires-et-réponses-avec-persona)

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

Depuis **octobre 2026**, le cloisonnement par auteur est retiré : la **lecture**
porte sur tout le contenu, quel que soit le token utilisé et quel que soit
l'auteur de la publication. `GET /posts`, `GET /threads`, `GET /stats/*` et
`GET /calendar` rendent donc le même périmètre pour Xavier et pour Caroline.

> Avant cette date, les listes filtraient en dur sur l'auteur du token — même
> pour un admin, à rebours de l'interface web. Un inventaire fait par l'API
> rendait **zéro ligne** sur le contenu de l'autre, sans erreur pour le dire,
> alors que `GET /posts/{id}` s'ouvrait très bien. Si une vieille procédure
> affirme encore qu'« un admin voit tout par l'API », elle date d'avant le
> correctif et sa conclusion était fausse.

L'**écriture** reste cloisonnée, elle :

- **Admin** : modifie, publie et supprime le contenu de n'importe qui.
- **Manager** : droits de config (personas, sources, bot, settings) ; peut
  relancer un réseau tombé en erreur, rien de plus sur le contenu d'autrui.
- **User** : ne modifie que ses propres posts/threads ; ne **publie** que vers
  les comptes sociaux qui lui sont rattachés (`GET /accounts` reste limité à
  ceux-là — c'est une liste de cibles de publication, pas un périmètre de lecture).

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

## 3. Produire du contenu : le texte vient de vous

RS-Max ne rédige plus de publication par l'API. `POST /api/posts` et `POST /api/threads`
reçoivent un texte déjà écrit (par vous ou par une autre IA) et le publient mot pour mot,
sans réécriture ni crédit IA côté RS-Max.

Les routes `POST /api/generate`, `POST /api/generate-thread`, `POST /api/bulk-schedule` et
`POST /api/bulk-schedule-threads` ont été retirées (voir §9 et §10).

---

## 4. Le persona : quand il s'applique, quand il s'applique pas

Un **persona** est un `system_prompt` qui définit le ton, le style et la voix d'une marque. Il est stocké dans la table `personas` et rattaché à un compte social par `persona_id`, depuis l'écran `/personas` (rôle `manager`).

### Il sert uniquement aux réponses et aux commentaires

- **Inbox** : préparation et suggestion de réponses (§19).
- **Extension Chrome** : commentaires TikTok et mentions X.
- **Bot Bluesky** : commentaires, via les champs `bot_comment_context_*`.

### Il n'est JAMAIS appliqué dans ces cas

- **À la création d'un post via `POST /api/posts`** : le `content_fr` que vous envoyez est stocké tel quel.
- **À la publication via `POST /api/posts/{id}/publish`** : le contenu en DB est envoyé aux plateformes sans passer par l'IA.
- **À la lecture via `GET /api/posts/{id}`** : le contenu retourné est celui stocké en DB, jamais réécrit.
- **À la traduction automatique** : le prompt de traduction est neutre (`"You are a professional translator"`), sans style persona.

---

## 5. Les traductions

RS-Max utilise **OpenAI** pour les traductions (pas DeepL ni Google Translate). Le modèle est configurable via `Settings.ai_model_translation` (défaut : `gpt-4o-mini`, température `0.3`). Le system prompt est neutre : *« You are a professional translator. Only output the translation. »* — **aucun persona n'est injecté lors de la traduction**.

### Quand la traduction a-t-elle lieu ?

| Scénario | Moment de la traduction |
|----------|------------------------|
| `POST /api/posts` (statut draft ou scheduled) | **Pas de traduction immédiate.** Les autres langues seront traduites à la publication si nécessaire. |
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

**Carrousels Instagram** : des images de ratios différents sont refusées en `422` (tolérance 1 %, référence = 1re image), ici ainsi qu'à la création ou à la modification d'un post `scheduled`. Instagram recadrerait sinon tout le carrousel sur la première image, sans le signaler. Un `draft` reste enregistrable. Vidéos et images hors médiathèque ne sont pas contrôlées.

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

## 9. Génération IA — retirée

`POST /api/generate` et `POST /api/generate-thread` n'existent plus (404). Rédigez le texte de votre côté, puis envoyez-le via `POST /api/posts` ou `POST /api/threads`.

---

## 10. Génération IA + planification — retirée

`POST /api/bulk-schedule` et `POST /api/bulk-schedule-threads` n'existent plus (404). Pour planifier un lot, appelez `POST /api/posts` ou `POST /api/threads` une fois par publication, avec `scheduled_at`.

---

## 11. Personas — plus d'API dédiée

Les routes `/api/personas` n'existent plus (404). Les personas se gèrent sur `/personas` (session web, rôle `manager`) et s'appliquent aux réponses (§4).

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
- `native` — publication faite hors RS-Max, puis adoptée.
- `rss`, `wordpress`, `youtube`, `reddit` — historique uniquement : la génération depuis ces sources est retirée.

### Limites

- Text content : 10 000 caractères max.
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

### `GET /api/extension/x/session`

Indique si RS-Max attend une session pour un compte X. Paramètre : `platform_account_id`. Réponse : `driver`, `status` (`connected`, `expired` ou `null`) et `received_at`.

### `POST /api/extension/x/session`

Reçoit les cookies X du navigateur de l'utilisateur et les donne au navigateur du VPS. Plafond : 10 appels par minute.

- Le compte est déduit du cookie `twid`. Il doit être rattaché à l'utilisateur du token, sinon : 422.
- Les cookies sont stockés chiffrés. Seule une tâche en cours peut les obtenir, voir `GET /api/extension/tasks/{id}/session`.
- Réponse 200 : session active, avec `status` et `username`. Réponse 409 : X ne reconnaît pas le compte sur le VPS. Réponse 502 : navigateur du VPS injoignable.

Le **Mode de connexion** de chaque compte X se règle dans sa fiche : API X, ou Navigateur du VPS, avec son **Profil navigateur**. Plusieurs comptes peuvent partager un profil : ils passent alors chacun leur tour dans le même Chromium.

### `POST /api/extension/tiktok/session`

Corps : `username` et `cookies` (domaine `tiktok.com`, avec `sessionid`). Les cookies sont chiffrés dans le compte. Si un profil navigateur est configuré, ils sont installés sur le NAS puis Studio doit y afficher le même nom. Sans profil (routage suspendu ou non configuré), stockage seul : aucune installation ni visite TikTok. Compte choisi : celui qui porte déjà ce nom, sinon la seule fiche TikTok libre. Réponse 200 avec `status:connected`, `stored:true`, `browser_connected:true` après vérification ; ou `status:stored`, `stored:true`, `browser_connected:false` pour le stockage seul. Le champ `message` distingue ces cas. 409 : Studio montre un autre compte, ou plusieurs fiches libres. 422 : pas de session, ou aucune fiche TikTok. 502 : activation du navigateur indisponible ; la copie a pu être enregistrée auparavant.

### `GET /api/extension/tasks/next`

Réservé aux admins, et à la copie de Companion qui tourne sur le VPS. Paramètre : `profile`. Donne la prochaine tâche de ce profil, ou `{"id": null}`. Un profil ne reçoit qu'une tâche à la fois. Pour une tâche X, `account_id` donne l'identifiant X du compte attendu : Companion n'agit pas si un autre compte est connecté.

### `GET /api/extension/tasks/{id}/session`

Réservé aux admins. Donne les cookies du compte d'une tâche en cours, pour que Companion change de compte avant d'agir. Réponse 409 : tâche qui n'est pas en cours. Réponse 422 : aucune session enregistrée.

### `POST /api/extension/tasks/{id}/finish`

Corps : `status` (`done`, `failed` ou `uncertain`), `result` et `error`. Chaque geste est journalisé dans `bot_action_logs` avec `source` à `browser`. Une tâche `uncertain` n'est jamais relancée : l'action a peut-être eu lieu.

---

## 15 bis. Agent

Réservé aux rôles `manager` et `admin`. Réponses volontairement courtes, pour limiter les jetons consommés.

- `GET /api/agent/accounts` : comptes où l'agent peut agir, avec les actions activées et ce qu'il reste du quota du jour (`left`).
- `GET /api/agent/accounts/{id}/read/{action}?limit=10` : exécute une lecture (`mentions.read` sur X, `comments.read` sur TikTok). `limit` vaut 30 au plus. Texte coupé à 280 caractères. Réponses : 403 action désactivée, 422 compte hors mode navigateur, 429 quota atteint, 502 navigateur du VPS injoignable. Une lecture en échec ne consomme pas le quota.
- `PUT /api/agent/accounts/{id}/actions/{action}` : corps `enabled` et `daily_limit`. Seules les actions réellement exécutables se règlent.
- `GET /api/agent/log?account=&limit=20` : dernières actions de l'agent.

- `POST /api/agent/accounts/{id}/drafts` : corps `limit` (20 au plus). Réponse 202 : RS-Max relit le réseau, rassemble les conversations de la messagerie et rédige toutes les réponses en un seul prompt, avec le persona du compte. 403 action désactivée, 422 pas de persona actif. Chaque réponse rédigée compte une fois dans le quota `reply`.
- `GET /api/agent/drafts?account=&limit=20` : réponses qui attendent une décision (`id` du message, `account`, `author`, `reply`).

Les réponses préparées restent dans `inbox_items.reply_draft`, visibles dans `/inbox`, onglet **À valider**. **Valider reste un geste humain** : aucune route de l'agent n'envoie un brouillon. TikTok et X par le navigateur passent par la même messagerie que les autres réseaux. Une réponse non confirmée par le navigateur (`reply_failed`) n'est jamais relancée : elle a pu partir, il faut contrôler sur le réseau.

Une action est désactivée tant qu'elle n'a pas été activée sur le compte. Le catalogue est dans `config/agent.php`.

### Flux TikTok et interactions sur les vidéos

Tout passe par Companion sur le **NAS**, avec contrôle du profil connecté. Activer séparément `feed.read`, `video.comments`, `video.like`, `comment.like`, `video.comment` sur le compte.

- `POST /api/agent/accounts/{id}/tiktok/read` : `{"action":"feed.read","feed":"following","limit":3}` (1 à 10 vidéos, `following` ou `foryou`). Renvoie `items` : URL canonique, auteur, légende et états visibles.
- Même route avec `{"action":"video.comments","url":"https://www.tiktok.com/@…/video/…"}` : ouvre la discussion, développe les réponses et collecte les lignes même virtualisées. Renvoie `video`, `comments` (`key`, `author`, `text`, `parent`, `liked`, `media`, `ambiguous`), `observed`, `expected`, `complete`, `accessible_complete`, `reason`, `snapshot_id`. Lecture bornée à 55 s et 5 000 lignes : `complete:true` couvre le total annoncé ; sinon `accessible_complete:true` autorise la rédaction seulement après épuisement stable de la liste et des réponses accessibles. Toujours indiquer `observed` réellement lus / `expected` annoncés. Les deux à `false` signifient lecture partielle. Répéter la lecture sur le même onglet poursuit la collecte. L'instantané serveur expire en une heure. Le raccourci `GET /api/agent/accounts/{id}/read/video.comments?url=…` est aussi disponible.
- `POST /api/agent/accounts/{id}/tiktok/prepare` : `{"action":"video.like|comment.like|video.comment","snapshot_id":"…"}`, plus `comment_key` pour un commentaire précis. Prépare une interaction unique par compte/action/cible. Aucun geste réseau ici. Pour `video.comment`, tous les commentaires accessibles lus et persona actif du **compte TikTok** obligatoires ; toute la discussion est envoyée à RS-Max IA. Commentaire original, bienveillant, sans copier ni inventer de vécu, 150 unités UTF-16 maximum. La réponse `discussion` indique `observed`, `expected`, `coverage` (`reported_total` ou `accessible`).
- La validation se fait dans `/agent/tiktok`. Pour une exécution explicitement autorisée : `POST /api/tiktok/engagements/{id}/send` avec `{"dry_run":false}`. `dry_run:true` par défaut. Réponse 202, exécution en file ; `GET /api/tiktok/engagements/{id}` donne le résultat. `confirmed` exige une relecture après rechargement (105 s avant rechargement pour un commentaire). `uncertain` ou `failed` : aucun nouvel envoi automatique, vérifier TikTok.

Les routes `/api/agent/*` lisent et préparent ; elles n'envoient aucun brouillon. Une modification du persona invalide la validation du commentaire préparé. Les likes déjà présents sont conservés, sans bascule. Quotas contrôlés à la préparation et à l'exécution. La file database doit avoir `retry_after > 300` (360 s par défaut).

### Community Manager : abonnement Hermès et horaires

Le client `cli/community_manager.py` utilise uniquement `/api/community-manager/*`. Son jeton est limité à ces routes et aux comptes attribués. Plage **06–22 h, Europe/Lisbon**, vérifiée côté client, serveur et Companion.

Pour rédiger : `GET /api/community-manager/accounts/{id}/comment-context?snapshot_id=…` renvoie le persona actif du compte et les commentaires accessibles. **Hermès rédige lui-même avec son abonnement connecté** ; ce parcours n’appelle jamais le modèle API configuré dans RS-Max et ne consomme donc pas son quota API. `POST /api/community-manager/prepare` reçoit `account_id`, `action:video.comment`, `snapshot_id`, `context_id`, `text`. Le contexte est lié au compte, au snapshot et à l’empreinte du persona ; il expire après 15 minutes. Texte absent ou persona changé : refus, aucun repli API. `draft_source:hermes_subscription` identifie ce chemin. La limite de 150 unités UTF-16 et la preuve après rechargement restent obligatoires. Aucun remplacement après une tentative.

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

---

## 18. Endpoint — Dépôt de média sans compression

### `POST /api/media/upload` (multipart)

Dépose un fichier **sans le toucher** : images **et vidéos**, l'octet exact, ni GD
ni ffmpeg. C'est le seul chemin par lequel une vidéo peut entrer par API —
`/media/ingest` n'accepte que des images et exige un `phash`, qui n'est calculable
que sur une image.

| Champ | |
|---|---|
| `file` | **requis**. jpeg, png, gif, webp, mp4, mov, webm. Plafonds : réglages `image_max_upload_mb` (50) et `video_max_upload_mb` (500) |
| `folder_path` ou `folder_id` | dossier de la médiathèque, créé au besoin |
| `description_fr`, `thematic_tags[]`, `brands[]` | optionnels |
| `intimacy_level` | `public` par défaut ; un dossier privé escalade en `never_publish` |

**Idempotent par `content_hash`** (SHA-256 des octets) : renvoyer le même fichier
rend `200` + `status: "exists"` plutôt qu'un doublon — un lot relancé après une
coupure réseau est donc sans danger.

```json
{
  "id": 412, "status": "created", "filename": "20261005_a1b2c3d4.png",
  "url": "/media/20261005_a1b2c3d4.png", "mimetype": "image/png",
  "size": 6291456, "width": 2160, "height": 2700, "preserve_original": true
}
```

L'`url` retournée s'utilise telle quelle dans `media[]` d'un
[`POST /api/posts`](#7-endpoints--posts-contenu-simple) ou d'un segment de fil.

### Ce que `preserve_original` change à la publication

La ligne porte `preserve_original = true`, et la normalisation qu'un réseau impose
n'a plus lieu à l'upload mais **à la publication, pour ce réseau seul**
(`MediaVariantService`, plafonds dans `config/media_variants.php`). Instagram
reçoit l'original ; seul Bluesky reçoit une version réduite, écrite dans une
variante à côté — l'original n'est jamais remplacé.

Les quatre chemins d'entrée historiques (upload web, `/media/ingest`,
téléchargement d'URL, import externe) **compressent toujours**, à dessein.

Détail et pièges : [`docs/cli-publication-sans-compression.md`](cli-publication-sans-compression.md).


## 19. Inbox — commentaires et réponses avec persona

Disponible par token Sanctum depuis octobre 2026, sur les réseaux déjà gérés
par l'inbox : `facebook`, `instagram`, `threads`, `youtube`, `bluesky`,
`telegram`, `twitter` (X). Les plateformes désactivées dans les réglages sont
exclues. Pinterest et LinkedIn ne sont pas pris en charge par cette inbox.

La lecture suit la visibilité de l'interface ; **générer ou envoyer une réponse
exige un rattachement actif de l'utilisateur au compte social**, y compris
pour un administrateur. Voir tout ne donne pas le droit de répondre partout.
Le persona est celui du **compte destinataire**, jamais celui de l'auteur du
commentaire, ni celui d'un autre compte du même réseau.

### Sélectionner au maximum N messages

`POST /api/inbox/ai-prepare`

```json
{
  "platforms": ["instagram", "facebook"],
  "accounts": [12, 18],
  "limit": 10,
  "types": ["comment", "reply"],
  "order": "oldest"
}
```

- `limit` obligatoire, de 1 à 250, **pour tout le lot**, pas par réseau.
- Au moins un filtre `platforms` ou `accounts` est obligatoire. S'ils sont
  fournis ensemble, c'est leur **intersection**. Comptes : IDs de `GET /api/accounts`.
- `types` : `comment`, `reply`, `dm`. Par défaut commentaires et réponses ; les
  messages privés nécessitent un choix explicite.
- `order` : `oldest` par défaut, ou `newest`.
- Uniquement les messages `unread`/`read` sans réponse, brouillon, programmation
  ni tentative d'envoi antérieure. Les messages du compte lui-même sont exclus.
- Persona actif et consignes non vides obligatoires. Sinon le compte apparaît
  dans `skipped_accounts`, avec sa raison ; aucun persona générique de secours.

Réponse : `requested`, `selected`, `available` (messages sans réponse avant
le filtre persona), `eligible` (avec persona), `skipped_accounts` et `items`.
Chaque item fournit son `id`, texte, auteur, réseau, compte, persona, état,
lien de publication et dates. **Cet appel ne génère ni n'envoie de réponse**.

`GET /api/inbox` accepte les mêmes filtres, `limit` (défaut 20), `page` et
`status` (`unanswered` par défaut ; `unread`, `read`, `replied`, `archived`,
`ignored`, `reply_failed`, `all`). Réponse paginée `items` + `pagination` ;
`can_reply` distingue le droit de regard du droit de réponse.

### Générer chaque réponse

`POST /api/inbox/{id}/ai-suggest` avec `{}`.

```json
{
  "id": 456,
  "reply_text": "Merci, on a adoré cet endroit aussi !",
  "persona": {"id": 3, "name": "Caroline"}
}
```

Réutilise le générateur de l'interface : persona, consignes inbox, contexte du
fil et langue du message. **Le persona est forcé pour cette API**, même si le
réglage web `inbox_use_persona` est désactivé. Aucun texte n'est enregistré ni
envoyé au réseau. Les appels se font message par message pour éviter un long
appel HTTP sur un lot de 250 générations.

### Aperçu, envoi ou programmation

`POST /api/inbox/{id}/reply`

```json
{"reply_text": "Merci !", "dry_run": true}
```

`dry_run` vaut **true par défaut** : renvoie le texte et le destinataire, sans
écriture ni envoi. Passer explicitement `false` pour envoyer. On peut joindre
`scheduled_at` (date future ISO 8601 avec fuseau) pour utiliser le scheduler
existant. La programmation stocke le texte exact, elle ne le régénère pas.

Une relance avec le même texte après succès renvoie `already_replied: true`
sans republier ; une programmation identique renvoie `already_scheduled`.
Une autre réponse déjà présente renvoie `409`. Un verrou par message est
partagé avec l'interface et le scheduler. L'API ne retente pas automatiquement
un envoi refusé ou non confirmé. Après un résultat incertain, contrôler le
réseau avant de réessayer. Les réponses programmées gardent les trois tentatives
maximales historiques du scheduler.

Codes : `401` sans token, `403` compte non autorisé, `409` message déjà traité
ou occupé, `422` paramètres/persona/configuration IA, `502` refus d'envoi.
Génération et envoi sont limités à 60 appels/minute par utilisateur.

### CLI : préparer puis envoyer les textes relus

```bash
python3 cli/rsmax.py repondre --reseaux instagram,facebook --comptes 12,18 \
  --nombre 10 --sortie /tmp/reponses.json
python3 cli/rsmax.py repondre --plan /tmp/reponses.json --envoyer
python3 cli/rsmax.py repondre --plan /tmp/reponses.json --envoyer --etaler-minutes 60
```

La première commande génère un aperçu JSON, sans envoi. Les deux suivantes
sont deux choix alternatifs : envoi immédiat ou réparti sur 60 minutes.
Sans `--plan`, `--envoyer` permet aussi de générer puis envoyer directement
un lot ciblé. Le plan conserve l'instance, les IDs, comptes, personas et textes.
Sa reprise conserve les textes exacts et saute les succès déjà enregistrés.
Les filtres de réseau/compte ne se combinent pas à `--plan` : modifier le fichier
pour retirer des items avant l'envoi. Le CLI n'effectue aucun nouvel essai HTTP
automatique en cas d'erreur. `--dry-run` (avant la sous-commande) décrit l'appel
sans génération IA ni publication. Le plan est un fichier de travail à supprimer
après utilisation, sans le copier dans le vault.


### Nouveaux commentaires et suivi du cycle (28 octobre 2026)

`new_only: true` sur `/inbox/ai-prepare` sélectionne exclusivement les commentaires
directs (`type=comment`, sans parent), dans une conversation sans réponse passée,
préparée ou programmée. Les messages privés et réponses à un commentaire sont
exclus. La limite maximale est désormais **250 pour le lot entier**.

`GET` ou `POST /api/inbox/status` calcule les compteurs, sans génération ni envoi.
Fournir `ids` (1 à 1000 identifiants distincts) pour suivre un **cycle précis**,
ou au moins `accounts`/`platforms` pour lire toute la file d'un périmètre.
Le cycle est la liste figée d'IDs conservée dans le plan du client ; cette route
ne crée pas de nouveau lot et ne réserve aucun message.

```json
{"ids": [123, 456, 789]}
```

Réponse : `counts` et `by_platform` avec `total`, `unprepared`, `prepared`,
`scheduled`, `sent`, `failed`, `excluded`, `pending` et `complete` ; `next_at`,
`last_planned_at`, `last_sent_at` et `missing_or_inaccessible` complètent le suivi.
`pending` additionne les messages non préparés, préparés et programmés.
`scheduled` inclut un envoi dont le scheduler attend encore le résultat du réseau.
`complete` ne signifie pas succès intégral : regarder aussi `failed`/`excluded`.
Un ID absent ou inaccessible empêche de déclarer le cycle terminé. Les réseaux
désactivés restent visibles pour compter leur historique. `new_only` ne masque
pas les messages du cycle une fois envoyés.

Le plan du CLI conserve tous les IDs dès la sélection, avant la première génération :

```bash
python3 cli/rsmax.py repondre --reseaux instagram,facebook,bluesky,youtube \
  --comptes 28,29,43,44,47 --nombre 250 --nouveaux-commentaires --sortie /tmp/cycle.json
python3 cli/rsmax.py etat-reponses --plan /tmp/cycle.json
```

Le suivi décrit l'état **dans RS-Max**. Un texte seulement généré en aperçu local
reste `unprepared` dans ces compteurs jusqu'à son envoi ou sa programmation.


### Stratégie et cadence par compte (Community Manager)
`GET /api/accounts/{id}/strategy` et `PATCH` au même endroit ; mêmes routes sous `/api/community-manager/accounts/{id}/strategy` pour le jeton dédié. Manager et compte attribué actif requis. PATCH partiel :
```json
{"agent_hourly_limit":2,"agent_min_interval_seconds":60,"agent_strategy":{"audience":"Public francophone","objective":"Échanges utiles","hashtags":["#mode"],"target_accounts":["@exemple"],"search_queries":["idées de tenue"],"exclusions":[]}}
```
Listes : 100 entrées maximum, une ligne par entrée dans le formulaire. `null` enlève le plafond supplémentaire, `0` bloque les gestes. Le ciblage n’active aucun follow/unfollow. Chaque réseau et compte conserve ses propres réglages.
La réponse contient `cadence` (tentatives sur 60 minutes glissantes, confirmées, restantes), `hourly_history` (24 h, heures de Lisbonne) et `actions` avec `channel=api|browser`, disponibilité et quotas quotidiens. Échec/incertitude consomment aussi une tentative ; lecture et contrôle de session ne consomment pas de geste. Une réponse et son like sont deux gestes. L’adaptation est désactivée par défaut et se configure séparément pour chaque compte.
`account_action_events` conserve les tentatives indépendamment des journaux purgés à 7 jours. Réservation sous verrou du compte avant prise d’une tâche d’écriture NAS ou réponse inbox API ; aucun geste quand le plafond/délai est atteint. Le contrôle couvre les écritures navigateur et les réponses inbox ; les moteurs historiques dédiés gardent leurs propres quotas.

Inbox du jeton dédié : `GET /api/community-manager/accounts/{id}/inbox`, `GET /api/community-manager/inbox/{item}/context` (persona actif, texte, légende, contexte valable 15 min), puis `POST /api/community-manager/inbox/{item}/reply` avec `context_id`, `reply_text`, `dry_run:false` explicite. Par défaut : aperçu. Rédaction par Hermès via son abonnement ; aucun appel IA RS-Max. Persona modifié/contexte expiré : refus. Même service d’envoi et mêmes protections anti-doublon que la messagerie. TikTok part en planification, puis preuve après rechargement et like automatique si réponse confirmée. La fenêtre 06–22 h du jour est persistée avec la réponse et vérifiée avant le geste NAS. Lire l’état via inbox après un timeout, jamais renvoyer.
CLI : `strategy --account ID [--update-file JSON]`, `inbox --account ID`, `reply-context ITEM`, `reply ITEM --context UUID --text-file FICHIER [--commit]`. IDs découverts dans le catalogue, jamais figés dans les consignes.

### X — Community Manager par Companion sur le NAS
Les routes suivantes existent sous `/api/agent` et `/api/community-manager` (ce dernier exige le jeton dédié et le compte attribué). Aucune API X ni génération IA RS-Max n’est appelée.

| Méthode et chemin après le préfixe | Usage |
|---|---|
| `POST accounts/{id}/x/read` | `action=feed.read|search.read|mentions.read|thread.read|profile.read`, `feed=following|foryou`, `query`, `url`, `handle`, `limit<=30`, `screens<=5` ; renvoie `snapshot_id` valable 1 h et complétude réelle |
| `GET accounts/{id}/x/context?snapshot_id=UUID&purpose=reply` | Fil lu requis ; persona actif et contexte valable 15 min pour rédiger dans Hermès. `purpose=quote` prépare une citation sans persona |
| `POST accounts/{id}/x/prepare` | `action`, `snapshot_id`, `target_id` pour un post ; réponse/citation : `context_id`, `text`. Déduplication persistante compte/action/cible |
| `POST x/engagements/{id}/execute` | Aperçu par défaut ; seul `dry_run:false` explicite enfile un geste |
| `GET x/engagements/{id}` | État, résultat, `confirmed`, `unchanged`, erreur ; reste lisible après expiration de session |
| `GET accounts/{id}/x/contacts?page=1` | Découvertes, suivi/désuivi confirmé, retour d’abonnement observé |

Actions d’écriture : `like`, `unlike`, `bookmark`, `unbookmark`, `reply`, `quote`, `repost`, `unrepost`, `follow`, `unfollow`. Elles sont désactivées par défaut ; activation et quotas dans `/agent/twitter`. Contrôle 06–22 h à Lisbonne, identité, persona, quota et cadence à la prise puis dans Companion avant clic. Un seul envoi, preuve après rechargement ; aucun renvoi d’un résultat incertain. `social_contacts` garde la mémoire au-delà des journaux de 7 jours. `agent_strategy.unfollow_after_days` (1–365, défaut 7) n’autorise pas un désuivi à lui seul : suivi de l’agent daté, deux absences espacées de 15 min et relecture récente sans retour exigés.

Client Hermès : `x-read`, `x-context`, `x-action [--commit]`, `x-status`, `x-contacts`. Mémoire SQLite `x_attempts` distincte de TikTok et inbox ; intention enregistrée avant HTTP. Le skill est livré dans `hermes/skills/community-manager-x/SKILL.md`.

Publications X en mode navigateur : publications planifiées/manuelles et segments de fil portent une clé de livraison dans `browser_tasks`, réutilisée après reprise. Un résultat incertain ne crée aucun deuxième ordre. Médias avant texte, un seul clic final, ID et preuve après rechargement exigés. Les articles non pris en charge échouent explicitement, sans API. Le délai de redistribution de queue est au moins 1 200 s, au-dessus du timeout du job vidéo (1 080 s).

`GET /api/accounts` expose `browser_followers_observation={value,precision,display,read_at}` : `precision=rounded` n’écrase ni le compteur exact ni ses snapshots. Les nouvelles publications natives dont le navigateur ne peut déterminer le statut de réponse restent `ignored_reason=browser_unclassified`, avec leurs métriques ; aucune adoption automatique hasardeuse.

Le contexte de la messagerie X (`GET /api/community-manager/inbox/{item}/context`) relit aussi le fil sur le NAS : publication d’origine, cible exacte et réponses visibles. Une intervention du compte déjà présente bloque une deuxième réponse. L’intention inbox est persistée avant le départ réseau ; le like automatique conserve la fenêtre horaire et attend la cadence.

Rapport : `GET /api/community-manager/report?hours=24` (également `/api/agent/report`) renvoie les comptes attribués, tentatives, confirmations, échecs/incertitudes et texte Telegram. `POST` au même endroit avec `hours`, `note` facultative (600 caractères), `dry_run:false` et `report_key` UUID envoie sur le bot RS configuré. Aperçu par défaut ; clé persistée avant envoi, aucun renvoi après résultat incertain. Client : `report --hours 24 [--note-file FICHIER] [--commit] [--key UUID]` ; clé quotidienne stable par défaut et mémoire locale distincte.
Le catalogue Community Manager expose aussi `next_publication_at` avec son fuseau. Avant une réponse et son like, réserver deux gestes ; éviter la dernière heure avant une publication planifiée afin que la cadence ne la bloque pas.

Une réponse inbox X par navigateur est désormais programmée, comme TikTok : HTTP 202 puis vérification dans `GET /api/community-manager/inbox/{item}` (`reply-status ITEM`). Le contrôle attend le montage du commentaire parent après rechargement ; une cible n’est pas son parent ni sa citation.

Pour résoudre une incertitude sans renvoyer : lire l’URL propre de la réponse retrouvée par `x/read` avec `action=thread.read`, puis `POST /api/community-manager/inbox/{item}/reconcile` avec `snapshot_id`. Aperçu par défaut ; `dry_run:false` rapproche la preuve. Auteur, texte, parent, heure de publication et lecture postérieure à la tentative doivent correspondre. La même tentative devient confirmée, l’incertitude initiale reste dans son résultat et seul le like automatique est déclenché. Aucun nouvel envoi de réponse. Client : `reconcile-reply ITEM --snapshot UUID [--commit]`.

Scripts Hermès : `rsmax_x_watch.py` fournit une prélecture compacte sans IA ; `rsmax_morning_report.py` envoie les bilans de 08 h et 20 h Lisbonne sans modèle. Aucun compte en dur ; attribution, session, stratégie, horaires et cadence viennent de RS-Max.

### Adaptation de la cadence par compte
`PATCH /api/accounts/{id}/strategy` (gestionnaire, jeton complet) :
```json
{"agent_hourly_limit":120,"agent_daily_limit":400,"agent_adaptation":{"enabled":true,"max_hourly":300,"max_daily":1000}}
```
Ces valeurs sont des exemples modifiables, jamais une limite commune au réseau. La réponse `cadence` expose le compteur `daily` (minuit Lisbonne), la politique, l’état de pause et les dix derniers ajustements avec motif. Le formulaire du compte expose les mêmes réglages. Un jeton `community-manager` peut lire cette politique et modifier le ciblage, mais ne peut pas modifier la politique ni la cadence lorsqu’elle est adaptative.

`agent:adapt-limits` s’exécute toutes les cinq minutes. Après au moins dix gestes confirmés et trente minutes stables, hausse de 25 %, arrondie à la dizaine supérieure, sans dépasser les deux plafonds autorisés. Le délai suit la cadence (minimum 12 s). Les quotas des réponses et likes déjà activés suivent la cadence quotidienne ; aucune action n’est activée. Les autres quotas restent indépendants.

Un échec ou une incertitude suspend la hausse et impose quinze minutes de pause. Seul un résultat Companion `platform_limit: {source:"platform_ui", scope:"temporary"|"daily"}` réduit de moitié les cadences et impose une pause d’une heure ou de 24 h. Les alertes sont lues hors du contenu social et des éditeurs ; un refus interne RS-Max ne constitue pas une limitation de X. Les ajustements automatiques sont notifiés au bot Telegram configuré. Aucun envoi incertain n’est répété. Les plafonds horaire et quotidien réservent toutes les tentatives d’écriture couvertes avant exécution, échecs compris ; lectures et contrôles de session restent séparés.

Le moniteur Hermès limite chaque lot à cinq conversations pertinentes, attend les preuves et laisse le navigateur libre à ±5 min d’une publication planifiée. Il peut encore choisir un geste de flux autorisé quand le quota de réponses est épuisé. Une enveloppe n’est jamais un objectif de volume.

### Journal des automatisations (rétention : 7 jours)
- Dans `/agent/{réseau}`, **Journal récent** et **Journal des actions** (`/agent/{réseau}/logs`) : compte, action, canal, état en attente/en cours/confirmé/échoué/incertain/annulé, texte, cible, durée, preuve et étapes Companion. Pagination et filtres compte/action/canal/état.
- `GET /api/agent/logs` (gestionnaire) ; `GET /api/community-manager/logs` limité aux comptes attribués. Paramètres : `platform`, `account_id`, `status`, `action`, `source`, `limit` (1–100, défaut 30), `before_id` ; réponse `logs`, `has_more`, `next_cursor`, `retention_days:7`. Aucun payload brut, cookie ni jeton. `agent.catalog.read` indique que le client/moniteur a consulté les possibilités du compte ; cette lecture ne prouve ni réflexion du modèle ni geste social et ne consomme aucun geste.
- Companion admin : `POST /api/extension/tasks/{id}/progress` reçoit `steps` (100 maximum) pour une tâche en cours. Chaque étape a `id`, `stage`, `elapsed_ms`, `details` filtrés. `finish` reçoit aussi `diagnostics` ; fusion dédoublonnée, aucun second geste. Les diagnostics restent uniquement dans le journal court, pas dans le résultat métier durable.
- `agent:purge-logs` s’exécute chaque heure, même sans ouvrir la page. `agent:backfill-logs` reconstruit les traces des tâches de la dernière semaine sans exécuter de geste. La mémoire métier anti-doublon (`browser_tasks`, engagements) et les compteurs restent séparés des logs pour conserver l’interdiction de renvoyer une réponse incertaine.
- Hermès : `scripts/community_manager.py logs --account ID --limit 20 [--status uncertain] [--before CURSOR]`. Diagnostic en lecture seule, possible de nuit ; un log ne donne aucune permission supplémentaire.

### Réponses autonomes : avancer dans la file, sans validation humaine
`GET /api/community-manager/accounts/{id}/inbox?agent_queue=1` exclut temporairement les commentaires déjà examinés (`agent_review.reason/retry_at`) sans modifier leur statut humain. `POST /api/community-manager/inbox/{id}/review` accepte `reason:not_relevant` (7 jours) ou `retry_later` (15 min). Un contexte X déjà traité ou modifié répond `409, skip_item:true`, enregistre le motif et laisse le lot continuer ; le client retourne ce résultat normalement. Aucun message n’est déclaré répondu sans preuve.

Le moniteur réveille chaque créneau de travail disponible, même avec une prélecture identique ; un état inactif reste silencieux. Les réponses X et TikTok autorisées via Community Manager sont sauvegardées et confiées immédiatement à `SendAutonomousInboxReply` (un essai, cadence et anti-doublon conservés), avec `validation_required:false`. Pas de passage par les brouillons à valider, ni d’attente du cron minute. Les routes de préparation manuelle restent distinctes.

Client : `inbox --account ID --pending`, `reply-context ITEM`, puis `reply ITEM --context UUID --text "RÉPONSE" --commit`. `report --note "BILAN" --commit --key UUID` conserve la note et envoie le bilan. Pas de fichier à créer par une commande séparée ni de Python -c en cron. Le skill demande de poursuivre après un candidat écarté ; il ne demande aucune validation humaine pour les réponses autonomes autorisées.

### Cadence des bilans Telegram
Le profil Hermès transmet deux bilans automatiques : 08 h et 20 h, heure de Lisbonne, sur les douze dernières heures. Une clé par jour/créneau empêche les doublons. Les cycles enregistrent seulement `note --text "BILAN"`, sans envoi. Le profil `report_schedule_only:true` neutralise aussi les anciens appels de cycle `report --commit` ; le script planifié emploie `--scheduled`. Les alertes techniques dédiées sont distinctes des bilans.

Le cycle inbox partagé sélectionne X et TikTok depuis le catalogue : TikTok éligible en premier, une conversation au plus et deux gestes réservés (réponse + like). Il n’active aucune action du flux TikTok. Les tentatives durables `tiktok.reply` consomment le quota quotidien de réponses, indépendamment des likes du flux. Après rechargement, seules les preuves confirment réponse et like ; résultat incertain : aucune relance. L’ancien cron TikTok limité aux likes du flux reste en pause.

### TikTok : vérifier et rapprocher une réponse incertaine
Companion 0.16.7 attend le filtre de Studio après rechargement (20 s) ; une relecture impossible est distinguée d’une réponse réellement absente. `POST /api/community-manager/inbox/{item}/verify-reply` crée seulement une lecture `tiktok.verify`, sans geste ni quota, et renvoie `verification_task_id` + `result`. `POST /api/community-manager/inbox/{item}/reconcile` accepte ce `verification_task_id` ; aperçu par défaut, `dry_run:false` rapproche la preuve et déclenche le like seul. Compte, texte, commentaire, légende et relecture postérieure à l’envoi doivent correspondre ; `confirmed:true`, `proof:after_reload` et `verified_kind:tiktok.reply` exigés. Un second rapprochement ne déclenche rien. CLI : `verify-reply ITEM`, puis `reconcile-reply ITEM --verification-task ID [--commit]`. Aucun renvoi de réponse.

Companion 0.18.4 observe passivement la réponse native de Studio (fetch/XHR) : `result.submission` conserve HTTP, `status_code`, `comment_status`, identifiants et correspondance du texte, sans cookie, jeton ni nouvelle requête TikTok. Exposé dans les preuves du journal API. HTTP 200, code 0, « envoyé » et apparition immédiate ne prouvent pas la persistance. Un statut interne n’est pas interprété comme une modération ou un quota sans preuve explicite.

Après un envoi inbox TikTok incertain, deux jobs `VerifyTikTokInboxReply` relisent à +5 puis +30 minutes, sans envoi ni remise à zéro des tentatives. Si une réponse exacte est retrouvée après reload, rapprochement idempotent et like seul ; sinon le message reste en échec, sans relance. Les anciens essais ne sont pas réarmés. Le 08/11, un essai neuf au statut interne 7 a persisté ; un autre au statut 2 a disparu malgré HTTP 200/code 0 et « Comment sent successfully », parent exact retrouvé avec zéro réponse dans la liste native. Cela ne prouve pas une suppression définitive : une réponse peut rester visible à son auteur sans être publique. La vérification anonyme de 0.18.5 distingue ces états.

Companion 0.18.5 utilise pour les réponses NAS une saisie et un clic natifs Chromium, par un service loopback authentifié (9223, jamais publié par Docker). La tâche, le nonce, l’avatar, le commentaire et le texte sont contrôlés ; une intention durable précède le clic. Service absent/timeout : aucun repli simulé ni nouvel envoi. Installation et tests dans `native/README.md` du patch `companion/patches/0.18.5-tiktok-entree-native.patch`.

Le reçu est sauvegardé par `POST /api/extension/tasks/{id}/checkpoint` avant l’attente ; ce checkpoint ne peut ajouter ni `confirmed` ni `proof`. Après reload Studio, le service relit dans un contexte anonyme jetable : CID/parent/vidéo/auteur/texte du reçu doivent correspondre aux données publiques et au DOM. Seuls `visibility:public`, `verification_source:anonymous_browser`, `confirmed:true`, `proof:after_reload` confirment le nouveau parcours NAS. Le CID est conservé dans `reply_external_id`. Une réponse visible au seul auteur reste incertaine. `verify-reply` reprend ce reçu, `reconcile` exige le même CID et déclenche seulement le like. Attente serveur d’envoi 330 s, verrou inbox 390 s, relecture 180 s ; conserver `retry_after > 330` (360 s actuellement). Hermès attend jusqu’à six minutes pour réponse et like TikTok.

### Photos et carrousels TikTok depuis les médias
Publication native par Companion NAS : `POST /api/community-manager/accounts/{id}/tiktok/photo-posts`, IDs de médias ordonnés, titre, description et visibilité ; aperçu par défaut, clé UUID stable obligatoire pour exécuter, répétition sans publication disponible. `GET /api/community-manager/tiktok/photo-posts/{id}` donne l’état et la preuve ; `POST .../{id}/verify` relit et rapproche sans renvoi. Action `photo.publish` par compte ; dossiers privés et photos interdites refusés. Voir [le parcours complet et les commandes Hermès](tiktok-photos.md).

### Inbox autonome API et découverte Threads
Facebook, Instagram, Threads et YouTube utilisent le même parcours Community Manager de réponses immédiates sans validation. Facebook ajoute `POST /api/community-manager/inbox/{id}/like` (aperçu par défaut) et le like automatique après réponse. Découverte/interactions Threads sur le NAS : `accounts/{id}/threads/read`, `/context`, `/actions`, `GET threads/actions/{task}`. Le compte Threads conserve son driver API ; la technique dépend de l’action. Voir `docs/community-manager-api-threads.md`.
