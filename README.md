# RS-Max

Plateforme de gestion de reseaux sociaux multi-comptes avec publication, boite de reception, bot de prospection, et generation de contenu par IA.

## Plateformes supportees

Facebook, Instagram, Threads, Twitter/X, Bluesky, YouTube, Reddit, Telegram

## Pre-requis

- **Production** : Docker + Coolify (ou tout orchestrateur Docker)
- **Developpement local** : PHP 8.2+, Composer, Node.js 18+, SQLite

## Installation rapide (dev local)

```bash
git clone <repo-url> rs-max && cd rs-max
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed
php artisan make:admin   # Creer le premier compte admin
php artisan serve
```

## Deploiement Docker (Coolify)

1. Creer une application **Docker Compose** dans Coolify
2. Configurer les variables d'environnement (voir `.env.example`)
3. Le premier demarrage execute automatiquement les migrations et seeders
4. Creer un admin : `docker exec -it <container> php artisan make:admin`

## Variables d'environnement requises

| Variable | Description |
|----------|-------------|
| `APP_KEY` | Cle de chiffrement (generee automatiquement au premier demarrage) |
| `APP_URL` | URL publique de l'instance |
| `DB_*` | Connexion base de donnees (MySQL en production) |
| `FACEBOOK_APP_ID` / `FACEBOOK_APP_SECRET` | App Meta pour Facebook + Instagram |
| `FACEBOOK_CONFIG_ID` | Config ID pour Facebook Login for Business |
| `THREADS_APP_ID` / `THREADS_APP_SECRET` | App Meta pour Threads |
| `YOUTUBE_CLIENT_ID` / `YOUTUBE_CLIENT_SECRET` | OAuth Google pour YouTube |
| `OPENAI_API_KEY` | API OpenAI pour la generation de contenu IA |
| `REGISTRATION_ENABLED` | `false` par defaut — desactive l'inscription publique |

Voir `.env.example` pour la liste complete.

## Commandes artisan utiles

```bash
php artisan make:admin              # Creer un compte administrateur
php artisan posts:publish-scheduled  # Publier les posts programmes
php artisan inbox:sync               # Synchroniser la boite de reception
php artisan bot:run                  # Lancer le bot de prospection
php artisan stats:sync               # Synchroniser les statistiques
php artisan followers:sync           # Synchroniser les compteurs d'abonnes
```

## Roles utilisateurs

- **Admin** : Acces complet (gestion utilisateurs, creation/suppression comptes sociaux)
- **Manager** : Configuration (hooks, personas, sources de contenu, bot, parametres)
- **User** : Publication, boite de reception, consultation stats

## APP_KEY — AVERTISSEMENT CRITIQUE

> **Ne JAMAIS modifier ou regenerer `APP_KEY` apres l'installation initiale.**
>
> Toutes les donnees sensibles (credentials des comptes sociaux, cles API) sont chiffrees
> avec cette cle via le cast `encrypted:array` de Laravel. Si `APP_KEY` change, toutes ces
> donnees deviennent **irreversiblement illisibles**.
>
> **Avant toute operation** :
> 1. Sauvegarder `APP_KEY` dans un endroit securise
> 2. Ne jamais l'inclure dans un commit ou un fichier partage
> 3. En cas de migration de serveur, copier la meme `APP_KEY`

## Systeme de mise a jour

RS-Max detecte automatiquement les nouvelles versions disponibles (verification horaire).
Un badge s'affiche dans l'interface admin lorsqu'une mise a jour est disponible.
L'admin peut declencher le deploiement manuellement via Coolify.

Variables necessaires : `COOLIFY_API_URL`, `COOLIFY_API_TOKEN`, `COOLIFY_APP_UUID`
