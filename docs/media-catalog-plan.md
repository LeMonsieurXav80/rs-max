# Plan : Catalogue d'images avec recherche sémantique dans RS-Max

## 1. Contexte et objectif

Xavier a une grosse bibliothèque de photos et vidéos éparpillée sur son disque externe T5, utilisée pour créer du contenu sur réseaux sociaux (threads avec une image par post) et accessoirement pour des articles WordPress.

**Objectif** : transformer RS-Max en backend d'un catalogue visuel interrogeable sémantiquement ("trouve-moi une photo de van au coucher de soleil avec Caroline"), avec des garde-fous stricts pour éviter la contamination entre personas.

## 2. Architecture globale du pipeline

```
Mac M4 Pro (Xavier)              VPS (RS-Max)
-----------------                ------------
analyse-images.py                API /api/media/ingest
  - parcourt un dossier          (reçoit image + métadonnées JSON)
  - lit _context.yaml               |
  - génère description             v
    (Ollama qwen2.5vl)          Storage (volume Docker ou MinIO)
  - génère embedding CLIP
    (sentence-transformers)         |
  - extrait EXIF                    v
  - stocke dans analyse.sqlite   MySQL (media_files enrichi
                                      + embeddings JSON)
  - push vers RS-Max                 |
                                     v
                                Recherche sémantique
                                /api/media/search
                                     |
                                     v
                                Claude/scripts génération
                                de threads et posts
```

**Point clé** : tout le travail ML est fait sur le Mac (embeddings CLIP pré-calculés), le VPS ne fait plus que stocker et servir. Pas de ML sur le VPS. Tu reçois des données prêtes à indexer.

## 3. Existant dans RS-Max à réutiliser

Déjà en place, à ne pas recréer :

- `MediaFile` + `MediaFolder` modèles et migrations
- `MediaController`, `MediaFolderController`, `MediaStudioController`
- `AiAssistController::generateFromMedia`
- `AccountGroup` (groupes de comptes, utilisable comme mapping vers les pools)
- `Persona`, `Post`, `Thread`, `ThreadSegment`, `PostPlatform`

## 4. Règles métier critiques (sécurité)

Xavier a 2 pools de contenu photos strictement étanches. Une photo qui fuite entre pools est un incident majeur.

| Pool | Cibles | Contenu |
|------|--------|---------|
| `wildycaro` | comptes solo Caroline (coaching, sensuel) | Caroline seule en contexte coaching/sensuel |
| `pdc_vantour` | Planete de Caro (FR) + Vantour (multilingue) | Duo, van, vanlife, paysages, voyages |

Règles figées par Xavier :

- Xavier seul sur la photo → `pdc_vantour` uniquement, jamais `wildycaro`
- Duo Xavier+Caroline → `pdc_vantour` uniquement, jamais `wildycaro`
- Photo de Caroline seule hors coaching : à trancher (cas encore ouvert, cf. section 9)

**Principe de conception** : default-deny, explicit allow. Par défaut, une photo n'est publiable nulle part. Il faut explicitement la flagger pour chaque pool.

Garde-fous techniques à implémenter :

- Flags bool explicites sur `media_files` : `allow_wildycaro` et `allow_pdc_vantour`, les deux `false` par défaut.
- Le paramètre `pool` de l'API search est OBLIGATOIRE. Pas de requête sans pool. Une requête sans pool retourne 400.
- Audit log : chaque requête search loggue `{persona, query, returned_ids}` pour pouvoir tracer une fuite éventuelle.
- Champ `intimacy_level` : valeurs `public | semi_prive | prive | never_publish`. Les images `prive` et `never_publish` ne sortent jamais de l'API search, même si les `allow_*` sont à `true`. Double garde-fou.

## 5. Schéma DB à ajouter

### Migration A : enrichir `media_files`

Ajouter colonnes :

```
description_fr         TEXT NULL
thematic_tags          JSON NULL        # ["vanlife","randonnee",...]
embedding              JSON NULL        # vecteur CLIP ~512 floats
embedding_model        VARCHAR(50) NULL # "clip-ViT-B-32" par ex.
pool_suggested         VARCHAR(32) NULL # "wildycaro"|"pdc_vantour"|"both"|"none"
allow_wildycaro        BOOLEAN NOT NULL DEFAULT FALSE
allow_pdc_vantour      BOOLEAN NOT NULL DEFAULT FALSE
intimacy_level         VARCHAR(32) NOT NULL DEFAULT 'semi_prive'
                       # public|semi_prive|prive|never_publish
people_ids             JSON NULL        # ["caroline","xavier"] ou []
ai_metadata            JSON NULL        # tout le reste du JSON généré par le Mac
                                        # (scene, lighting, composition, etc.)
source_context         TEXT NULL        # le contexte hérité du _context.yaml
source_path            VARCHAR(512) NULL# chemin d'origine sur le T5, pour traçabilité
phash                  VARCHAR(64) NULL # hash perceptuel pour dédup
ingested_at            TIMESTAMP NULL
```

Index :

- `(allow_wildycaro, allow_pdc_vantour, intimacy_level)` pour filtrage rapide
- `phash` pour dédup

### Migration B : `media_publications`

Table dédiée pour tracer l'historique de publication :

```
id                  BIGINT PK
media_file_id       FK media_files
post_id             FK posts NULL         # si posté via un Post RS-Max
thread_segment_id   FK thread_segments NULL # si utilisé dans un segment de thread
post_platform_id    FK post_platform NULL # plateforme précise
external_url        TEXT NULL             # URL de la publication finale
published_at        TIMESTAMP
context             VARCHAR(255) NULL     # contexte libre (ex: "article blog PdC import")
```

Permet la requête "image déjà publiée sur Threads dans les 30 derniers jours ?" pour éviter les doublons dans la génération de contenu.

### Migration C (optionnelle) : `media_people`

Si Xavier veut formaliser les personnes identifiables (pour l'instant `people_ids` en JSON suffit).

## 6. Endpoints API à créer

Ajouter un nouveau namespace `app/Http/Controllers/Api/Media` avec Sanctum auth (il y a déjà `personal_access_tokens` dans les migrations, cf. `2026_04_21_104054_create_personal_access_tokens_table.php`).

### `POST /api/media/ingest`

Upload une image et ses métadonnées pré-calculées.

**Request** (multipart) :

- `file` : binaire image
- `metadata` : JSON avec tous les champs issus de l'analyse Mac :

```json
{
  "description_fr": "...",
  "thematic_tags": ["vanlife", "golden-hour"],
  "embedding": [0.12, -0.34, ...],
  "embedding_model": "clip-ViT-B-32",
  "pool_suggested": "pdc_vantour",
  "people_ids": ["caroline", "xavier"],
  "intimacy_level": "public",
  "ai_metadata": { "scene": "...", "lighting": "...", ... },
  "source_context": "Roadtrip Espagne août 2025",
  "source_path": "/T5/Photos/PdC/Espagne_2025/IMG_1234.jpg",
  "phash": "a1b2c3d4...",
  "folder_path": "PdC/Espagne_2025"
}
```

**Comportement** :

- Vérifie dédup par `phash` → si match, renvoie 200 avec l'ID existant (idempotence).
- Crée/récupère le `MediaFolder` selon `folder_path`.
- Les flags `allow_wildycaro` / `allow_pdc_vantour` NE sont PAS posés par cet endpoint. Ils restent `false`. Le pool suggéré est stocké dans `pool_suggested` uniquement.

**Response** : `{ id, status: "created"|"exists", url_thumb, url_full }`

### `POST /api/media/{id}/validate`

Valide une image (pose les vrais flags `allow_*`). Appelé après review humaine dans l'UI RS-Max.

**Request** :

```json
{
  "allow_wildycaro": false,
  "allow_pdc_vantour": true,
  "intimacy_level": "public",
  "thematic_tags_override": ["vanlife", "espagne", "coucher-soleil"]
}
```

Pas d'autre endpoint qui modifie `allow_*`. C'est la seule façon de rendre une image publiable.

### `GET /api/media/search`

Recherche sémantique.

**Params** :

- `pool` : OBLIGATOIRE, valeurs `wildycaro` ou `pdc_vantour`. Absence → 400.
- `query` : string de recherche libre (sera embeddée côté RS-Max ou côté client)
- `query_embedding` : alternative, vecteur déjà pré-calculé par le client (évite d'embedder la query côté RS-Max si le Mac s'en occupe)
- `tags` : tableau de tags à filtrer (AND)
- `people` : tableau de personnes à filtrer
- `exclude_recently_published_days` : int, exclut les images publiées dans les N derniers jours (défaut 30 pour `pool=pdc_vantour`, 0 pour `wildycaro`)
- `max_intimacy` : défaut `public` (ne jamais retourner `prive` ou `never_publish` via cet endpoint)
- `limit` : défaut 20

**Filtres toujours appliqués** (hardcodés, non contournables) :

```sql
WHERE allow_<pool> = TRUE
  AND intimacy_level IN ('public', 'semi_prive')   -- jamais prive/never_publish
  AND deleted_at IS NULL
```

**Response** : liste triée par similarité (cosine distance sur embeddings) :

```json
{
  "results": [
    { "id": 123, "similarity": 0.87, "url_thumb": "...", "url_full": "...",
      "description_fr": "...", "thematic_tags": [...], "people_ids": [...] }
  ],
  "query_embedded": true,
  "filters_applied": { "pool": "pdc_vantour", "intimacy": "public|semi_prive" }
}
```

**Implémentation de la similarité** : pour v1, brute force en PHP avec cosine similarity (OK jusqu'à ~20k images). Passer à Qdrant ou pgvector quand volume dépasse. Benchmarker avant d'optimiser.

### `POST /api/media/{id}/mark-published`

Appelé quand une image est effectivement publiée (manuellement ou via `PublishingService` après succès).

**Request** :

```json
{
  "post_id": 456,
  "thread_segment_id": null,
  "post_platform_id": 789,
  "external_url": "https://threads.net/...",
  "published_at": "2026-04-23T14:30:00Z"
}
```

Crée une ligne dans `media_publications`. Permet ensuite à `/api/media/search` de filtrer les images déjà utilisées récemment.

### Intégration dans le workflow existant RS-Max

- `PublishingService` : après succès de publication, appeler `mark-published` automatiquement pour toute image attachée au post.
- `AiAssistController::generateFromMedia` : peut désormais utiliser `/api/media/search` pour suggérer des images pertinentes à un thread en cours de génération.

## 7. Sécurité et auth

- Tous les endpoints sous Sanctum (personal access tokens, table déjà migrée).
- Le Mac de Xavier utilise un token dédié avec scope `media:ingest` et `media:read`.
- Middleware custom pour les endpoints `/api/media/*` :
  - Log de chaque requête search dans un canal dédié (`storage/logs/media-search.log`), avec `token_name + params + IDs retournés`.
  - Rate-limit raisonnable sur ingest (pour éviter erreur de script qui spamme).

## 8. Choix techniques recommandés

- **Stockage fichiers** : commencer sur volume Docker (simple). Migrer vers MinIO dans un second temps si le volume des images devient problématique. Chemin de stockage versioned dans la config pour pouvoir bouger.
- **Embeddings en base** : colonne JSON MySQL pour v1, c'est suffisant. Ne pas se lancer dans pgvector tant qu'on n'a pas un bench qui montre que c'est nécessaire.
- **Thumbnails** : générer 3 tailles (thumb 200px, medium 800px, full). Endpoint `/media/thumbnail/{id}/{size}`.
- **UI de validation** : une vue Blade simple qui liste les images avec `allow_wildycaro=false AND allow_pdc_vantour=false`, grille avec actions "valider pool X" / "rejeter" / "marquer never_publish". Priorité basse, peut venir après les endpoints.

## 9. Questions à clarifier avec Xavier avant de coder

1. **Règle pour Caroline seule hors contexte coaching** (selfie vanlife, cuisine dans le van, etc.) : pool `pdc_vantour` uniquement, ou les deux `wildycaro` + `pdc_vantour`, ou c'est le LLM sur le Mac qui décide via `pool_suggested` ? Réponse Xavier non encore donnée.
2. **Volume de départ estimé** (10k / 50k / 100k+ images) pour choisir l'approche recherche (brute force vs Qdrant).
3. **Stockage** : volume Docker RS-Max ou MinIO dès le début ?
4. **Format d'auth pour le Mac** : un token personnel Sanctum suffit ? OK avec scopes ?

## 10. Ordre d'implémentation suggéré

1. Migrations A + B (enrichir `media_files`, créer `media_publications`).
2. Endpoint `POST /api/media/ingest` avec idempotence phash.
3. Endpoint `GET /api/media/search` avec filtre pool obligatoire + brute force cosine.
4. Endpoint `POST /api/media/{id}/validate`.
5. Endpoint `POST /api/media/{id}/mark-published`.
6. UI Blade de validation.
7. Hook dans `PublishingService` pour `mark-published` auto.
8. Tester avec un jeu de 10 images uploadées depuis le Mac avant de lancer le bulk ingest.

---

## 11. Décisions prises (session du 2026-04-23)

### Architecture côté Mac
- **Stockage local** : MAMP MySQL (pas SQLite). Une DB locale `rs_max_analyse` avec une table `photos_analyse`.
- **Interface de consultation** : phpMyAdmin pour debug + farfouillage SQL ("montre-moi toutes les photos d'Espagne avec Caroline").
- **Schéma table locale** :
  ```
  photos_analyse
    id, local_path, phash, description_fr, tags_json,
    embedding_json, people_json, pool_suggested,
    intimacy_level, allow_wildycaro, allow_pdc_vantour,
    pushed_to_vps (bool), vps_id, vps_filename,
    vps_url_thumb, vps_url_full, ingested_at
  ```
- **Workflow en 2 étapes** :
  1. Analyse locale → écrit dans MAMP avec `pushed_to_vps=FALSE`
  2. Push vers VPS → API renvoie `vps_id`, `vps_filename`, URLs → script complète la ligne MAMP, passe à `TRUE`
- **Idempotence** par `phash` côté VPS (évite les doublons si on relance).
- **Reprise** : on peut interrompre/reprendre l'étape 2 à tout moment (filtre `pushed_to_vps=FALSE`).

### Lien Mac ↔ VPS (renommage de fichier)
- RS-Max renomme le fichier à l'upload (`MediaController.php:148` : `date_random.ext`). Confirmé.
- La table MAMP stocke **les deux noms** : `local_path` (chemin T5 d'origine) + `vps_filename` (nom renommé sur VPS).
- Pour afficher des vignettes : utiliser `vps_url_thumb` (marche partout, sans T5 branché). Pour ouvrir l'original : `local_path` (T5 branché requis).

### Règles de sécurité
- **YAML = source de vérité** : l'API `/api/media/ingest` pose directement `allow_wildycaro` / `allow_pdc_vantour` à partir du YAML. **PAS** d'étape de validation manuelle obligatoire.
- L'endpoint `/api/media/{id}/validate` reste utile pour **corriger** une photo après coup (changer son pool, son intimacy_level, etc.) mais n'est plus le seul moyen d'activer les `allow_*`.
- Le principe **default-deny** reste valide : si le YAML ne flagge rien, la photo n'est publiable nulle part.

### Scope v1
- **Vidéos** : hors scope. Photos uniquement.
- **Photos existantes (109 en base au 2026-04-23)** : option C hybride.
  - Maintenant : on les laisse, elles auront `allow_*=false` par défaut donc invisibles à la recherche sémantique. Elles restent utilisables via la médiathèque manuelle classique.
  - Plus tard : ajouter un endpoint `POST /api/media/{id}/enrich` + flag `--include-legacy` au script Mac pour rattraper si besoin.

### Stratégie unifiée pour les MediaFile non-pipeline
- **Migration ajoute** : `pending_analysis BOOLEAN NOT NULL DEFAULT TRUE`.
- Conséquence : tout MediaFile créé hors pipeline (uploads web, imports en masse via `MediaDownloadService`, `MediaStudioService`, `SyncMediaFilesCommand`) est automatiquement en file d'attente.
- Seul `/api/media/ingest` (pipeline Mac) pose `pending_analysis = false` car l'analyse est déjà fournie.
- Le script Mac peut faire `GET /api/media/pending-analysis` pour récupérer les photos en attente, télécharger depuis le VPS, analyser, et renvoyer l'enrichissement.

### Uploads web (UI RS-Max)
- Option C retenue :
  - Formulaire d'upload **force** le choix d'un pool (dropdown obligatoire) + intimacy_level → photo immédiatement publiable.
  - Photo marquée `pending_analysis = true` → enrichie automatiquement par le script Mac au prochain run (description + embedding).

### Infrastructure
- **VPS Coolify** : 156 Go disponibles. Volume Docker pour la v1 (pas MinIO). L'outil compresse déjà les vidéos.
- **Auth Mac** : Sanctum personal access token (table déjà migrée).

### Confirmé : recherche depuis le Mac
- Le script Python sur le Mac transforme la phrase de recherche en vecteur (CLIP), puis envoie le **vecteur** à l'API VPS. RS-Max ne fait que comparer des vecteurs (calcul simple, pas de ML).
- L'endpoint search n'accepte que `query_embedding` (array de floats), pas `query` (string). Pas de modèle ML sur le VPS.

## 12. Décisions finales (session 2026-04-25)

Les 6 questions de la section précédente sont tranchées :

1. **Volume estimé** : 6 000 à 12 000 photos. → MySQL JSON + brute force cosine en PHP. Pas de Qdrant/pgvector tant que ça tient (re-bencher si latence devient sensible).
2. **Pools** : hardcodés. 2 colonnes booléennes `allow_wildycaro` + `allow_pdc_vantour`. Si un 3ème pool arrive un jour → migration. Pas de table dédiée pour l'instant.
3. **Tags** : 1 seule colonne `thematic_tags`. Les corrections utilisateur écrasent les tags IA. Pas de double colonne ai/user.
4. **Champ `source`** : on ajoute la valeur `'mac_pipeline'` (en plus des `'upload'`, `'import'`, `'studio'` existantes) pour distinguer les photos venues du script Mac.
5. **Soft delete** : non. Suppression définitive (cohérent avec la philo "pas de baggage inutile en base").
6. **Imports en masse + uploads web** : default-deny systématique.
   - Tout import (`MediaDownloadService`, `SyncMediaFilesCommand`) crée des photos avec `allow_*=false` → invisibles à la recherche sémantique mais utilisables dans la médiathèque manuelle.
   - **L'upload web ne force PLUS le pool** (révoque l'option C de la section 11). Caroline peut uploader sans rien choisir → photo en file d'attente.
   - **Vue dédiée "Photos à classer"** dans la médiathèque : liste les photos avec les 2 `allow_*` à `false`. UI simple : choisir pool + intimacy_level → validé. Cette même vue sert aussi à rattraper les 109 photos legacy.
   - **Seul le pipeline Mac** pose les `allow_*` directement (depuis le YAML, source de vérité). Les autres chemins passent tous par la file d'attente.

---

## 13. Prochaines étapes

1. Migrations A + B (section 5) — enrichir `media_files`, créer `media_publications`.
2. Mettre à jour le modèle `MediaFile` (fillable, casts).
3. Endpoints API (section 6) dans l'ordre : `ingest` → `search` → `validate` → `mark-published`.
4. Vue Blade "Photos à classer" (section 6 + décision 6 ci-dessus).
5. Hook `mark-published` auto dans `PublishingService`.

---

## 14. Itération métadonnées structurées (session 2026-04-25 fin de journée)

Au-delà des `thematic_tags` (free-text), 6 champs structurés ajoutés à `media_files` pour permettre filtrage exact, autocomplete UI et tri historique. Plus une dénormalisation du compteur de publications.

### Champs ajoutés
- `city`, `region`, `country` : 3 colonnes string nullable séparées (au lieu d'un seul champ "lieu")
- `brands` : JSON array, dédup case-insensitive, casse d'origine préservée
- `event` : string nullable, libre, facultatif
- `taken_at` : datetime nullable, lu depuis EXIF DateTimeOriginal côté Mac
- `publication_count` : int default 0, cache dénormalisé incrémenté par `MediaPublicationTracker` et `markPublished`

### Décisions
- **Refusé** : note/rating sur les photos. Préférence pour le compteur d'utilisation comme proxy de qualité indirecte (les bonnes photos sont republiées spontanément).
- **Refusé** : champ "campagne / projet". Trop spécialisé pour le périmètre actuel.
- **Lieu décomposé** plutôt que champ unique : facilite filtrage géographique multi-niveau et autocomplete par hiérarchie.
- **Événement facultatif** : pas tous les batches en ont (ex: photos quotidiennes du van). On ne force pas.
- **Date de prise = EXIF auto, pas modification manuelle obligatoire** : si l'EXIF est absent, la photo reste sans `taken_at` plutôt que d'imposer une saisie au pipeline.
- **Compteur de pub plutôt qu'une note** : objectif = éviter la répétition, pas évaluer la qualité. Le tri "moins publiées d'abord" varie naturellement les photos sans intervention humaine.
- **Backfill obligatoire dans la migration** : sans ça, toutes les photos déjà publiées seraient à `publication_count=0` et seraient suralimentées par l'auto-attach. Sub-query corrélée sur `media_publications` au moment du déploiement.
- **Champs envoyés par le script Python** : seulement si non-vides (pas écraser un override serveur avec des null venus du pipeline).
- **UI : auto-save debouncé 400 ms** plutôt qu'un bouton "Sauver" : moins de friction pour un workflow où Caroline retouche souvent les méta après coup.

### Ce que ça change côté usage
- Auto-attach threads varie davantage : photos jamais publiées en priorité, puis aléatoire au sein d'un même compteur. Le filtre "30 jours" pré-existant reste actif (ces deux mécaniques se cumulent).
- Le pipeline Mac pose 7 questions par dossier au lieu de 4 (3 nouvelles : lieu structuré 3 inputs, marques, événement). Les tags free-text restent en plus.
- L'UI médiathèque permet d'éditer ces champs photo par photo, avec autocomplete sur les valeurs déjà saisies en base.

