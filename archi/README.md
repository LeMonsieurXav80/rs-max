# RS-Max - Architecture du projet

## Vue d'ensemble

RS-Max est une application Laravel 12 de **publication automatisée multi-plateformes** pour les réseaux sociaux. Elle remplace un workflow n8n + NocoDB par une solution intégrée.

### Fonctionnalités principales

- **Publication multi-plateforme** : Facebook, Instagram, Twitter/X, Telegram, Threads, YouTube
- **Contenu multilingue** : Support de plusieurs langues avec traduction auto via GPT-4o-mini
- **OAuth intégré** : Connexion Facebook/Instagram, Threads et YouTube avec récupération automatique des comptes
- **Geolocation** : Tag de lieux pour Facebook, Instagram et Threads
- **Planification** : scheduler minute par minute pour les posts programmés
- **Multi-utilisateurs** : Comptes sociaux partagés entre utilisateurs (many-to-many), activation par utilisateur
- **Médias privés** : stockage sécurisé avec URLs signées et streaming vidéo (HTTP Range)
- **Médiathèque** : upload, listing, thumbnails vidéo, suppression de médias
- **Conversion automatique** : Vidéos HEVC → H.264, compression adaptative d'images (paramétrable)
- **Branding** : signature personnalisable par compte social
- **Personas IA** : prompts système personnalisés pour la génération de contenu
- **Assistance IA** : génération et réécriture de contenu via OpenAI (gpt-4o-mini)
- **Flux RSS** : import automatique d'articles avec génération de posts via IA
- **Import historique** : récupération des posts existants depuis les plateformes
- **Analytics** : tableau de bord statistiques, synchronisation des métriques par plateforme
- **Suivi des abonnés** : synchronisation des compteurs de followers
- **Hashtags** : suggestions basées sur l'historique d'utilisation

---

## Stack technique

| Composant       | Technologie                          |
|-----------------|--------------------------------------|
| Framework       | Laravel 12 (PHP 8.2+)               |
| Frontend        | Blade + Tailwind CSS 3 + Alpine.js   |
| Base de données | MySQL (SQLite en dev)                |
| Queue           | Database (configurable Redis)        |
| Traduction      | OpenAI API (gpt-4o-mini)            |
| Génération IA   | OpenAI API (gpt-4o-mini)            |
| Auth            | Laravel Breeze                       |
| Build           | Vite + PostCSS                       |
| Déploiement     | Docker / Coolify (nixpacks)          |

---

## Structure du projet

```
rs-max/
├── app/
│   ├── Console/Commands/       # Commandes Artisan (scheduler, import, media, stats, RSS)
│   ├── Http/Controllers/       # Controllers (posts, comptes, plateformes, OAuth, import, stats, personas, RSS, médias)
│   ├── Jobs/                   # PublishToPlatformJob (publication async)
│   ├── Models/                 # User, Post, Platform, SocialAccount, Setting, PostPlatform, PostLog,
│   │                           # ExternalPost, Persona, RssFeed, RssItem, RssPost, Hashtag
│   └── Services/
│       ├── Adapters/           # Facebook, Instagram, Twitter, Telegram, Threads, YouTube adapters
│       ├── Import/             # Import historique par plateforme (Facebook, Instagram, Twitter, YouTube, Threads)
│       ├── Stats/              # Synchronisation des métriques par plateforme
│       ├── Rss/                # Fetch RSS, génération de contenu IA, extraction d'articles
│       ├── PublishingService   # Orchestration de la publication
│       ├── TranslationService  # Traduction via OpenAI
│       ├── AiAssistService     # Assistance IA pour la création de contenu
│       ├── FollowersService    # Synchronisation des compteurs d'abonnés
│       └── YouTubeTokenHelper  # Rafraîchissement des tokens YouTube
├── database/
│   ├── migrations/             # 33 migrations (schema complet)
│   └── seeders/                # PlatformSeeder + DatabaseSeeder
├── resources/views/
│   ├── layouts/                # Layout principal avec navigation
│   ├── components/             # Composants Blade réutilisables
│   ├── posts/                  # index (liste + calendrier), create, edit, show
│   ├── accounts/               # index, create
│   ├── platforms/              # facebook, threads, telegram, twitter, youtube (pages de gestion)
│   ├── personas/               # index, create, edit
│   ├── rss/                    # index, create, edit
│   ├── stats/                  # dashboard analytics
│   ├── media/                  # médiathèque
│   ├── settings/               # paramètres admin
│   └── dashboard.blade.php     # Tableau de bord avec calendrier et métriques
├── routes/
│   ├── web.php                 # Routes web principales
│   ├── auth.php                # Routes d'authentification (Breeze)
│   └── console.php             # Scheduler (publish, RSS, stats sync)
└── archi/                      # Ce dossier de documentation
```

---

## Documentation détaillée

| Fichier                             | Contenu                                      |
|-------------------------------------|----------------------------------------------|
| [DATABASE.md](DATABASE.md)          | Schéma DB, tables, relations, casts           |
| [CONTROLLERS.md](CONTROLLERS.md)    | Routes, contrôleurs, validation               |
| [PUBLISHING.md](PUBLISHING.md)      | Pipeline de publication, jobs, scheduler       |
| [ADAPTERS.md](ADAPTERS.md)          | Adapteurs, import, stats, RSS, IA             |
| [MEDIA.md](MEDIA.md)               | Gestion des médias, médiathèque, stockage privé |
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
   ├── Auto-traduction FR→EN/PT/ES/DE/IT si nécessaire
   ├── Dispatch PublishToPlatformJob par plateforme
        │
        ▼
PublishToPlatformJob
   ├── Sélectionne l'adapter (Telegram/Facebook/Instagram/Twitter/Threads/YouTube)
   ├── Construit le contenu (langues + hashtags + branding)
   ├── Génère des URLs signées pour les médias
   ├── Appelle adapter->publish()
   ├── Met à jour PostPlatform (published/failed)
   └── Met à jour Post global si toutes les plateformes sont terminées
```

## Flux RSS automatique

```
RssFetchService (toutes les 6h)
   ├── Parcourt les flux RSS actifs
   ├── Parse RSS 2.0 / Atom / Sitemaps
   └── Crée des RssItems
        │
        ▼
RssGenerateCommand (toutes les 6h)
   ├── Pour chaque feed + compte avec auto_post activé
   ├── ArticleFetchService → récupère le contenu de l'article
   ├── ContentGenerationService → génère le contenu via IA (persona)
   ├── Crée un Post (status=scheduled, source_type=rss)
   └── Planifie dans la fenêtre 9h-20h
        │
        ▼
→ Le scheduler classique prend le relais pour la publication
```
