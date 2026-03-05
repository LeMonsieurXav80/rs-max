# RS-Max - Architecture du projet

## Vue d'ensemble

RS-Max est une application Laravel 12 de **publication automatisée multi-plateformes** pour les réseaux sociaux. Elle remplace un workflow n8n + NocoDB par une solution intégrée.

### Fonctionnalités principales

- **Publication multi-plateforme** : Facebook, Instagram, Twitter/X, Telegram, Threads, YouTube, Bluesky, Reddit (8 plateformes)
- **Messagerie / Inbox** : boîte de réception unifiée multi-plateformes (commentaires, réponses, DMs), suggestions de réponse IA, réponses groupées avec planification étalée, threading par `conversation_key`
- **Bot d'engagement** : recherche automatique de posts par mots-clés, réponses IA avec persona, support Bluesky et Facebook, fréquence configurable par compte
- **Threads / Multi-posts** : création de threads multi-segments (Twitter threads, Threads carousel), génération depuis URL, régénération par segment
- **Sources de contenu** : RSS, WordPress (REST API), YouTube, Reddit — chacune avec tables sources/items/pivot/posts, workflow preview + confirm
- **Contenu multilingue** : Support de plusieurs langues avec traduction auto via GPT-4o-mini
- **OAuth intégré** : Connexion Facebook/Instagram, Threads et YouTube avec récupération automatique des comptes
- **Geolocation** : Tag de lieux pour Facebook, Instagram et Threads
- **Planification** : scheduler minute par minute pour les posts programmés
- **Multi-utilisateurs** : Comptes sociaux partagés entre utilisateurs (many-to-many), activation par utilisateur
- **Médias privés** : stockage sécurisé avec URLs signées et streaming vidéo (HTTP Range)
- **Médiathèque** : upload, listing, thumbnails vidéo, suppression de médias, organisation en dossiers
- **Conversion automatique** : Vidéos HEVC → H.264, compression adaptative d'images (paramétrable)
- **Branding** : signature personnalisable par compte social
- **Personas IA** : prompts système personnalisés pour la génération de contenu
- **Assistance IA** : génération et réécriture de contenu via OpenAI (gpt-4o-mini)
- **Hooks** : webhooks URL déclenchés par événements, catégories avec compteurs de requêtes
- **Import historique** : récupération des posts existants depuis les plateformes
- **Analytics** : tableau de bord statistiques, synchronisation des métriques par plateforme
- **Suivi des abonnés** : synchronisation des compteurs de followers
- **Hashtags** : suggestions basées sur l'historique d'utilisation
- **Gestion utilisateurs** : admin peut gérer les utilisateurs et toggler le statut admin

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
│   ├── Console/Commands/       # Commandes Artisan (scheduler, import, media, stats, RSS, inbox, bot)
│   ├── Http/Controllers/       # Controllers (posts, comptes, plateformes, OAuth, import, stats, personas,
│   │                           # RSS, médias, inbox, bot, threads, WordPress, YouTube, Reddit,
│   │                           # hooks, media folders, users, source items)
│   ├── Jobs/                   # PublishToPlatformJob (publication async)
│   ├── Models/                 # User, Post, Platform, SocialAccount, Setting, PostPlatform, PostLog,
│   │                           # ExternalPost, Persona, RssFeed, RssItem, RssPost, Hashtag,
│   │                           # InboxItem, BotTerm, BotLog, BotAccountSetting,
│   │                           # Thread, ThreadSegment, Hook, HookCategory, MediaFolder,
│   │                           # WpSource, WpItem, WpPost, YtSource, YtItem, YtPost,
│   │                           # RedditSource, RedditItem, RedditPost
│   └── Services/
│       ├── Adapters/           # Facebook, Instagram, Twitter, Telegram, Threads, YouTube, Bluesky, Reddit adapters
│       ├── Import/             # Import historique par plateforme
│       ├── Stats/              # Synchronisation des métriques par plateforme
│       ├── Inbox/              # 8 services inbox par plateforme + InboxSyncService
│       ├── Bot/                # BlueskyBotService, FacebookBotService
│       ├── Rss/                # Fetch RSS, génération de contenu IA, extraction d'articles
│       ├── WordPress/          # WordPressFetchService (REST API)
│       ├── YouTube/            # YouTubeFetchService (OAuth)
│       ├── Reddit/             # RedditFetchService
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
│   ├── wordpress/              # sites WordPress
│   ├── youtube-channels/       # chaînes YouTube
│   ├── reddit/                 # sources Reddit
│   ├── inbox/                  # messagerie unifiée
│   ├── bot/                    # bot d'engagement
│   ├── threads/                # threads multi-segments
│   ├── hooks/                  # webhooks
│   ├── stats/                  # dashboard analytics
│   ├── media/                  # médiathèque + dossiers
│   ├── users/                  # gestion utilisateurs
│   ├── settings/               # paramètres admin
│   └── dashboard.blade.php     # Tableau de bord avec calendrier et métriques
├── routes/
│   ├── web.php                 # Routes web principales
│   ├── auth.php                # Routes d'authentification (Breeze)
│   ├── api.php                 # Routes API (source items)
│   └── console.php             # Scheduler (publish, RSS, inbox, bot, stats sync)
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
| [INBOX.md](INBOX.md)               | Messagerie / Inbox architecture                |
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
   ├── Sélectionne l'adapter (Telegram/Facebook/Instagram/Twitter/Threads/YouTube/Bluesky/Reddit)
   ├── Construit le contenu (langues + hashtags + branding)
   ├── Génère des URLs signées pour les médias
   ├── Appelle adapter->publish()
   ├── Met à jour PostPlatform (published/failed)
   └── Met à jour Post global si toutes les plateformes sont terminées
```

## Flux Sources de contenu (RSS, WordPress, YouTube, Reddit)

```
FetchService (RSS/WordPress/YouTube/Reddit)
   ├── Parcourt les sources actives
   ├── Récupère les items (articles, vidéos, posts)
   └── Crée des Items (RssItem, WpItem, YtItem, RedditItem)
        │
        ▼
GenerateCommand (par type de source)
   ├── Pour chaque source + compte avec auto_post activé
   ├── ArticleFetchService → récupère le contenu (si applicable)
   ├── ContentGenerationService → génère le contenu via IA (persona)
   ├── Crée un Post (status=scheduled, source_type=rss|wp|yt|reddit)
   └── Planifie dans la fenêtre 9h-20h
        │
        ▼
→ Le scheduler classique prend le relais pour la publication
```

## Flux Inbox (Messagerie)

```
Platform APIs → InboxService::fetchInbox() → InboxItem (avec conversation_key)
                                           → InboxController groupe par conversation_key
                                           → Suggestions IA via OpenAI
                                           → Réponse via API plateforme
```

## Flux Bot (Engagement automatique)

```
bot:run (toutes les 15 min) → BotTerm mots-clés → recherche API plateforme
                            → IA génère une réponse → publie via API plateforme
                            → BotLog trace les actions
```

## Scheduler (routes/console.php)

```
posts:publish-scheduled    → toutes les minutes
followers:sync             → quotidien à 06:00
stats:sync                 → configurable (défaut : toutes les heures)
inbox:send-scheduled       → toutes les minutes
inbox:sync                 → configurable (défaut : toutes les 15 min)
bot:run                    → toutes les 15 min
snapshots:downsample       → mensuel le 1er à 03:00
```
