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
  └── has many ──► social_accounts
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
| is_admin                 | boolean           | Peut voir/gérer tous les clients     |
| email_verified_at        | timestamp         | Date de vérification email           |

**Casts** : `openai_api_key` → encrypted, `auto_translate` → boolean, `is_admin` → boolean

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

**Plateformes seedées** : Facebook, Twitter/X, Instagram, Telegram

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

| Colonne       | Type                | Description                         |
|---------------|---------------------|-------------------------------------|
| id            | bigint PK           | Identifiant                         |
| user_id       | FK → users          | Propriétaire du compte              |
| platform_id   | FK → platforms      | Plateforme associée                 |
| name          | string              | Nom du compte (ex: "Page Facebook") |
| credentials   | JSON (encrypted)    | Tokens/clés API                     |
| language      | string              | Langue de publication (fr/en/both)  |
| branding      | text (nullable)     | Signature à ajouter aux posts       |
| show_branding | boolean             | Afficher le branding                |
| is_active     | boolean             | Compte actif/inactif                |
| last_used_at  | timestamp (nullable)| Dernière utilisation                |

**Index** : `[user_id, platform_id]`

**Casts** : `credentials` → encrypted:array

---

### `posts`

| Colonne          | Type              | Description                          |
|------------------|-------------------|--------------------------------------|
| id               | bigint PK         | Identifiant                          |
| user_id          | FK → users        | Auteur du post                       |
| content_fr       | text              | Contenu français                     |
| content_en       | text (nullable)   | Contenu anglais (auto ou manuel)     |
| hashtags         | string (nullable) | Hashtags                             |
| auto_translate   | boolean           | Traduction auto activée pour ce post |
| media            | JSON              | Tableau de médias                    |
| link_url         | string (nullable) | Lien à inclure                       |
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
