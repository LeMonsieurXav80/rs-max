# Installation et déploiement

## Prérequis

- PHP 8.2+
- Composer
- Node.js + npm
- MySQL (ou SQLite pour le dev)

---

## Installation locale

### 1. Cloner et installer

```bash
git clone <repo-url> rs-max
cd rs-max
composer setup
```

La commande `composer setup` exécute :
1. `composer install` - Dépendances PHP
2. `php artisan key:generate` - Clé d'application
3. `php artisan migrate` - Création des tables
4. `npm install && npm run build` - Dépendances JS + build

### 2. Configuration

Copier et éditer le `.env` :

```bash
cp .env.example .env
```

**Variables essentielles** :

```env
# Application
APP_NAME=RS-Max
APP_URL=http://localhost:8000

# Base de données (MySQL recommandé)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rs_max
DB_USERNAME=root
DB_PASSWORD=

# OpenAI (traduction automatique)
OPENAI_API_KEY=sk-...

# Queue (database par défaut, Redis recommandé en prod)
QUEUE_CONNECTION=database

# Session
SESSION_DRIVER=database
```

### 3. Seed des plateformes

```bash
php artisan db:seed --class=PlatformSeeder
```

### 4. Lancer en développement

```bash
composer dev
```

Lance 4 services en parallèle :
- **Serveur web** : `http://localhost:8000`
- **Queue worker** : traite les jobs de publication
- **Pail** : affiche les logs en temps réel
- **Vite** : hot reload pour le CSS/JS

---

## Configuration des plateformes

### Facebook

1. Créer une Facebook App sur [developers.facebook.com](https://developers.facebook.com)
2. Activer les permissions : `pages_manage_posts`, `pages_read_engagement`
3. Obtenir un Page Access Token (longue durée)
4. Dans RS-Max : Comptes → Ajouter → Facebook → `page_id` + `access_token`

### Instagram

1. Même Facebook App, activer Instagram Graph API
2. Permissions : `instagram_basic`, `instagram_content_publish`
3. Obtenir le `account_id` Instagram Business (via Graph API Explorer)
4. Dans RS-Max : Comptes → Ajouter → Instagram → `account_id` + `access_token`

### Twitter/X

1. Créer un projet sur [developer.twitter.com](https://developer.twitter.com)
2. Obtenir les 4 clés OAuth 1.0a
3. Dans RS-Max : Comptes → Ajouter → Twitter → les 4 clés

### Telegram

1. Créer un bot via [@BotFather](https://t.me/BotFather)
2. Obtenir le `bot_token`
3. Obtenir le `chat_id` du channel/groupe cible
4. Dans RS-Max : Comptes → Ajouter → Telegram → `bot_token` + `chat_id`

---

## Déploiement (Coolify / Docker)

### Docker Compose

Le projet inclut `docker-compose.yml` et `Dockerfile` pour le déploiement.

### Coolify (nixpacks)

Le fichier `nixpacks.toml` est configuré pour le déploiement automatique sur Coolify.

### Variables d'environnement en production

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-domaine.com

# Forcer HTTPS
ASSET_URL=https://votre-domaine.com

# MySQL
DB_CONNECTION=mysql
DB_HOST=...
DB_DATABASE=rs_max
DB_USERNAME=...
DB_PASSWORD=...

# Redis (recommandé en prod)
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# OpenAI
OPENAI_API_KEY=sk-...

# Mail (pour vérification email)
MAIL_MAILER=smtp
MAIL_HOST=...
```

### Commandes post-déploiement

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Scheduler en production

Ajouter au crontab :
```cron
* * * * * cd /path/to/rs-max && php artisan schedule:run >> /dev/null 2>&1
```

### Queue worker en production

Utiliser Supervisor ou systemd :
```ini
[program:rs-max-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/rs-max/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
```

---

## Migration depuis NocoDB

Pour importer les données de l'ancien système :

```bash
# 1. Importer les clients et posts depuis NocoDB
php artisan import:nocodb --admin-email=votre@email.com

# 2. Télécharger les médias distants en local
php artisan media:download

# 3. Sécuriser les médias (stockage privé + UUID)
php artisan media:secure
```

---

## Structure des fichiers de config

| Fichier                    | Rôle                                    |
|----------------------------|-----------------------------------------|
| `.env`                     | Variables d'environnement               |
| `config/filesystems.php`   | Disques de stockage (local, public, s3) |
| `config/services.php`      | Clés API externes (OpenAI)              |
| `config/queue.php`         | Configuration de la queue               |
| `docker-compose.yml`       | Services Docker                         |
| `Dockerfile`               | Image Docker de l'app                   |
| `nixpacks.toml`            | Config Coolify/Nixpacks                 |
| `vite.config.js`           | Build frontend                          |
| `tailwind.config.js`       | Configuration Tailwind CSS              |
