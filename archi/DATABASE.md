# Base de données - Schéma et relations

## Diagramme des relations

```
users
  │
  ├── has many ──► posts
  │                  │
  │                  └── has many ──► post_platform
  │                                      │
  │                                      ├── belongs to ──► social_accounts
  │                                      ├── belongs to ──► platforms
  │                                      └── has many ──► post_logs
  │
  ├── has many ──► hashtags
  │
  └── belongs to many ──► social_accounts (via social_account_user)
                              │
                              ├── belongs to ──► platforms
                              ├── belongs to ──► personas (optionnel)
                              ├── has many ──► external_posts
                              └── belongs to many ──► rss_feeds (via rss_feed_social_account)

personas
  └── has many ──► rss_posts

rss_feeds
  ├── has many ──► rss_items
  │                  └── has many ──► rss_posts
  └── belongs to many ──► social_accounts (via rss_feed_social_account)
```

---

## Tables

### `users`

| Colonne                  | Type              | Description                          |
|--------------------------|-------------------|--------------------------------------|
| id                       | bigint PK         | Identifiant                          |
| name                     | string            | Nom du client                        |
| email                    | string (unique)   | Email de connexion                   |
| password                 | string (hashed)   | Mot de passe bcrypt                  |
| default_language         | string            | Langue par défaut (fr/en/both)       |
| auto_translate           | boolean           | Traduction auto activée              |
| openai_api_key           | text (encrypted)  | Clé API OpenAI personnelle           |
| telegram_alert_chat_id   | string (nullable) | Chat ID pour alertes Telegram        |
| default_accounts         | JSON (nullable)   | Comptes sociaux par défaut par plateforme |
| is_admin                 | boolean           | Peut voir/gérer tous les clients     |
| email_verified_at        | timestamp         | Date de vérification email           |

**Casts** : `openai_api_key` → encrypted, `auto_translate` → boolean, `is_admin` → boolean, `default_accounts` → array

---

### `platforms`

| Colonne     | Type             | Description                                |
|-------------|------------------|--------------------------------------------|
| id          | bigint PK        | Identifiant                                |
| slug        | string (unique)  | Identifiant technique (facebook, telegram) |
| name        | string           | Nom affiché                                |
| description | text (nullable)  | Description                                |
| logo_url    | string (nullable)| URL du logo                                |
| color       | string           | Couleur hex (#6366f1)                      |
| auth_type   | enum             | oauth / api_key / bot_token                |
| config      | JSON             | Champs de credentials + version API        |
| is_active   | boolean          | Plateforme disponible                      |

**Plateformes seedées** : Facebook, Twitter/X, Instagram, Telegram, Threads, YouTube

**Exemple de config** :
```json
{
  "api_version": "v21.0",
  "credential_fields": [
    {"key": "page_id", "label": "Page ID", "type": "text"},
    {"key": "access_token", "label": "Access Token", "type": "password"}
  ]
}
```

---

### `social_accounts`

| Colonne              | Type                | Description                                    |
|----------------------|---------------------|------------------------------------------------|
| id                   | bigint PK           | Identifiant                                    |
| platform_id          | FK → platforms      | Plateforme associée                            |
| platform_account_id  | string              | ID unique du compte sur la plateforme          |
| name                 | string              | Nom du compte (ex: "Page Facebook")            |
| profile_picture_url  | text (nullable)     | URL de la photo de profil                      |
| followers_count      | bigint (nullable)   | Nombre d'abonnés                               |
| followers_synced_at  | timestamp (nullable)| Dernière synchronisation des abonnés           |
| credentials          | JSON (encrypted)    | Tokens/clés API                                |
| languages            | JSON                | Langues de publication (ex: ["fr","en"])       |
| branding             | text (nullable)     | Signature à ajouter aux posts                  |
| show_branding        | boolean             | Afficher le branding                           |
| persona_id           | FK → personas (nullable) | Persona IA par défaut pour ce compte      |
| last_used_at         | timestamp (nullable)| Dernière utilisation                           |
| last_history_import_at | timestamp (nullable) | Dernier import historique                   |

**Index unique** : `[platform_id, platform_account_id]`

**Casts** : `credentials` → encrypted:array, `languages` → array, `show_branding` → boolean, `followers_synced_at` → datetime

**Note** : `is_active` est stocké dans la table pivot `social_account_user` (par utilisateur, pas global). Les comptes sont uniques par `platform_id` + `platform_account_id` et peuvent être partagés entre plusieurs utilisateurs.

---

### `social_account_user` (pivot many-to-many)

| Colonne           | Type              | Description                         |
|-------------------|-------------------|-------------------------------------|
| id                | bigint PK         | Identifiant                         |
| social_account_id | FK → social_accounts | Compte social                    |
| user_id           | FK → users        | Utilisateur lié                     |
| is_active         | boolean           | Compte actif pour cet utilisateur   |

**Index unique** : `[social_account_id, user_id]`

**Architecture** : Permet à plusieurs utilisateurs de partager le même compte social. Chaque utilisateur peut activer/désactiver un compte indépendamment.

---

### `posts`

| Colonne          | Type              | Description                          |
|------------------|-------------------|--------------------------------------|
| id               | bigint PK         | Identifiant                          |
| user_id          | FK → users        | Auteur du post                       |
| content_fr       | text              | Contenu français                     |
| content_en       | text (nullable)   | Contenu anglais (legacy, migré vers translations) |
| translations     | JSON (nullable)   | Traductions multi-langues (ex: {"en": "..."}) |
| hashtags         | string (nullable) | Hashtags                             |
| auto_translate   | boolean           | Traduction auto activée pour ce post |
| media            | JSON              | Tableau de médias                    |
| link_url         | string (nullable) | Lien à inclure                       |
| location_name    | string (nullable) | Nom du lieu (ex: "Paris, France")    |
| location_id      | string (nullable) | ID du lieu pour les APIs (Facebook, Threads) |
| source_type      | string(20)        | Origine du post : `manual` ou `rss`  |
| status           | enum              | draft/scheduled/publishing/published/failed |
| scheduled_at     | timestamp         | Date de publication programmée       |
| published_at     | timestamp         | Date de publication effective        |

**Index** : `[user_id, status]`, `scheduled_at`

**Scopes** :
- `scheduled()` → status = scheduled AND scheduled_at not null
- `readyToPublish()` → scheduled AND scheduled_at <= now()

**Format média** :
```json
[
  {
    "url": "/media/abc-123-uuid.jpg",
    "mimetype": "image/jpeg",
    "size": 245000,
    "title": "photo.jpg"
  }
]
```

---

### `post_platform` (pivot enrichi)

| Colonne           | Type              | Description                         |
|-------------------|-------------------|-------------------------------------|
| id                | bigint PK         | Identifiant                         |
| post_id           | FK → posts        | Post associé                        |
| social_account_id | FK → social_accounts | Compte de publication            |
| platform_id       | FK → platforms    | Plateforme cible                    |
| status            | enum              | pending/publishing/published/failed |
| external_id       | string (nullable) | ID du post sur la plateforme        |
| error_message     | text (nullable)   | Message d'erreur si failed          |
| published_at      | timestamp         | Date de publication sur la plateforme |
| metrics           | JSON (nullable)   | Métriques d'engagement (views, likes, comments, shares) |
| metrics_synced_at | timestamp (nullable) | Dernière synchronisation des métriques |

**Index** : `[post_id, platform_id]`

---

### `post_logs`

| Colonne           | Type              | Description                         |
|-------------------|-------------------|-------------------------------------|
| id                | bigint PK         | Identifiant                         |
| post_platform_id  | FK → post_platform| Publication concernée               |
| action            | string            | submitted/published/failed/retried  |
| details           | JSON              | Détails (external_id, error, etc.)  |

**Index** : `post_platform_id`

---

### `external_posts`

| Colonne           | Type              | Description                         |
|-------------------|-------------------|-------------------------------------|
| id                | bigint PK         | Identifiant                         |
| social_account_id | FK → social_accounts (cascade) | Compte source             |
| platform_id       | FK → platforms (cascade) | Plateforme source              |
| external_id       | string            | ID du post sur la plateforme        |
| content           | text (nullable)   | Contenu du post                     |
| media_url         | text (nullable)   | URL du média principal              |
| post_url          | text (nullable)   | URL publique du post                |
| published_at      | timestamp (nullable) | Date de publication originale    |
| metrics           | JSON (nullable)   | Métriques (views, likes, comments, shares) |
| metrics_synced_at | timestamp (nullable) | Dernière synchronisation          |

**Index unique** : `[platform_id, external_id]`

**Usage** : Stocke les posts importés depuis les plateformes (import historique). Distinct des posts créés dans RS-Max.

---

### `personas`

| Colonne             | Type              | Description                         |
|---------------------|-------------------|-------------------------------------|
| id                  | bigint PK         | Identifiant                         |
| name                | string            | Nom de la persona                   |
| description         | string (nullable) | Description courte                  |
| system_prompt       | text              | Prompt système pour l'IA            |
| output_instructions | text (nullable)   | Instructions de formatage de sortie |
| tone                | string (nullable) | Ton souhaité (ex: professionnel)    |
| language            | string(10)        | Langue par défaut (défaut: fr)      |
| is_active           | boolean           | Persona active (défaut: true)       |

**Casts** : `is_active` → boolean

**Usage** : Les personas définissent le comportement de l'IA pour la génération de contenu (assistance IA et flux RSS).

---

### `hashtags`

| Colonne      | Type              | Description                         |
|--------------|-------------------|-------------------------------------|
| id           | bigint PK         | Identifiant                         |
| user_id      | FK → users (cascade) | Utilisateur propriétaire         |
| tag          | string            | Le hashtag (sans #)                 |
| usage_count  | unsigned int      | Nombre d'utilisations (défaut: 1)  |
| last_used_at | timestamp         | Dernière utilisation                |

**Index unique** : `[user_id, tag]`

**Usage** : Suivi des hashtags utilisés par chaque utilisateur pour suggestions auto-complète.

---

### `rss_feeds`

| Colonne              | Type              | Description                         |
|----------------------|-------------------|-------------------------------------|
| id                   | bigint PK         | Identifiant                         |
| name                 | string            | Nom du flux                         |
| url                  | text              | URL du flux RSS/Atom/Sitemap        |
| description          | string (nullable) | Description                         |
| category             | string (nullable) | Catégorie                           |
| is_active            | boolean           | Flux actif (défaut: true)           |
| is_multi_part_sitemap| boolean           | Sitemap multi-parties (défaut: false)|
| last_fetched_at      | timestamp (nullable) | Dernier fetch                    |

**Casts** : `is_active` → boolean, `is_multi_part_sitemap` → boolean, `last_fetched_at` → datetime

---

### `rss_feed_social_account` (pivot many-to-many)

| Colonne            | Type                     | Description                         |
|--------------------|--------------------------|-------------------------------------|
| id                 | bigint PK                | Identifiant                         |
| rss_feed_id        | FK → rss_feeds (cascade) | Flux RSS                            |
| social_account_id  | FK → social_accounts (cascade) | Compte cible                  |
| persona_id         | FK → personas (nullable) | Persona IA à utiliser               |
| auto_post          | boolean                  | Publication auto activée (défaut: false) |
| post_frequency     | string(20)               | Fréquence (défaut: daily)           |
| max_posts_per_day  | unsigned smallint        | Maximum de posts par jour (défaut: 1) |

**Index unique** : `[rss_feed_id, social_account_id]`

---

### `rss_items`

| Colonne      | Type                     | Description                         |
|--------------|--------------------------|-------------------------------------|
| id           | bigint PK                | Identifiant                         |
| rss_feed_id  | FK → rss_feeds (cascade) | Flux source                         |
| guid         | string                   | Identifiant unique de l'article     |
| title        | text                     | Titre de l'article                  |
| url          | text                     | URL de l'article                    |
| content      | text (nullable)          | Contenu complet                     |
| summary      | text (nullable)          | Résumé                              |
| author       | string (nullable)        | Auteur                              |
| image_url    | text (nullable)          | URL de l'image principale           |
| published_at | timestamp (nullable)     | Date de publication originale       |
| fetched_at   | timestamp                | Date de récupération                |

**Index unique** : `[rss_feed_id, guid]`

---

### `rss_posts`

| Colonne           | Type                            | Description                         |
|-------------------|---------------------------------|-------------------------------------|
| id                | bigint PK                       | Identifiant                         |
| rss_item_id       | FK → rss_items (cascade)        | Article source                      |
| social_account_id | FK → social_accounts (cascade)  | Compte cible                        |
| persona_id        | FK → personas (nullable)        | Persona utilisée                    |
| post_id           | FK → posts (nullable)           | Post généré (si créé)               |
| generated_content | text (nullable)                 | Contenu généré par l'IA             |
| status            | string(20)                      | pending/generated/posted/failed     |
| posted_at         | timestamp (nullable)            | Date de publication                 |

**Index unique** : `[rss_item_id, social_account_id]`

---

### `settings`

| Colonne     | Type          | Description                              |
|-------------|---------------|------------------------------------------|
| key         | string PK     | Clé du paramètre                         |
| value       | text          | Valeur (peut être chiffrée)              |

**Méthodes helper** :
- `Setting::get(key, default)` - Récupère une valeur
- `Setting::set(key, value)` - Définit une valeur
- `Setting::getEncrypted(key)` - Récupère et déchiffre
- `Setting::setEncrypted(key, value)` - Chiffre et stocke

**Paramètres gérés** :
- `openai_api_key` - Clé API OpenAI globale (chiffrée)
- `image_max_dimension` - Dimension max des images
- `image_target_min_kb` / `image_target_max_kb` - Taille cible des images
- `image_min_quality` - Qualité minimale de compression
- `image_max_upload_mb` / `video_max_upload_mb` - Taille max d'upload
- `video_bitrate_1080p` / `video_bitrate_720p` - Bitrates vidéo
- `video_codec` / `video_audio_bitrate` - Codecs vidéo
- `stats_sync_frequency` - Fréquence de sync des stats (every_15_min/every_30_min/hourly/every_2_hours/every_6_hours/every_12_hours/daily)
- `stats_{platform}_interval` - Intervalle de sync par plateforme (heures)
- `stats_{platform}_max_days` - Âge max des posts à synchroniser (jours)

---

## Migrations (ordre chronologique)

1. `0001_01_01_000000_create_users_table` - Users + password_reset_tokens
2. `0001_01_01_000001_create_cache_table` - Cache + cache_locks
3. `0001_01_01_000002_create_jobs_table` - Jobs + job_batches + failed_jobs
4. `2025_02_24_000001_create_platforms_table`
5. `2025_02_24_000002_create_social_accounts_table`
6. `2025_02_24_000003_create_posts_table`
7. `2025_02_24_000004_create_post_platform_table`
8. `2025_02_24_000005_create_post_logs_table`
9. `2025_02_24_100001_alter_posts_add_bilingual_fields` - Ajoute FR/EN, supprime content/title
10. `2025_02_24_100002_alter_social_accounts_add_configs` - Ajoute language/branding
11. `2025_02_24_100003_alter_users_add_settings` - Ajoute admin/API keys/langue
12. `2026_02_24_194531_refactor_social_accounts_many_to_many` - Many-to-many users ↔ social_accounts
13. `2026_02_24_195532_alter_social_accounts_profile_picture_url_to_text` - Élargit profile_picture_url
14. `2026_02_25_085708_add_default_accounts_to_users` - Ajoute default_accounts JSON
15. `2026_02_25_095919_create_settings_table` - Table settings (key-value)
16. `2026_02_25_120000_add_location_to_posts` - Ajoute location_name, location_id
17. `2026_02_25_154655_alter_social_accounts_language_to_languages_json` - language → languages (JSON)
18. `2026_02_25_154712_add_translations_to_posts` - Ajoute translations JSON
19. `2026_02_25_182309_create_hashtags_table` - Table hashtags avec index unique [user_id, tag]
20. `2026_02_26_073123_add_youtube_to_platforms_table` - Ajoute YouTube aux plateformes
21. `2026_02_26_082400_add_metrics_to_post_platform_table` - Ajoute metrics + metrics_synced_at
22. `2026_02_26_095511_create_external_posts_table` - Table external_posts + last_history_import_at sur social_accounts
23. `2026_02_26_101407_update_external_posts_url_columns_to_text` - media_url/post_url → text
24. `2026_02_26_131451_add_followers_count_to_social_accounts_table` - Ajoute followers_count + followers_synced_at
25. `2026_02_26_140001_create_personas_table` - Table personas
26. `2026_02_26_140002_create_rss_feeds_table` - Table rss_feeds
27. `2026_02_26_140003_create_rss_feed_social_account_table` - Table pivot rss_feed_social_account
28. `2026_02_26_140004_create_rss_items_table` - Table rss_items
29. `2026_02_26_140005_create_rss_posts_table` - Table rss_posts
30. `2026_02_26_140006_add_source_type_to_posts_table` - Ajoute source_type aux posts
31. `2026_02_27_000001_add_is_multi_part_sitemap_to_rss_feeds_table` - Ajoute is_multi_part_sitemap
32. `2026_02_27_000002_add_persona_id_to_social_accounts_table` - Ajoute persona_id aux social_accounts
33. `2026_02_27_100001_move_is_active_to_pivot` - Déplace is_active de social_accounts vers social_account_user
