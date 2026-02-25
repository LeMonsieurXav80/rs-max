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
  └── belongs to many ──► social_accounts (via social_account_user)
                              │
                              └── belongs to ──► platforms
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

**Plateformes seedées** : Facebook, Twitter/X, Instagram, Telegram, Threads

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
| credentials          | JSON (encrypted)    | Tokens/clés API                                |
| languages            | JSON                | Langues de publication (ex: ["fr","en"])       |
| branding             | text (nullable)     | Signature à ajouter aux posts                  |
| show_branding        | boolean             | Afficher le branding                           |
| is_active            | boolean             | Compte actif/inactif                           |
| last_used_at         | timestamp (nullable)| Dernière utilisation                           |

**Index unique** : `[platform_id, platform_account_id]`

**Casts** : `credentials` → encrypted:array, `languages` → array

**Note** : Les comptes sont uniques par `platform_id` + `platform_account_id`. Ils peuvent être partagés entre plusieurs utilisateurs via la table pivot `social_account_user`.

---

### `social_account_user` (pivot many-to-many)

| Colonne           | Type              | Description                         |
|-------------------|-------------------|-------------------------------------|
| id                | bigint PK         | Identifiant                         |
| social_account_id | FK → social_accounts | Compte social                    |
| user_id           | FK → users        | Utilisateur lié                     |

**Index unique** : `[social_account_id, user_id]`

**Architecture** : Permet à plusieurs utilisateurs de partager le même compte social (ex: même page Facebook pour plusieurs membres d'une équipe).

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
| telegram_channel | string (nullable) | Override du chat_id Telegram         |
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

**Index** : `[post_id, platform_id]`

---

### `settings`

| Colonne     | Type          | Description                              |
|-------------|---------------|------------------------------------------|
| key         | string PK     | Clé du paramètre (ex: "openai_api_key") |
| value       | text          | Valeur (peut être chiffrée)              |

**Méthodes helper** :
- `Setting::get(key, default)` - Récupère une valeur
- `Setting::set(key, value)` - Définit une valeur
- `Setting::getEncrypted(key)` - Récupère et déchiffre
- `Setting::setEncrypted(key, value)` - Chiffre et stocke

**Usage** : Stockage global de configuration (clés API globales, paramètres d'application).

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
