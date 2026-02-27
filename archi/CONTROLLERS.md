# Routes et Contrôleurs

## Routes web (`routes/web.php`)

### Publiques (aucun middleware)
| Méthode | URL                    | Action                  | Description                           |
|---------|------------------------|-------------------------|---------------------------------------|
| GET     | `/`                    | Closure                 | Redirige vers /dashboard ou /login    |
| GET     | `/media/{filename}`    | MediaController@show    | Sert les médias privés (auth OU URL signée) |

### Authentifiées + vérifiées (`auth`, `verified`)

#### Posts
| Méthode | URL                                    | Action                              | Nom                    |
|---------|----------------------------------------|-------------------------------------|------------------------|
| GET     | `/posts`                               | PostController@index                | posts.index            |
| GET     | `/posts/create`                        | PostController@create               | posts.create           |
| POST    | `/posts`                               | PostController@store                | posts.store            |
| GET     | `/posts/{post}`                        | PostController@show                 | posts.show             |
| GET     | `/posts/{post}/edit`                   | PostController@edit                 | posts.edit             |
| PATCH   | `/posts/{post}`                        | PostController@update               | posts.update           |
| DELETE  | `/posts/{post}`                        | PostController@destroy              | posts.destroy          |
| POST    | `/posts/ai-assist`                     | AiAssistController@generate         | posts.aiAssist         |
| POST    | `/posts/default-accounts`              | PostController@saveDefaultAccounts  | posts.defaultAccounts  |
| POST    | `/posts/{post}/sync-stats`             | PostController@syncStats            | posts.syncStats        |

#### Publication manuelle
| Méthode | URL                                        | Action                          | Nom                |
|---------|--------------------------------------------|---------------------------------|--------------------|
| POST    | `/posts/{post}/publish`                    | PublishController@publishAll    | posts.publish      |
| POST    | `/posts/platform/{postPlatform}/publish`   | PublishController@publishOne    | posts.publishOne   |
| POST    | `/posts/platform/{postPlatform}/reset`     | PublishController@resetOne      | posts.resetOne     |

#### Comptes sociaux
| Méthode | URL                                 | Action                              | Nom                |
|---------|-------------------------------------|-------------------------------------|---------------------|
| GET     | `/accounts`                         | SocialAccountController@index       | accounts.index      |
| GET     | `/accounts/create`                  | SocialAccountController@create      | accounts.create     |
| POST    | `/accounts`                         | SocialAccountController@store       | accounts.store      |
| GET     | `/accounts/{account}/edit`          | SocialAccountController@edit        | accounts.edit       |
| PATCH   | `/accounts/{account}`               | SocialAccountController@update      | accounts.update     |
| DELETE  | `/accounts/{account}`               | SocialAccountController@destroy     | accounts.destroy    |
| PATCH   | `/accounts/{account}/toggle`        | SocialAccountController@toggleActive| accounts.toggle     |

#### Import historique et followers
| Méthode | URL                                 | Action                              | Nom                      |
|---------|-------------------------------------|-------------------------------------|--------------------------|
| GET     | `/accounts/{account}/import/info`   | ImportController@info               | accounts.import.info     |
| POST    | `/accounts/{account}/import`        | ImportController@import             | accounts.import          |
| POST    | `/accounts/sync-followers`          | ImportController@syncFollowers      | accounts.syncFollowers   |

#### OAuth Facebook/Instagram
| Méthode | URL                          | Action                              | Nom                |
|---------|------------------------------|-------------------------------------|---------------------|
| GET     | `/auth/facebook/redirect`    | FacebookOAuthController@redirect    | facebook.redirect   |
| GET     | `/auth/facebook/callback`    | FacebookOAuthController@callback    | facebook.callback   |
| GET     | `/auth/facebook/select`      | FacebookOAuthController@select      | facebook.select     |
| POST    | `/auth/facebook/connect`     | FacebookOAuthController@connect     | facebook.connect    |

#### OAuth Threads
| Méthode | URL                         | Action                              | Nom                |
|---------|-----------------------------|-------------------------------------|---------------------|
| GET     | `/auth/threads/redirect`    | ThreadsOAuthController@redirect     | threads.redirect    |
| GET     | `/auth/threads/callback`    | ThreadsOAuthController@callback     | threads.callback    |

#### OAuth YouTube
| Méthode | URL                         | Action                              | Nom                |
|---------|-----------------------------|-------------------------------------|---------------------|
| GET     | `/oauth/youtube/redirect`   | YouTubeOAuthController@redirect     | youtube.redirect    |
| GET     | `/oauth/youtube/callback`   | YouTubeOAuthController@callback     | youtube.callback    |
| GET     | `/oauth/youtube/select`     | YouTubeOAuthController@select       | youtube.select      |
| POST    | `/oauth/youtube/store`      | YouTubeOAuthController@store        | youtube.store       |

#### Gestion des plateformes
| Méthode | URL                                    | Action                                  | Nom                          |
|---------|----------------------------------------|-----------------------------------------|------------------------------|
| GET     | `/platforms/facebook`                  | PlatformController@facebook             | platforms.facebook           |
| GET     | `/platforms/threads`                   | PlatformController@threads              | platforms.threads            |
| GET     | `/platforms/telegram`                  | PlatformController@telegram             | platforms.telegram           |
| GET     | `/platforms/twitter`                   | PlatformController@twitter              | platforms.twitter            |
| GET     | `/platforms/youtube`                   | PlatformController@youtube              | platforms.youtube            |
| POST    | `/platforms/telegram/validate-bot`     | PlatformController@validateTelegramBot  | platforms.telegram.validateBot |
| POST    | `/platforms/telegram/register-bot`     | PlatformController@registerTelegramBot  | platforms.telegram.registerBot |
| POST    | `/platforms/telegram/add-channel`      | PlatformController@addTelegramChannel   | platforms.telegram.addChannel |
| DELETE  | `/platforms/telegram/bot`              | PlatformController@destroyTelegramBot   | platforms.telegram.destroyBot |
| POST    | `/platforms/twitter/add-account`       | PlatformController@addTwitterAccount    | platforms.twitter.addAccount |
| POST    | `/platforms/twitter/validate-account`  | PlatformController@validateTwitterAccount | platforms.twitter.validateAccount |
| DELETE  | `/platforms/account/{account}`         | PlatformController@destroyAccount       | platforms.destroyAccount     |

#### Personas IA (admin)
| Méthode | URL                          | Action                        | Nom              |
|---------|------------------------------|-------------------------------|-------------------|
| GET     | `/personas`                  | PersonaController@index       | personas.index    |
| GET     | `/personas/create`           | PersonaController@create      | personas.create   |
| POST    | `/personas`                  | PersonaController@store       | personas.store    |
| GET     | `/personas/{persona}/edit`   | PersonaController@edit        | personas.edit     |
| PATCH   | `/personas/{persona}`        | PersonaController@update      | personas.update   |
| DELETE  | `/personas/{persona}`        | PersonaController@destroy     | personas.destroy  |

#### Flux RSS (admin)
| Méthode | URL                              | Action                          | Nom                |
|---------|----------------------------------|---------------------------------|--------------------|
| GET     | `/rss-feeds`                     | RssFeedController@index         | rss-feeds.index    |
| GET     | `/rss-feeds/create`              | RssFeedController@create        | rss-feeds.create   |
| POST    | `/rss-feeds`                     | RssFeedController@store         | rss-feeds.store    |
| GET     | `/rss-feeds/{rssFeed}/edit`      | RssFeedController@edit          | rss-feeds.edit     |
| PATCH   | `/rss-feeds/{rssFeed}`           | RssFeedController@update        | rss-feeds.update   |
| DELETE  | `/rss-feeds/{rssFeed}`           | RssFeedController@destroy       | rss-feeds.destroy  |
| POST    | `/rss-feeds/{rssFeed}/fetch`     | RssFeedController@fetchNow      | rss-feeds.fetch    |
| POST    | `/rss-feeds/{rssFeed}/generate`  | RssFeedController@generateNow   | rss-feeds.generate |

#### Analytics et paramètres
| Méthode | URL                    | Action                    | Nom              |
|---------|------------------------|---------------------------|-------------------|
| GET     | `/stats/dashboard`     | StatsController@dashboard | stats.dashboard   |
| GET     | `/settings`            | SettingsController@index  | settings.index    |
| PATCH   | `/settings`            | SettingsController@update | settings.update   |

#### Médiathèque
| Méthode | URL                              | Action                       | Nom              |
|---------|----------------------------------|------------------------------|-------------------|
| GET     | `/media`                         | MediaController@index        | media.index       |
| POST    | `/media/upload`                  | MediaController@upload       | media.upload      |
| GET     | `/media/list`                    | MediaController@list         | media.list        |
| GET     | `/media/thumbnail/{filename}`    | MediaController@thumbnail    | media.thumbnail   |
| DELETE  | `/media/{filename}`              | MediaController@destroy      | media.destroy     |

#### API / Utilitaires
| Méthode | URL                      | Action                    | Nom                |
|---------|--------------------------|---------------------------|---------------------|
| GET     | `/api/locations/search`  | LocationController@search | locations.search    |
| GET     | `/api/hashtags`          | HashtagController@index   | hashtags.index      |

### Authentifiées (`auth` uniquement)
| Méthode | URL         | Action                    | Nom             |
|---------|-------------|---------------------------|-----------------|
| GET     | `/profile`  | ProfileController@edit    | profile.edit    |
| PATCH   | `/profile`  | ProfileController@update  | profile.update  |
| DELETE  | `/profile`  | ProfileController@destroy | profile.destroy |

### Scheduler (`routes/console.php`)
```php
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('rss:generate')->cron('0 */6 * * *')->withoutOverlapping();
Schedule::command('stats:sync')->{fréquence configurable via Settings}->withoutOverlapping();
```

---

## DashboardController

**Fichier** : `app/Http/Controllers/DashboardController.php`

### `index(Request $request)`
Affiche le tableau de bord avec statistiques et listes de posts.

**Données passées à la vue** :
- `scheduledCount` - Posts programmés
- `publishedCount` - Posts publiés
- `failedCount` - Posts en erreur
- `draftCount` - Brouillons
- `activeAccountsCount` - Comptes sociaux actifs
- `upcomingPosts` - 5 prochains posts programmés (futur)
- `recentPosts` - 5 derniers posts publiés

**Admin** : voit les données de tous les utilisateurs.
**User** : voit uniquement ses propres données.

---

## PostController

**Fichier** : `app/Http/Controllers/PostController.php`

### `index(Request $request)`
Liste les posts avec vue liste (paginée) et vue calendrier (groupée par jour).

**Paramètres query** :
- `status` - Filtre par statut
- `media_type` - Filtre par type de média (images, videos)
- `month` (YYYY-MM) - Mois affiché sur le calendrier

### `create(Request $request)`
Formulaire de création. Charge les comptes actifs de l'utilisateur groupés par plateforme et les comptes par défaut.

### `store(Request $request)`
Crée un post + ses entrées PostPlatform.

**Validation** :
```php
'content_fr'     => 'required|string|max:10000'
'content_en'     => 'nullable|string|max:10000'
'hashtags'       => 'nullable|string|max:1000'
'auto_translate' => 'boolean'
'media'          => 'nullable|array'
'media.*'        => 'file|max:51200'  // 50MB max par fichier
'link_url'       => 'nullable|url|max:2048'
'location_name'  => 'nullable|string|max:255'
'location_id'    => 'nullable|string|max:255'
'accounts'       => 'required|array|min:1'
'accounts.*'     => 'exists:social_accounts,id'
'scheduled_at'   => 'nullable|date|after:now'
'publish_now'    => 'boolean'
```

**Logique** :
- Upload des médias vers `storage/app/private/media/` (UUID filename)
- Si `publish_now` : status = scheduled, scheduled_at = now()
- Sinon : status = draft ou scheduled selon scheduled_at
- Crée une entrée PostPlatform par compte sélectionné
- Enregistre l'usage des hashtags (`recordHashtagsUsage`)

### `show(Request $request, int $id)`
Détail du post avec timeline de publication par plateforme + logs. Vérifie l'activation des comptes par utilisateur.

### `edit(Request $request, int $id)`
Formulaire d'édition (interdit si status = publishing ou published). Seul le propriétaire ou un admin peut éditer.

### `update(Request $request, int $id)`
Met à jour le post et re-sync les PostPlatform (supprime les anciens, crée les nouveaux).

### `destroy(Request $request, int $id)`
Supprime le post, ses PostPlatform et PostLogs (en transaction). Interdit si status = publishing.

### `saveDefaultAccounts(Request $request)`
Sauvegarde la sélection de comptes par défaut pour l'utilisateur.

### `syncStats(Request $request, int $id)`
Synchronise les métriques du post (tous les comptes ou une plateforme spécifique via `?platform=`).

---

## PublishController

**Fichier** : `app/Http/Controllers/PublishController.php`

### `publishAll(Request $request, Post $post)`
Publie manuellement toutes les PostPlatform pending/failed d'un post.

### `publishOne(Request $request, PostPlatform $postPlatform)`
Publie manuellement une PostPlatform spécifique de manière synchrone.

### `resetOne(Request $request, PostPlatform $postPlatform)`
Remet une PostPlatform failed/published en statut pending.

---

## SocialAccountController

**Fichier** : `app/Http/Controllers/SocialAccountController.php`

### `index(Request $request)`
Liste les comptes groupés par plateforme.

**Many-to-many** : Les comptes sont chargés via `$user->socialAccounts()`. Les admins voient tous les comptes.

### `create()`
Formulaire avec sélection de plateforme et champs dynamiques selon `platform.config.credential_fields`.

### `store(Request $request)`
Crée un compte. Les credentials sont chiffrés (encrypted:array cast). Ne stocke que les champs attendus par la config de la plateforme.

### `edit / update`
Édition. Les credentials existants sont conservés si le champ est laissé vide (merge).

### `destroy(Request $request, int $id)`
Supprime le compte. Interdit si des posts sont en pending/publishing.

### `toggleActive(Request $request, int $id)`
Toggle `is_active` dans la table pivot. Retourne du JSON (utilisé en AJAX avec Alpine.js).

---

## ImportController

**Fichier** : `app/Http/Controllers/ImportController.php`

### `info(Request $request, int $accountId)`
Retourne les informations d'import (coût quota, statut cooldown) pour un compte.

### `import(Request $request, int $accountId)`
Importe les posts historiques d'un compte (limite clamped entre 1-200).

### `syncFollowers(Request $request)`
Synchronise les compteurs d'abonnés pour tous les comptes actifs (admin uniquement).

---

## StatsController

**Fichier** : `app/Http/Controllers/StatsController.php`

### `dashboard(Request $request)`
Affiche le tableau de bord analytics avec filtres.

**Paramètres query** :
- `accounts` - Filtre par comptes (array)
- `period` - Période (7/30/90/custom)
- `start_date` / `end_date` - Dates personnalisées

**Données calculées** : stats agrégées (views, likes, comments, shares, engagement rate), top posts, stats par plateforme, stats par compte, timeline.

---

## AiAssistController

**Fichier** : `app/Http/Controllers/AiAssistController.php`

### `generate(Request $request)`
Génère du contenu via IA en utilisant la persona du compte.

**Validation** :
```php
'content'    => 'nullable|string|max:10000'
'account_id' => 'required|exists:social_accounts,id'
```

---

## PersonaController

**Fichier** : `app/Http/Controllers/PersonaController.php`

CRUD complet pour les personas IA (admin uniquement).

**Validation** :
```php
'name'                => 'required|string|max:255'
'description'         => 'nullable|max:500'
'system_prompt'       => 'required|max:5000'
'output_instructions' => 'nullable|max:2000'
'tone'                => 'nullable|max:100'
'language'            => 'required|max:10'
'is_active'           => 'boolean'
```

---

## RssFeedController

**Fichier** : `app/Http/Controllers/RssFeedController.php`

CRUD complet pour les flux RSS (admin uniquement).

### `store / update`
Crée/modifie un flux RSS avec ses associations de comptes sociaux.

**Validation** :
```php
'name'                => 'required|string|max:255'
'url'                 => 'required|url|max:2000'
'description'         => 'nullable|max:500'
'category'            => 'nullable|max:100'
'is_active'           => 'boolean'
'is_multi_part_sitemap' => 'boolean'
'accounts'            => 'nullable|array'
// Chaque account contient : persona_id, auto_post, post_frequency, max_posts_per_day
```

### `fetchNow(Request $request, RssFeed $rssFeed)`
Déclenche un fetch immédiat des articles du flux.

### `generateNow(Request $request, RssFeed $rssFeed)`
Déclenche la génération immédiate de posts à partir des articles via Artisan.

---

## PlatformController

**Fichier** : `app/Http/Controllers/PlatformController.php`

Pages de gestion par plateforme (affichent les comptes et options de connexion).

### `facebook(Request $request)`
Page Facebook/Instagram : affiche les comptes Facebook et Instagram liés.

### `threads(Request $request)`
Page Threads : affiche les comptes Threads.

### `telegram(Request $request)`
Page Telegram : affiche les bots et channels, groupés par bot_token.

### `twitter(Request $request)`
Page Twitter/X : affiche les comptes groupés par api_key.

### `youtube(Request $request)`
Page YouTube : affiche les chaînes YouTube.

### `validateTelegramBot(Request $request)`
Valide un token de bot Telegram via l'API getMe (AJAX).

### `registerTelegramBot(Request $request)`
Enregistre un bot Telegram (valide + sauvegarde).

### `addTelegramChannel(Request $request)`
Ajoute un channel Telegram après validation du bot et du channel (API getChat).

### `addTwitterAccount(Request $request)`
Ajoute un compte Twitter/X avec les 4 clés OAuth 1.0a.

### `validateTwitterAccount(Request $request)`
Valide les credentials Twitter via GET /2/users/me (AJAX).

### `destroyTelegramBot(Request $request)`
Supprime un bot Telegram et tous ses channels associés.

### `destroyAccount(Request $request, SocialAccount $account)`
Supprime un compte social (admin ou propriétaire).

---

## MediaController

**Fichier** : `app/Http/Controllers/MediaController.php`

### `index(Request $request)`
Page médiathèque avec filtrage (all/images/videos). Affiche les métadonnées et l'usage dans les posts.

### `upload(Request $request)`
Upload d'un fichier média (AJAX). Compression automatique des images, conversion des vidéos non-H.264 en MP4.

**Validation** :
```php
'file' => 'required|max:51200|mimes:jpeg,jpg,png,gif,webp,mp4,mov,avi,webm'
```

### `list(Request $request)`
Liste tous les fichiers médias en JSON pour le sélecteur dans les formulaires.

### `thumbnail(Request $request, string $filename)`
Sert un thumbnail vidéo généré via ffmpeg (frame à 5 secondes, cache JPEG).

### `destroy(Request $request, string $filename)`
Supprime un fichier média.

### `show(Request $request, string $filename)`
Sert un fichier média privé depuis `storage/app/private/media/`.

**Accès autorisé si** :
1. L'utilisateur est authentifié (navigation web), **OU**
2. L'URL a une signature valide (URLs signées pour les APIs externes)

**HTTP Range** : Supporte les requêtes Range pour le streaming vidéo.

---

## FacebookOAuthController

**Fichier** : `app/Http/Controllers/FacebookOAuthController.php`

### `redirect()`
Redirige vers Facebook Login pour obtenir l'autorisation OAuth.

**Scopes** : `pages_show_list`, `pages_read_engagement`, `pages_read_user_content`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish`

**Config** : Utilise `FACEBOOK_CONFIG_ID` pour Facebook Login for Business.

### `callback(Request $request)`
Callback OAuth après autorisation.

1. Échange le code contre un access token (user token)
2. Récupère les pages via `/me/accounts` (fallback: `debug_token` + query par page_id)
3. Pour chaque page : récupère les détails + vérifie si un compte Instagram est lié
4. Redirige vers la page de sélection

**Fallback** : Si `/me/accounts` retourne vide, utilise `debug_token` → `granular_scopes` → `pages_show_list.target_ids`.

### `select()`
Affiche la page de sélection des pages/comptes à connecter.

### `connect(Request $request)`
Crée/met à jour les SocialAccount depuis les pages sélectionnées et les attache à l'utilisateur.

---

## ThreadsOAuthController

**Fichier** : `app/Http/Controllers/ThreadsOAuthController.php`

### `redirect()`
Redirige vers Threads OAuth. **Scopes** : `threads_basic`, `threads_content_publish`

### `callback(Request $request)`
Callback OAuth : échange le code, récupère le profil Threads, crée/met à jour le SocialAccount.

---

## YouTubeOAuthController

**Fichier** : `app/Http/Controllers/YouTubeOAuthController.php`

### `redirect()`
Redirige vers Google OAuth. **Scopes** : `youtube.upload`, `youtube.readonly`

### `callback(Request $request)`
Callback OAuth : échange le code contre access_token + refresh_token, récupère les infos de la chaîne.

### `select()`
Page de sélection de la chaîne YouTube.

### `store(Request $request)`
Crée/met à jour le SocialAccount YouTube avec les credentials (access_token, refresh_token, channel_id).

---

## LocationController

**Fichier** : `app/Http/Controllers/LocationController.php`

### `search(Request $request)`
Recherche de lieux via l'API Graph Facebook.

**Paramètres** : `q` (query), `type`, `center` (coordonnées optionnelles)

---

## HashtagController

**Fichier** : `app/Http/Controllers/HashtagController.php`

### `index(Request $request)`
Retourne les 20 hashtags les plus utilisés par l'utilisateur connecté (JSON).

---

## SettingsController

**Fichier** : `app/Http/Controllers/SettingsController.php`

### `index()`
Affiche la page de configuration globale (admin uniquement).

### `update(Request $request)`
Met à jour les paramètres globaux via le modèle `Setting`.

**Paramètres gérés** :
- **Images** : `image_max_dimension`, `image_target_min_kb`, `image_target_max_kb`, `image_min_quality`, `image_max_upload_mb`
- **Vidéos** : `video_max_upload_mb`, `video_bitrate_1080p`, `video_bitrate_720p`, `video_codec`, `video_audio_bitrate`
- **Stats** : `stats_sync_frequency`, `stats_{platform}_interval` (heures), `stats_{platform}_max_days` (jours)
- **IA** : `openai_api_key` (chiffré)

**Accès** : Réservé aux administrateurs (`is_admin = true`)
