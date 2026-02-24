# RS-Max - Architecture du projet

## Vue d'ensemble

RS-Max est une application Laravel 12 de **publication automatisée multi-plateformes** pour les réseaux sociaux. Elle remplace un workflow n8n + NocoDB par une solution intégrée.

### Fonctionnalités principales

- **Publication multi-plateforme** : Facebook, Instagram, Twitter/X, Telegram
- **Contenu bilingue** : FR/EN avec traduction auto via GPT-4o-mini
- **Planification** : scheduler minute par minute pour les posts programmés
- **Multi-client** : 1 user = 1 client, l'admin peut tout voir/gérer
- **Médias privés** : stockage sécurisé avec URLs signées pour les APIs externes
- **Branding** : signature personnalisable par compte social

---

## Stack technique

| Composant       | Technologie                          |
|-----------------|--------------------------------------|
| Framework       | Laravel 12 (PHP 8.2+)               |
| Frontend        | Blade + Tailwind CSS 3 + Alpine.js   |
| Base de données | MySQL (SQLite en dev)                |
| Queue           | Database (configurable Redis)        |
| Traduction      | OpenAI API (gpt-4o-mini)            |
| Auth            | Laravel Breeze                       |
| Build           | Vite + PostCSS                       |
| Déploiement     | Docker / Coolify (nixpacks)          |

---

## Structure du projet

```
rs-max/
├── app/
│   ├── Console/Commands/       # Commandes Artisan (scheduler, import, media)
│   ├── Http/Controllers/       # Controllers web (CRUD posts, comptes, dashboard)
│   ├── Jobs/                   # PublishToPlatformJob (publication async)
│   ├── Models/                 # User, Post, Platform, SocialAccount, PostPlatform, PostLog
│   └── Services/
│       ├── Adapters/           # Facebook, Instagram, Twitter, Telegram adapters
│       ├── PublishingService   # Orchestration de la publication
│       └── TranslationService  # Traduction via OpenAI
├── database/
│   ├── migrations/             # 11 migrations (schema complet)
│   └── seeders/                # PlatformSeeder + DatabaseSeeder
├── resources/views/
│   ├── layouts/                # Layout principal avec sidebar
│   ├── components/             # Composants Blade réutilisables
│   ├── posts/                  # index, create, edit, show
│   ├── accounts/               # index, create, edit
│   └── dashboard.blade.php     # Tableau de bord
├── routes/
│   ├── web.php                 # Routes web principales
│   ├── auth.php                # Routes d'authentification (Breeze)
│   └── console.php             # Scheduler (posts:publish-scheduled)
└── archi/                      # Ce dossier de documentation
```

---

## Documentation détaillée

| Fichier                             | Contenu                                      |
|-------------------------------------|----------------------------------------------|
| [DATABASE.md](DATABASE.md)          | Schéma DB, tables, relations, casts           |
| [CONTROLLERS.md](CONTROLLERS.md)    | Routes, contrôleurs, validation               |
| [PUBLISHING.md](PUBLISHING.md)      | Pipeline de publication, jobs, scheduler       |
| [ADAPTERS.md](ADAPTERS.md)          | Adapteurs par plateforme (API, auth, médias)  |
| [MEDIA.md](MEDIA.md)               | Gestion des médias, stockage privé, URLs signées |
| [COMMANDS.md](COMMANDS.md)          | Commandes Artisan disponibles                  |
| [SETUP.md](SETUP.md)               | Installation, configuration, déploiement       |

---

## Flux principal

```
Utilisateur crée un post (draft/scheduled)
        │
        ▼
Scheduler (toutes les minutes)
        │
        ▼
PublishingService::publish()
   ├── Auto-traduction FR→EN si nécessaire
   ├── Dispatch PublishToPlatformJob par plateforme
        │
        ▼
PublishToPlatformJob
   ├── Sélectionne l'adapter (Telegram/Facebook/Instagram/Twitter)
   ├── Construit le contenu (langue + branding)
   ├── Génère des URLs signées pour les médias
   ├── Appelle adapter->publish()
   ├── Met à jour PostPlatform (published/failed)
   └── Met à jour Post global si toutes les plateformes sont terminées
```
