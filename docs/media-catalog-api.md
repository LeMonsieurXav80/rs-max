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
  "description_fr": "Plage d'Albufeira...", // description FR générée par le LM local
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
  "source_context": "import batch 2004",   // texte libre, contexte d'origine
  "folder_path": "PdC/Espagne_2025",

  // Champs structurés (Avr 2026)
  "city": "Albufeira",
  "region": "Algarve",
  "country": "Portugal",
  "brands": ["Decathlon", "Quechua"],      // tableau, dédup case-insensitive côté serveur
  "event": "Voyage Portugal 2004",         // string libre, facultatif
  "taken_at": "2004-07-15 14:30:00"        // EXIF DateTimeOriginal, formats ISO acceptés
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

### `GET /api/media/{id}`

Retourne **tous les champs** d'une photo : techniques (mime, size, dimensions, urls), IA (description, tags, embedding model, people), structurés (city/region/country/brands/event/taken_at), classification (allow_*, intimacy_level, pool_suggested), tracking (publication_count, ingested_at, pending_analysis), et dossier rattaché.

**Query params** :
- `include_embedding` : `1` pour inclure le vecteur (~512 floats). Défaut : omis (payload léger).

**Réponse 200** :
```jsonc
{
  "id": 142,
  "filename": "20260425_143022_X8aB3kLm.jpg",
  "original_name": "IMG_5421.jpg",
  "mime_type": "image/jpeg",
  "size": 328000,
  "size_human": "320 KB",
  "width": 2048,
  "height": 1365,
  "is_image": true,
  "is_video": false,
  "url_full": "/media/20260425_143022_X8aB3kLm.jpg",
  "url_thumb": "/media/20260425_143022_X8aB3kLm.jpg",   // route('media.thumbnail', ...) si vidéo

  "description_fr": "Plage d'Albufeira au coucher de soleil...",
  "thematic_tags": ["plage", "parasol en paille", "calme"],
  "embedding_model": "clip-ViT-B-32",
  "pool_suggested": "pdc_vantour",
  "allow_wildycaro": false,
  "allow_pdc_vantour": true,
  "allow_mamawette": false,
  "intimacy_level": "public",
  "people_ids": ["caroline"],

  "city": "Albufeira",
  "region": "Algarve",
  "country": "Portugal",
  "brands": ["Decathlon", "Quechua"],
  "event": "Voyage Portugal 2004",
  "taken_at": "2004-07-15T14:30:00+00:00",

  "folder_id": 7,
  "folder": { "id": 7, "name": "Espagne_2025", "slug": "espagne-2025", "path": "PdC / Espagne_2025" },

  "source": "mac_pipeline",
  "source_url": null,
  "source_path": "/Volumes/T5/Photos/...",
  "source_context": null,
  "phash": "a1b2c3d4...",
  "ai_metadata": { "person_count": 2 },

  "pending_analysis": false,
  "ingested_at": "2026-04-25T09:35:39+00:00",
  "publication_count": 3,
  "created_at": "2026-04-25T09:35:39+00:00",
  "updated_at": "2026-04-25T09:35:39+00:00"

  // "embedding": [...] présent uniquement si ?include_embedding=1
}
```

**Garde-fou** : pas de restriction de pool sur cet endpoint (même politique que `/validate` et `/enrich`). Si tu as l'id, tu as déjà été autorisé en amont. Les filtres de pool ne s'appliquent qu'à `/search` (qui fait de la découverte).

**Erreurs** : 404 si `{id}` n'existe pas.

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

**Body JSON** : mêmes champs que `/ingest` sans `file`. `phash` optionnel, `embedding` requis. Accepte également `description_fr`, `source_context`, et tous les champs structurés (`city`, `region`, `country`, `brands[]`, `event`, `taken_at`). Les clés absentes ne touchent pas la valeur existante ; les clés présentes avec `null` effacent (pour `city/region/country/event/taken_at/brands`).

```json
{ "id": 87, "status": "enriched", "pending_analysis": false }
```

### `POST /api/media/{id}/validate`

Corrige les flags / tags / personnes / champs structurés d'une photo après coup.

**Body JSON** :
```json
{
  "allow_wildycaro": true,
  "allow_pdc_vantour": false,
  "allow_mamawette": false,
  "intimacy_level": "public",
  "thematic_tags_override": ["vanlife", "espagne"],
  "people_ids_override": ["caroline"],
  "city": "Albufeira",
  "region": "Algarve",
  "country": "Portugal",
  "brands": ["Decathlon"],
  "event": "Voyage Portugal 2004",
  "taken_at": "2004-07-15"
}
```

Tous les champs sont optionnels. Les champs structurés (city/region/country/event/brands/taken_at) sont remplaçables individuellement et acceptent `null` pour effacer.

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
```jsonc
{
  "results": [
    {
      "id": 142,
      "similarity": 0.873,                    // présent uniquement en mode embedding
      "url_thumb": "/media/...",
      "url_full": "/media/...",
      "mime_type": "image/jpeg",
      "width": 2048,
      "height": 1365,
      "description_fr": "Plage d'Albufeira...",
      "thematic_tags": ["plage", "parasol en paille"],
      "people_ids": ["caroline"],
      "city": "Albufeira",
      "region": "Algarve",
      "country": "Portugal",
      "brands": ["Decathlon"],
      "event": "Voyage Portugal 2004",
      "taken_at": "2004-07-15T14:30:00+00:00",
      "folder_id": 7,
      "publication_count": 3
    }
  ],
  "filters_applied": {
    "pool": "pdc_vantour",
    "intimacy": "public",
    "exclude_recently_published_days": 30
  }
}
```

`similarity` n'est présent que si `query_embedding` était fourni. Pour le payload **complet** d'une photo (folder, ai_metadata, source, allow_*, intimacy, phash, ingested_at, etc.), faire ensuite `GET /api/media/{id}`.

**Tri par moins-publiées d'abord** (depuis Avr 2026) : en mode mots-clés (sans embedding), les résultats sont triés par `publication_count` croissant puis aléatoirement à publication_count égal. Évite de servir toujours les mêmes photos en auto-attach. En mode embedding, le tri reste par similarité cosine.

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

**Réponse 201** :
```json
{
  "id": 17,
  "media_file_id": 142,
  "published_at": "2026-04-25T14:30:00+00:00",
  "publication_count": 3
}
```

L'appel incrémente `media_files.publication_count` (cache dénormalisé alimentant le tri auto-attach et l'affichage UI). À utiliser uniquement si tu publies une photo **hors RS-Max** et veux la marquer comme déjà utilisée pour le filtre `exclude_recently_published_days`.

---

## Endpoints web (session-auth, pas Sanctum)

### `PATCH /media/{id}/details`

Édite les champs structurés d'une photo depuis l'UI. Chaque clé présente est appliquée ; clé absente = pas de changement.

**Body** :
```json
{
  "city": "Albufeira",
  "region": "Algarve",
  "country": "Portugal",
  "brands": ["Decathlon", "Quechua"],
  "event": "Voyage Portugal 2004",
  "taken_at": "2004-07-15"
}
```

**Réponse** :
```json
{
  "id": 142,
  "city": "Albufeira",
  "region": "Algarve",
  "country": "Portugal",
  "brands": ["Decathlon", "Quechua"],
  "event": "Voyage Portugal 2004",
  "taken_at": "2004-07-15",
  "taken_at_label": "juillet 2004"
}
```

### `GET /media/autocomplete`

Valeurs distinctes existantes pour les champs structurés (alimente les `<datalist>` de l'UI).

**Réponse** :
```json
{
  "cities": ["Albufeira", "Lisbonne", "Madrid"],
  "regions": ["Algarve", "Andalousie"],
  "countries": ["Portugal", "Espagne"],
  "brands": ["Decathlon", "Quechua"]
}
```

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
