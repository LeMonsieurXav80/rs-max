# Catalogue média — Architecture interne

Documentation technique du fonctionnement interne de l'analyse, l'indexation et l'auto-attach de photos. Pour la référence des endpoints publics, voir [media-catalog-api.md](media-catalog-api.md). Pour la genèse du projet et les décisions, voir [media-catalog-plan.md](media-catalog-plan.md).

---

## Vue d'ensemble

Trois chemins permettent qu'une photo finisse correctement taguée dans la médiathèque :

```
┌─────────────────────────┐    ┌─────────────────────────┐    ┌─────────────────────────┐
│ A. Pipeline Mac local   │    │ B. Upload web RS-Max    │    │ C. Photo legacy         │
│  - Ollama qwen2.5vl     │    │  - User upload sans tag │    │    (déjà en base avant  │
│  - sentence-transformers│    │  - allow_*=false        │    │     le système de tags) │
│  - phash, embedding CLIP│    │  - pending_analysis=T   │    │  - dans /media/         │
│                          │    │                          │    │    unclassified         │
│  POST /api/media/ingest │    │  → Vue "Photos à        │    │  POST /api/media/{id}/  │
│  → photo arrive taguée  │    │     classer"             │    │    enrich (Mac --legacy)│
└─────────────────────────┘    └─────────────────────────┘    └─────────────────────────┘
                                          │                              │
                                          └──────┬───────────────────────┘
                                                 ▼
                                  ┌──────────────────────────────┐
                                  │ Photo taguée + classée       │
                                  │ (allow_*=true, intimacy set) │
                                  │   → utilisable dans search   │
                                  │   → utilisable en auto-attach│
                                  └──────────────────────────────┘
```

---

## A. Pipeline Mac local (offline)

Localisation du script : `/Volumes/Samsung_T5/DEV/Scripts/analyse-images.py`. Run local sur le Mac de Xavier, jamais déployé sur le VPS.

### Stack
- **Ollama** + `qwen2.5vl:7b` — modèle vision local (≈ 7B paramètres, gratuit)
- **sentence-transformers** + `clip-ViT-B-32` — embedding 512-dim sur GPU MPS Apple Silicon
- **imagehash** — phash perceptuel pour la dédup
- **SQLite** local (à côté du script) — tracking par environnement (dev/prod)

### Modes
1. **`python analyse-images.py <dossier>`** — analyse + push d'un dossier
2. **`python analyse-images.py --legacy`** — rattrape les photos `pending_analysis=true` côté serveur (download + analyse + `/enrich`)
3. **`python analyse-images.py --init`** — config interactive (URLs API, tokens)

### Questions par dossier (mode interactif)
Posées au lancement, **une seule fois pour tout le dossier** :
1. Contexte / lieu (ex: `Albufeira - Algarve - Portugal - 2004`)
   - Sert à enrichir le prompt Ollama, **non envoyé au serveur** (redondant avec les tags)
2. Pool (1=PdC/Vantour, 2=Wildycaro, 3=Mamawette, 4=Aucun)
   - Détermine `allow_wildycaro` / `allow_pdc_vantour` / `allow_mamawette`
   - L'`intimacy_level` est dérivé : `prive` pour Mamawette, `public` ailleurs
3. Personnes attendues dans les photos (csv : `caroline, xavier`)
   - Aide Ollama à compter les personnes (le modèle 7B ne sait pas reconnaître des visages, on lui donne le contexte)
4. **Lieu structuré** (3 questions séparées) : ville / région / pays
   - Stockés dans les colonnes dédiées `city`, `region`, `country` (depuis Avr 2026)
   - Permettent filtrage et autocomplete UI sans dépendre du free-text des tags
5. **Marques / partenaires** (csv : `Decathlon, Quechua`)
   - Stockées dans la colonne JSON `brands` (depuis Avr 2026)
   - Dédup case-insensitive côté serveur, casse d'origine préservée
6. **Événement** (facultatif, ex: `Voyage Portugal 2004`)
   - Stocké dans la colonne `event`
7. Tags additionnels du dossier (csv libre)
   - Appliqués à toutes les photos du batch en plus des tags générés par l'IA

### Date de prise de vue (taken_at)
Lue automatiquement depuis l'EXIF de chaque photo via `read_exif_taken_at()` :
- Tag prioritaire : `36867` (DateTimeOriginal)
- Fallbacks : `36868` (DateTimeDigitized), `306` (DateTime)
- Format EXIF `YYYY:MM:DD HH:MM:SS` converti en `YYYY-MM-DD HH:MM:SS` ISO
- Si EXIF absent ou corrompu : `taken_at` reste `null`, pas d'erreur

L'UI web affiche la date sous forme « mois année » (ex: `juillet 2004`) pour la lisibilité.

### Prompt Ollama (extrait)

Le prompt est conçu pour que **tout** ce que l'IA voit devienne un tag exploitable :

```
RÈGLES POUR thematic_tags (15 à 25 tags, tous en minuscules) :
- objets visibles (parasol en paille, chaise longue bleue, ...)
- couleurs dominantes (bleu, blanc, ocre)
- matières / textures (sable fin, pierre, paille)
- ambiance / mood (calme, joyeux, romantique)
- lumière (plein soleil, golden hour)
- cadre / lieu (plage, ville, intérieur, falaises)
- architecture (bâtiments blancs, façade ancienne)
- activité (lecture, baignade, repos)
- composition (vue large, gros plan)

person_count : nombre exact (0, 1, 2, 3+)
```

Le LLM répond en JSON strict avec format Ollama natif (`format: 'json'`).

### Normalisation des tags (Python + serveur, double safety)

Les tags malformés sont **atomisés** automatiquement :
- `"couleurs dominantes: bleu, blanc, ocre"` → split sur `:` puis `,` → `["bleu", "blanc", "ocre"]`
- `"chaise longue bleue"` → conservé (multi-mot OK)
- Lowercase, trim, dedup case-insensitive, max 60 chars/tag

Implémentation : `normalize_tags()` Python (script), `MediaApiController::normalizeTags()` serveur (en safety net sur ingest/enrich/validate).

### Stockage SQLite local
```sql
photos_analyse (
  id, local_path UNIQUE, phash,
  description_fr (vide — abandonné),
  thematic_tags JSON, embedding JSON, embedding_model,
  people_ids, pool_suggested, intimacy_level,
  allow_wildycaro, allow_pdc_vantour, allow_mamawette,
  source_context, folder_name, analyzed_at,

  -- Métadonnées structurées (Avr 2026)
  city, region, country, brands JSON, event, taken_at,

  -- Tracking par environnement
  pushed_to_dev, vps_id_dev, vps_filename_dev, vps_url_full_dev, pushed_at_dev,
  pushed_to_prod, vps_id_prod, vps_filename_prod, vps_url_full_prod, pushed_at_prod
)
```

Le script peut pousser le même catalogue vers dev ET prod sans conflit (colonnes par environnement). **Migration automatique** en cas d'évolution du schéma : `open_db()` ajoute les colonnes manquantes via `ALTER TABLE`.

### Workflow en 2 étapes (interruptible et reprenable)
1. **Analyse locale** : pour chaque image du dossier (skip si `local_path` déjà en DB), compute phash + analyze + embedding, écrit en SQLite avec `pushed_to_<env>=FALSE`.
2. **Push vers VPS** : pour chaque ligne `pushed_to_<env>=FALSE`, fait `POST /api/media/ingest` avec multipart. Le serveur dédup par phash. Une fois OK, marque `pushed_to_<env>=TRUE` + stocke `vps_id`, `vps_filename`, `vps_url_full`.

Si le réseau coupe ou tu interromps, tu relances la même commande : l'étape 1 saute les images analysées, l'étape 2 reprend là où elle s'est arrêtée.

### Gestion HTTPS auto-signé (Laravel Herd / Valet)
Le script détecte automatiquement les domaines locaux (`.local`, `.test`, `localhost`, `127.0.0.1`) et désactive `verify_ssl` pour ceux-là. Les domaines de prod gardent la vérification stricte.

---

## B. Upload web (Caroline et autres uploads ponctuels)

L'upload web (`/media/upload`) **ne demande aucun tag**. La photo arrive avec :
- `source = 'upload'`
- `pool_suggested = null`, tous les `allow_*` = `false`
- `intimacy_level = 'public'` (défaut depuis la migration)
- `pending_analysis = true`
- Compression GD : redimensionnement max 2048px, qualité adaptative entre 200-500 KB

### Vue "Photos à classer" (`/media/unclassified`)
Liste les photos avec :
```sql
WHERE allow_wildycaro = false
  AND allow_pdc_vantour = false
  AND allow_mamawette = false
  AND intimacy_level != 'never_publish'
  AND mime_type LIKE 'image/%'
```

Caroline (ou tout user) clique sur un bouton :
- **PdC / Vantour** → `allow_pdc_vantour=true`, `intimacy=public`
- **Wildycaro** → `allow_wildycaro=true`, `intimacy=public`
- **PdC + Wildycaro** → les deux à true, `intimacy=public`
- **🔒 Mamawette** → `allow_mamawette=true`, `intimacy=prive`
- **Jamais publier** → `intimacy=never_publish`

Endpoint : `POST /media/{media}/classify` (web, session-auth).

Le compteur "X photos à classer" apparaît en bandeau au-dessus de `/media` quand il y a des photos en file d'attente.

---

## C. Photos legacy (mode `--legacy`)

109 photos étaient déjà en base avant le système de tags (pré-Feb 2026). Le script Mac peut les rattraper :

```bash
python analyse-images.py --legacy --limit 50
```

Pour chaque photo `pending_analysis=true` retournée par `GET /api/media/pending-analysis` :
1. Télécharge le fichier depuis `/media/<filename>` (pas de stockage permanent — fichier temporaire dans `Scripts/.tmp_legacy_*`)
2. Compute phash + analyze + embedding
3. `POST /api/media/{id}/enrich` avec les méta — **sans toucher aux flags `allow_*`** (la photo legacy n'a pas de contexte connu, elle reste dans "à classer" pour décision humaine)
4. Supprime le fichier temp

---

## Pools : 3 silos étanches

Décisions figées par Xavier ([cf. media-catalog-plan.md section 12](media-catalog-plan.md)) :

| Pool | Audience | Intimacy par défaut | Searchable via API ? |
|------|----------|---------------------|----------------------|
| `pdc_vantour` | Planète de Caro + Vantour | public | ✅ (intimacy=public uniquement) |
| `wildycaro` | Compte solo Caroline (coaching, sensuel) | public | ✅ (intimacy=public uniquement) |
| `mamawette` | Compte privé (lingerie, nu) | prive | ❌ (manuel-only) |

### Garde-fous techniques

```php
// app/Http/Controllers/Api/MediaApiController.php
private const ALL_POOLS = ['wildycaro', 'pdc_vantour', 'mamawette'];
private const SEARCHABLE_POOLS = ['wildycaro', 'pdc_vantour'];   // mamawette exclu
private const SAFE_INTIMACY = ['public'];                        // jamais prive en search
```

Dans le SQL de `/api/media/search` :
```sql
WHERE allow_<pool> = TRUE
  AND intimacy_level = 'public'   -- hardcoded, pas de override
  AND mime_type LIKE 'image/%'
```

Mamawette ne sort que dans la médiathèque web (`/media`), jamais via search. Cohérent avec l'usage : photos mamawette toujours publiées **manuellement** via l'UI du site privé, jamais auto-suggérées par génération de threads.

`never_publish` est un kill-switch global : la photo n'apparaît **nulle part** dans aucune recherche, peu importe les `allow_*`. Pour annuler, il faut explicitement repasser `intimacy_level` à `public` ou `prive` via `/validate`.

---

## Auto-attach photos dans la génération de threads

Implémenté dans `app/Services/ThreadContentGenerationService.php::attachPhotosByKeywords()`.

### Flow
```
LLM génère thread + photo_keywords par segment
                    │
                    ▼
   ┌──────────────────────────────────────────────┐
   │ Pour chaque segment SANS média existant :    │
   │                                                │
   │  1) Strict : tous les keywords matchent      │
   │     (whereRaw JSON_SEARCH LIKE %kw% pour     │
   │      chaque keyword en AND)                   │
   │                                                │
   │  2) Sinon, large : au moins UN keyword       │
   │     matche (orWhereRaw)                       │
   │                                                │
   │  3) Sinon, fallback stock (si setting        │
   │     stock_photos_auto_fallback = true) :     │
   │     - tente keywords[0..1] joints            │
   │     - puis keywords[0] seul                  │
   │     - prend la 1ère image trouvée            │
   │                                                │
   │  Filtres sur les matches locaux :             │
   │    allow_<pool>=TRUE                          │
   │    intimacy=public                            │
   │    mime LIKE 'image/%'                        │
   │    PAS publiée dans les 30 derniers jours    │
   │    PAS déjà attachée à un autre segment      │
   │      du même thread (dédup intra-thread)     │
   └──────────────────────────────────────────────┘
```

### Exclusion des photos récentes

Évite la répétition entre threads :
```php
$query->whereDoesntHave('publications', function ($q) {
    $q->where('published_at', '>=', now()->subDays(30));
});
```

La table `media_publications` est alimentée automatiquement par `MediaPublicationTracker` après chaque publication réussie (post ou segment de thread).

### Pool fourni au moment de la génération

L'appel `ThreadContentGenerationService::generate()` accepte un 6ème paramètre `?string $pool = null`. Sans pool, l'auto-attach est skippé (pas d'erreur). Avec pool, le filtrage `allow_<pool>=true` s'applique.

Sources du pool :
- `POST /api/generate-thread` : champ `pool` du body (défaut `pdc_vantour`)
- `POST /threads/generate-from-url` (web UI) : champ `pool` du form (défaut `pdc_vantour`)

---

## Banques d'images externes (Pexels, Pixabay, Unsplash)

`app/Services/StockPhotoService.php` agrège 3 providers gratuits.

### Caractéristiques
- **Aucune image stockée localement** — on retourne juste les URLs et l'attribution
- **Recherche en français** native (Pexels `locale=fr-FR`, Pixabay `lang=fr`, Unsplash `lang=fr`)
- **Format unifié** :
  ```php
  ['id', 'source', 'url_full', 'url_thumb', 'photographer', 'attribution', 'source_url', 'width', 'height']
  ```
- **Mélange aléatoire** dans `searchAll()` pour ne pas favoriser un provider

### Clés API
Chiffrées en base via `Setting::setEncrypted()` :
- `pexels_api_key` — Header `Authorization: <key>`, key obtenue sur pexels.com/api
- `pixabay_api_key` — query param `?key=<key>`, key obtenue sur pixabay.com/api/docs
- `unsplash_access_key` — Header `Authorization: Client-ID <key>`, **Access Key uniquement** (pas Secret Key qui est pour OAuth)

UI : `Settings → onglet "Services externes"`.

### Setting de fallback automatique
`stock_photos_auto_fallback` (boolean) : si `true`, l'auto-attach des threads va chercher dans le stock quand rien ne matche dans la médiathèque locale. Sinon, le segment reste sans média.

---

## Tracking des publications (`MediaPublicationTracker`)

`app/Services/MediaPublicationTracker.php` enregistre chaque utilisation effective d'un MediaFile dans la table `media_publications`. Hooks :
- `app/Jobs/PublishToPlatformJob.php` (post réussi)
- `app/Services/ThreadPublishingService.php` (mode segment-par-segment réussi)
- `app/Services/ThreadPublishingService.php` (mode compilé réussi)

### Comportement avec URLs externes
Le tracker matche les fichiers locaux par filename (`basename(parse_url(...))`). Pour les **photos externes** (`external: true`, URLs Pexels/Pixabay), il ne trouve pas de `MediaFile` → silence (pas d'enregistrement). C'est voulu : les photos externes ne sont pas dans notre médiathèque, on n'a pas à les tracker.

---

## Compression à l'ingest

Le `MediaApiController::ingest()` utilise le trait `App\Concerns\ProcessesImages` (le même que `MediaController::upload`). Logique :
- Redimensionne si la plus grande dimension dépasse `image_max_dimension` (Setting, défaut 2048px)
- Cherche une qualité JPEG entre `image_min_quality` (défaut 60) et 90 pour produire un fichier entre `image_target_min_kb` (200) et `image_target_max_kb` (500)
- PNG sans transparence → converti en JPEG (économie de poids)

Le fichier stocké sur le VPS est la version compressée. Le **phash et l'embedding** sont calculés côté Mac sur l'**original** (pas un souci, CLIP est robuste à la compression).

---

## Métadonnées structurées (Avr 2026)

En plus des `thematic_tags` (free-text), chaque photo a 6 champs structurés permettant filtrage exact et autocomplete UI :

| Champ | Type | Source primaire | Usage |
|-------|------|-----------------|-------|
| `city` | string nullable | Question dossier (script Mac) ou édition manuelle UI | Autocomplete, filtre |
| `region` | string nullable | Idem | Autocomplete, filtre |
| `country` | string nullable | Idem | Autocomplete, filtre |
| `brands` | JSON array | Question dossier (csv) ou édition chip-style UI | Identifier les photos d'une campagne / partenariat. Dédup case-insensitive |
| `event` | string nullable | Question dossier (facultatif) ou UI | Regrouper une série narrative (ex: "Voyage Portugal 2004") |
| `taken_at` | datetime nullable | EXIF DateTimeOriginal (auto) ou UI | Chronologie réelle, alimente la phrase "il y a X mois" en génération |

Tous éditables depuis le panneau de détail photo dans `/media` via `PATCH /media/{id}/details` (auto-save debouncé 400 ms côté UI). Datalists d'autocomplete alimentés par `GET /media/autocomplete` (valeurs distinctes en base).

---

## Compteur de publications (publication_count)

Colonne `publication_count` (int, défaut 0) sur `media_files`, dénormalisée par souci de performance (tri/affichage rapides sans JOIN).

### Alimentation

Trois points d'incrément, tous via `MediaFile::increment('publication_count')` :

1. **`MediaPublicationTracker::track()`** : appelé après chaque post / thread segment publié. C'est le chemin par défaut de toute publication interne RS-Max.
2. **`MediaApiController::markPublished()`** : pour publications hors RS-Max marquées via API.
3. **Backfill migration** : la migration `add_location_brands_to_media_files` initialise `publication_count = COUNT(*) FROM media_publications WHERE media_file_id = ?` au moment du déploiement, donc les photos déjà publiées avant l'ajout de la colonne ont leur compteur à jour.

### Utilisation

- **Auto-attach threads** (`ThreadContentGenerationService::attachPhotosByKeywords`) : les requêtes de match strict ET large utilisent `orderBy('publication_count')->inRandomOrder()`. Les photos jamais publiées sortent en priorité, puis celles avec 1 pub, etc. Au sein d'un même `publication_count`, ordre aléatoire pour varier.
- **`/api/media/search` mode mots-clés** (sans embedding) : même tri.
- **`/api/media/search` mode embedding** : tri par similarité cosine inchangé (publication_count ignoré).
- **UI médiathèque** : compteur affiché sur la photo sélectionnée pour visibilité humaine.

### Garde-fous existants conservés

Le filtre `exclude_recently_published_days` (par défaut 30 jours pour `pdc_vantour`) reste actif. Il opère via `whereDoesntHave('publications', ...)` et ne dépend pas de `publication_count`. Les deux mécanismes sont complémentaires :
- **`exclude_recently_published_days`** = "n'utilise PAS cette photo si elle a été publiée récemment"
- **`publication_count` ASC** = "à choix égal, préfère celle qu'on a moins utilisée historiquement"

---

## Migrations Laravel

| Migration | Effet |
|-----------|-------|
| `2026_04_25_100000_enrich_media_files_for_catalog` | Ajoute 15 colonnes IA + flags pool sur `media_files` (description_fr, thematic_tags, embedding, allow_*, intimacy_level, phash, pending_analysis, etc.) + 3 index. |
| `2026_04_25_100001_create_media_publications_table` | Crée `media_publications` (FK vers `media_files`, `posts`, `thread_segments`, `post_platform`). |
| `2026_04_25_110000_add_mamawette_pool_and_simplify_intimacy` | Ajoute `allow_mamawette`. Supprime `semi_prive` de l'enum (les photos `semi_prive` migrent vers `public`). Refait l'index pool avec mamawette. |
| `2026_04_25_144421_add_parent_id_to_media_folders_table` | Ajoute `parent_id` (FK self) sur `media_folders` pour la hiérarchie de dossiers. |
| `2026_04_25_160000_add_location_brands_to_media_files` | Ajoute `city`, `region`, `country`, `brands` (JSON), `event`, `taken_at`, `publication_count` sur `media_files`. 4 index. Backfill `publication_count` depuis `media_publications`. |

Toutes les migrations gèrent SQLite + MySQL (les `MODIFY COLUMN` MySQL-specific sont gardés derrière un `if (DB::connection()->getDriverName() === 'mysql')` pour ne pas casser les tests in-memory).

---

## Ordre d'exécution global

Quand le pipeline complet tourne (génération + auto-attach + publication), voici l'ordre des appels :

```
1. POST /api/generate-thread
   │
   ├─ ThreadContentGenerationService::generate($url, $persona, $platforms, ..., $pool)
   │  ├─ ArticleFetchService->fetch()       (lecture du blog)
   │  ├─ Http::post('openai.com/...')        (rédaction du thread + photo_keywords)
   │  ├─ normalizeResponse(...)              (parse JSON, extrait photo_keywords par segment)
   │  └─ attachPhotosByKeywords(&$segments, $pool)
   │     ├─ MediaFile::query()->whereRaw(JSON_SEARCH...)  (match strict)
   │     ├─ MediaFile::query()->whereRaw(JSON_SEARCH...)  (match large si strict KO)
   │     └─ StockPhotoService->searchAll(...)             (fallback stock si setting ON)
   │
2. POST /api/threads + scheduling                          (côté caller)
   │
3. Job queue Laravel : PublishToPlatformJob (ou ThreadPublishingService)
   │
   ├─ Adapter->publish($account, $content, $media)
   │
   └─ MediaPublicationTracker->track(...)                   (enregistre dans media_publications)
      → impacte le filtre "exclude_recently_published_days" du PROCHAIN auto-attach
```
