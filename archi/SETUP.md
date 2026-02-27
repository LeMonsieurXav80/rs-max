# Installation et déploiement

## Prérequis

- PHP 8.2+
- Composer
- Node.js + npm
- MySQL (ou SQLite pour le dev)
- FFmpeg (optionnel, pour la conversion vidéo et les thumbnails)

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

# OpenAI (traduction automatique + génération IA)
OPENAI_API_KEY=sk-...

# Queue (database par défaut, Redis recommandé en prod)
QUEUE_CONNECTION=database

# Session
SESSION_DRIVER=database
```

**Variables plateformes** :

```env
# Facebook / Instagram (OAuth + Graph API)
FACEBOOK_APP_ID=
FACEBOOK_APP_SECRET=
FACEBOOK_CONFIG_ID=

# Threads (OAuth)
THREADS_APP_ID=
THREADS_APP_SECRET=

# YouTube (Google OAuth)
YOUTUBE_CLIENT_ID=
YOUTUBE_CLIENT_SECRET=
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

### Facebook / Instagram (OAuth)

1. Créer une Facebook App sur [developers.facebook.com](https://developers.facebook.com)
2. Sélectionner les cas d'usage : "Tout gérer sur votre Page" + "Gérer les messages et les contenus sur Instagram"
3. Activer **Facebook Login for Business** et configurer le `config_id`
4. Activer les permissions (Accès standard) :
   - `pages_show_list`
   - `pages_read_engagement`
   - `pages_read_user_content`
   - `pages_manage_posts`
   - `instagram_basic`
   - `instagram_content_publish`
5. Configurer le callback URL : `{APP_URL}/auth/facebook/callback`
6. Dans `.env` : renseigner `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`, `FACEBOOK_CONFIG_ID`
7. Dans RS-Max : Plateformes → Facebook → "Connecter avec Facebook"

**Note** : Facebook Login for Business ne retourne que les pages liées au **business portfolio** de l'app. Les pages doivent être gérées via Meta Business Suite.

### Threads (OAuth)

1. Utiliser la même Facebook App (un sous-app Threads est auto-créée)
2. Configurer le callback URL : `{APP_URL}/auth/threads/callback`
3. Dans `.env` : renseigner `THREADS_APP_ID`, `THREADS_APP_SECRET`
4. Dans RS-Max : Plateformes → Threads → "Connecter avec Threads"

### YouTube (Google OAuth)

1. Créer un projet sur [console.cloud.google.com](https://console.cloud.google.com)
2. Activer l'API YouTube Data API v3
3. Créer des identifiants OAuth 2.0 (application web)
4. Configurer le redirect URI : `{APP_URL}/oauth/youtube/callback`
5. Dans `.env` : renseigner `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`
6. Dans RS-Max : Plateformes → YouTube → "Connecter avec Google"

### Twitter/X (API)

1. Créer un projet sur [developer.twitter.com](https://developer.twitter.com)
2. Obtenir les 4 clés OAuth 1.0a (API Key, API Secret, Access Token, Access Token Secret)
3. Dans RS-Max : Plateformes → Twitter → Ajouter le compte avec les 4 clés
4. Valider les credentials via le bouton de validation

### Telegram (Bot API)

1. Créer un bot via [@BotFather](https://t.me/BotFather) et obtenir le `bot_token`
2. Dans RS-Max : Plateformes → Telegram → Enregistrer le bot
3. Ajouter le bot comme administrateur du channel/groupe cible
4. Ajouter le channel via l'interface (recherche automatique du chat_id)

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

# Facebook / Instagram
FACEBOOK_APP_ID=...
FACEBOOK_APP_SECRET=...
FACEBOOK_CONFIG_ID=...

# Threads
THREADS_APP_ID=...
THREADS_APP_SECRET=...

# YouTube
YOUTUBE_CLIENT_ID=...
YOUTUBE_CLIENT_SECRET=...

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

Le scheduler exécute automatiquement :
- `posts:publish-scheduled` - Chaque minute
- `rss:generate` - Toutes les 6 heures
- `stats:sync` - Fréquence configurable via Settings (défaut: hourly)

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

# 4. Convertir les vidéos en H.264 si nécessaire
php artisan media:convert-videos

# 5. Recompresser les images selon les paramètres
php artisan media:recompress
```

---

## Structure des fichiers de config

| Fichier                    | Rôle                                         |
|----------------------------|----------------------------------------------|
| `.env`                     | Variables d'environnement                    |
| `config/services.php`      | Clés API (Facebook, Threads, YouTube, OpenAI)|
| `config/filesystems.php`   | Disques de stockage (local, public, s3)      |
| `config/queue.php`         | Configuration de la queue                    |
| `docker-compose.yml`       | Services Docker                              |
| `Dockerfile`               | Image Docker de l'app                        |
| `nixpacks.toml`            | Config Coolify/Nixpacks                      |
| `vite.config.js`           | Build frontend                               |
| `tailwind.config.js`       | Configuration Tailwind CSS                   |
