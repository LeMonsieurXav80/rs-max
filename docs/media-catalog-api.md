# Catalogue média — Référence API

Ce document décrit tous les endpoints exposés par RS-Max pour le pipeline média :
ingest depuis le Mac, recherche, classification, génération de threads avec photos auto-attachées, et banques d'images externes.

**Auth** : Sanctum (Personal Access Token). En-tête : `Authorization: Bearer <token>`.

**Création du token** :
```bash
php artisan tinker
>>> $u = User::where('email', 'votre@email.com')->first();
>>> echo $u->createToken('mon-app', ['media:ingest', 'media:read'])->plainTextToken;
```

**Base URLs** :
- Dev local : `https://rs-max.local`
- Prod : URL de l'instance Coolify

---

## Sommaire

- [Pipeline Mac (ingest et enrichissement)](#pipeline-mac)
- [Médiathèque (recherche, classification)](#médiathèque)
- [Banques d'images externes (Pexels, Pixabay, Unsplash)](#banques-dimages)
- [Génération de threads avec auto-attach photos](#génération-threads)
- [Tracking des publications](#tracking-publications)
- [Garde-fous serveur](#garde-fous)

---

## Pipeline Mac

### `POST /api/media/ingest`

Push une photo + métadonnées pré-calculées localement (description, tags, embedding CLIP, phash). Idempotent par phash.

**Body** : `multipart/form-data`
- `file` : binaire image (jpeg/jpg/png/gif/webp, max 50 Mo)
- `metadata` : string JSON

**Champs metadata** :
```jsonc
{
  "phash": "a1b2c3d4...",                  // OBLIGATOIRE, dédup
  "thematic_tags": ["plage", "parasol en paille", "calme", "albufeira", "2004"],
  "embedding": [0.12, -0.34, ...],         // ~512 floats CLIP, optionnel
  "embedding_model": "clip-ViT-B-32",
  "pool_suggested": "pdc_vantour",         // wildycaro|pdc_vantour|mamawette|both|none
  "people_ids": ["caroline", "xavier"],
  "intimacy_level": "public",              // public|prive|never_publish
  "allow_wildycaro": false,                // pose les flags directement
  "allow_pdc_vantour": true,
  "allow_mamawette": false,
  "ai_metadata": { "person_count": 2, "..." : "..." },
  "source_path": "/Volumes/T5/Photos/...",
  "folder_path": "PdC/Espagne_2025"
}
```

**Réponse 201** (créée) :
```json
{
  "id": 142,
  "status": "created",
  "filename": "20260425_143022_X8aB3kLm.jpg",
  "url_full": "/media/20260425_143022_X8aB3kLm.jpg",
  "url_thumb": "/media/20260425_143022_X8aB3kLm.jpg"
}
```

**Réponse 200** (déjà existante via phash) : même format avec `status: "exists"`.

**Comportement** :
- Le serveur **compresse l'image** automatiquement (mêmes settings que les uploads web : redimensionnement max 2048px, qualité adaptative entre 200-500 KB)
- Le serveur **normalise les tags** : split sur `:` et `,`, lowercase, dedup, max 60 chars/tag

### `GET /api/media/pending-analysis`

Liste les photos qui attendent un enrichissement IA — uploads web sans tags, imports en masse, photos legacy.

**Query params** : `limit` (1-500, défaut 50)

**Réponse** :
```json
{
  "items": [
    {
      "id": 87,
      "filename": "20260423_091234_aBc.jpg",
      "mime_type": "image/jpeg",
      "source": "upload",
      "original_name": "IMG_5421.jpg",
      "created_at": "2026-04-23T09:12:34+00:00",
      "url_full": "/media/20260423_091234_aBc.jpg"
    }
  ],
  "total_pending": 109
}
```

### `POST /api/media/{id}/enrich`

Pose les méta IA sur une photo existante (rattrapage legacy). Met `pending_analysis = false`.

**Body JSON** : mêmes champs que `/ingest` sans `file`. `phash` optionnel.

```json
{ "id": 87, "status": "enriched", "pending_analysis": false }
```

### `POST /api/media/{id}/validate`

Corrige les flags / tags / personnes d'une photo après coup.

**Body JSON** :
```json
{
  "allow_wildycaro": true,
  "allow_pdc_vantour": false,
  "allow_mamawette": false,
  "intimacy_level": "public",
  "thematic_tags_override": ["vanlife", "espagne"],
  "people_ids_override": ["caroline"]
}
```

Tous les champs sont optionnels.

---

## Médiathèque

### `GET /api/media/search`

Recherche dans la médiathèque locale.

**Query params** :
- `pool` : **OBLIGATOIRE**, `wildycaro` ou `pdc_vantour` (mamawette est **rejeté** par cet endpoint)
- `query_embedding[]` : tableau de floats — **OPTIONNEL**. Si fourni, tri par similarité cosine. Sinon, ordre random parmi les matches.
- `tags[]` : sous-chaînes recherchées via JSON_SEARCH+LIKE (case-insensitive). Match tolérant : `"plage"` trouve `"plage paradisiaque"`, `"chaise longue"` trouve `"chaise longue bleue"`.
- `people[]` : match exact (les ids sont normalisés `caroline`, `xavier`)
- `exclude_recently_published_days` : exclut les photos publiées récemment. Défaut : 30 pour `pdc_vantour`, 0 pour `wildycaro`.
- `limit` : 1-200 (défaut 20)

**Filtres hardcodés non-contournables** :
- `allow_<pool> = TRUE`
- `intimacy_level = 'public'` (jamais `prive` ni `never_publish` via ce endpoint)
- Si `query_embedding` fourni : `embedding IS NOT NULL`

**Réponse** :
```json
{
  "results": [
    {
      "id": 142,
      "similarity": 0.873,
      "url_thumb": "/media/...",
      "url_full": "/media/...",
      "thematic_tags": ["plage", "parasol en paille"],
      "people_ids": ["caroline"]
    }
  ],
  "filters_applied": {
    "pool": "pdc_vantour",
    "intimacy": "public",
    "exclude_recently_published_days": 30
  }
}
```

`similarity` n'est présent que si `query_embedding` était fourni.

---

## Banques d'images

### `GET /api/stock-photos/search`

Agrège Pexels, Pixabay et Unsplash. Les images **ne sont jamais stockées localement** — on retourne les URLs et l'attribution.

**Query params** :
- `q` : OBLIGATOIRE, 1-100 chars
- `limit` : 1-30 (défaut 8)
- `source` : `pexels|pixabay|unsplash|all` (défaut `all`)

**Réponse** :
```json
{
  "query": "bacalhau",
  "count": 24,
  "available_providers": {
    "pexels": true,
    "pixabay": true,
    "unsplash": false
  },
  "results": [
    {
      "id": "pexels:1234567",
      "source": "pexels",
      "url_full": "https://images.pexels.com/photos/.../large.jpg",
      "url_thumb": "https://images.pexels.com/photos/.../medium.jpg",
      "width": 1920,
      "height": 1280,
      "photographer": "Jane Doe",
      "attribution": "Photo by Jane Doe on Pexels",
      "source_url": "https://www.pexels.com/photo/..."
    }
  ]
}
```

**Configuration** : les clés API sont à renseigner dans `Settings → Services externes` (chiffrées en base). Sans clé, le provider est silencieusement skippé.

---

## Génération threads

### `POST /api/generate-thread`

Génère un thread complet via OpenAI **avec photos auto-attachées** (médiathèque locale + fallback stock si configuré).

**Body** :
```json
{
  "instructions": "Top 10 des plats portugais, 1 plat par segment, 280 chars max",
  "source_url": null,
  "persona_id": 1,
  "account_id": 5,
  "platforms": ["twitter", "threads"],
  "pool": "pdc_vantour"
}
```

- `instructions` OU `source_url` (l'un des deux requis)
- `persona_id` ou `account_id` (pour résoudre la persona)
- `pool` : optionnel, défaut `pdc_vantour`. Détermine dans quel pool de la médiathèque chercher les photos.

**Comportement** :
1. Le LLM rédige le thread + propose `photo_keywords` (2-4 mots par segment)
2. Pour chaque segment **sans média** :
   - **Match strict** : cherche une photo dont les tags contiennent TOUS les keywords
   - **Match large** : sinon, au moins UN keyword
   - **Fallback stock** (si `Settings.stock_photos_auto_fallback = true`) : appelle Pexels/Pixabay/Unsplash et attache la 1ère trouvée
3. Évite de réutiliser une photo dans 2 segments du même thread
4. Exclut les photos publiées dans les 30 derniers jours (média locale uniquement)

**Réponse** :
```json
{
  "title": "10 plats portugais à connaître",
  "segments": [
    {
      "position": 1,
      "content_fr": "Le bacalhau à brás, plat star...",
      "platform_contents": {
        "twitter": "Le bacalhau à brás 🇵🇹...",
        "threads": "Le bacalhau à brás..."
      },
      "photo_keywords": ["bacalhau", "morue", "plat portugais"],
      "media": [
        {
          "type": "image",
          "url": "https://images.pexels.com/photos/.../large.jpg",
          "thumbnail_url": "https://images.pexels.com/photos/.../medium.jpg",
          "source": "pexels",
          "attribution": "Photo by Jane Doe on Pexels",
          "external": true,
          "auto_attached": true
        }
      ]
    }
  ],
  "compiled": { "facebook": "...", "telegram": "..." }
}
```

Les photos `external: true` sont des URLs externes (Pexels/Pixabay/Unsplash). Les photos locales ont `url` qui commence par `/media/`.

### `POST /threads/generate-from-url` (web, session-auth)

Même comportement, depuis l'éditeur web. Body : `source_url`, `persona_id`, `accounts[]`, `pool` (optionnel), `hook_category_id` (optionnel), `context_instructions` (optionnel).

---

## Tracking publications

### `POST /api/media/{id}/mark-published`

Trace une publication manuelle. **Pas nécessaire** côté pipeline Mac : RS-Max appelle automatiquement le tracker en interne après chaque publication réussie via `MediaPublicationTracker`.

**Body** :
```json
{
  "post_id": 456,
  "thread_segment_id": null,
  "post_platform_id": 789,
  "external_url": "https://threads.net/...",
  "published_at": "2026-04-25T14:30:00Z",
  "context": "thread:bacalhau-portugal"
}
```

À utiliser uniquement si tu publies une photo **hors RS-Max** et veux la marquer comme déjà utilisée pour le filtre `exclude_recently_published_days`.

---

## Garde-fous

Implantés en dur, non-contournables côté code :

1. **`pool` requis** sur `/api/media/search` → 422 si absent
2. **Mamawette est rejeté** sur `/search` (cloisonnement strict). Les photos mamawette ne peuvent être trouvées que via la médiathèque web (`/media`), jamais via l'API.
3. **`intimacy_level = 'public'`** appliqué dans `/search`. Les `prive` ne sortent **jamais** via l'API, même si `allow_*=true`.
4. **`never_publish`** est un kill-switch global qui exclut la photo de toute recherche, partout.
5. **Idempotence par phash** sur `/ingest` → pas de doublon possible.
6. **Filtres `allow_<pool>=true`** hardcodés (pas de query parameter pour les contourner)
7. **Audit log** de chaque recherche dans `storage/logs/laravel.log` : token, pool, tags, IDs retournés.

---

## Workflow recommandé pour génération en masse

```
                       ┌──────────────────────────────────────────┐
                       │ Programmation cron / job externe         │
                       │  - prépare une liste de sujets           │
                       │  - choisit la persona, le pool, la date  │
                       └──────────────────────────────────────────┘
                                        │
                                        ▼
                       ┌──────────────────────────────────────────┐
                       │ POST /api/generate-thread                │
                       │  - LLM rédige + propose keywords         │
                       │  - RS-Max auto-attache des photos        │
                       │    (médiathèque OU stock photos)         │
                       └──────────────────────────────────────────┘
                                        │
                                        ▼
                       ┌──────────────────────────────────────────┐
                       │ POST /api/threads (création + scheduling)│
                       │  - relaie le résultat tel quel           │
                       │  - planifie à la date voulue             │
                       └──────────────────────────────────────────┘
                                        │
                                        ▼
                       ┌──────────────────────────────────────────┐
                       │ Job queue Laravel publie au moment voulu │
                       │  - photos locales : URL signée 4h        │
                       │  - photos externes : passe l'URL telle   │
                       │    quelle aux adapters compatibles       │
                       │  - mark-published auto via le tracker    │
                       └──────────────────────────────────────────┘
```

**Coût indicatif pour 1000 threads de 5 segments** :
- gpt-4o-mini (rédaction + keywords) : ~0.85 $
- Stock photos : gratuit (limites raisonnables des APIs)
- **Total : sous 1 $ pour 5000 segments**
