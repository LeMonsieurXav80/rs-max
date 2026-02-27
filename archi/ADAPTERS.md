# Services : Adapteurs, Import, Stats, RSS et IA

## 1. Adapteurs de publication

### Interface commune

**Fichier** : `app/Services/Adapters/PlatformAdapterInterface.php`

```php
public function publish(SocialAccount $account, string $content, ?array $media = null, ?array $options = null): array;
```

**Paramètre `$options`** : Tableau optionnel contenant des données additionnelles (ex: `location_id`, `location_name` pour le geotagging).

**Retour attendu** :
```php
[
    'success'     => bool,
    'external_id' => ?string,  // ID du post sur la plateforme
    'error'       => ?string,  // Message d'erreur si échec
]
```

---

### Telegram

**Fichier** : `app/Services/Adapters/TelegramAdapter.php`
**API** : Telegram Bot API
**Credentials** : `bot_token`, `chat_id`

| Cas                    | Méthode API             | Notes                              |
|------------------------|-------------------------|------------------------------------|
| Texte seul             | `sendMessage`           | parse_mode = HTML                  |
| 1 image                | `sendPhoto`             | caption = contenu                  |
| 1 vidéo                | `sendVideo`             | caption = contenu                  |
| 2+ médias              | `sendMediaGroup`        | caption sur le 1er élément         |

**Particularités** :
- Le `chat_id` peut être override par le champ `Post.telegram_channel`
- Parse mode HTML : supporte `<b>`, `<i>`, `<a>`, etc.
- Media group : caption uniquement sur le premier élément (limite API Telegram)
- **Conversion vidéo automatique** : Les vidéos HEVC/H.265 sont automatiquement converties en H.264/AAC MP4. Utilise FFmpeg avec `libx264` et `aac`.

---

### Facebook

**Fichier** : `app/Services/Adapters/FacebookAdapter.php`
**API** : Graph API v21.0
**Credentials** : `page_id`, `access_token`

| Cas                    | Endpoint                          | Notes                              |
|------------------------|-----------------------------------|------------------------------------|
| Texte seul             | `/{page_id}/feed`                 | Détecte les liens pour preview     |
| 1 image                | `/{page_id}/photos`               | message + url                      |
| 1 vidéo                | `/{page_id}/videos`               | description + file_url             |
| 2+ images              | Multi-photo (unpublished → feed)  | Upload chaque photo, puis post     |
| Mix image+vidéo        | 1er média seulement               | Fallback : publie le premier       |

**Particularités** :
- Les liens dans le contenu sont extraits automatiquement pour le `link` preview
- Multi-photo : chaque photo est d'abord uploadée en `published=false`, puis regroupée
- Support du geolocation via `$options['location_id']` (place_id Facebook)

---

### Instagram

**Fichier** : `app/Services/Adapters/InstagramAdapter.php`
**API** : Graph API v21.0 (Container API)
**Credentials** : `account_id`, `access_token`

| Cas                    | Processus                                | Notes                              |
|------------------------|------------------------------------------|------------------------------------|
| Texte seul             | ❌ Non supporté                          | Instagram requiert un média        |
| 1 image                | Créer container → Publier                | image_url + caption                |
| 1 vidéo (Reel)         | Créer container → Poll status → Publier  | video_url + caption                |
| 2+ médias (Carousel)   | Créer children → Créer carousel → Publier| Jusqu'à 10 éléments               |

**Processus vidéo** : Poll toutes les 5 secondes (max 30 tentatives = 2.5 min), attend `status_code = FINISHED`.

**Particularités** :
- Instagram ne supporte PAS les posts texte seul
- Carousels supportent un mix images + vidéos
- Support du geolocation via `$options['location_id']`

---

### Twitter/X

**Fichier** : `app/Services/Adapters/TwitterAdapter.php`
**API** : v2 (tweets) + v1.1 (media upload)
**Credentials** : `api_key`, `api_secret`, `access_token`, `access_token_secret`

| Cas                    | Processus                                | Notes                              |
|------------------------|------------------------------------------|------------------------------------|
| Texte seul             | POST `/2/tweets`                         | OAuth 1.0a HMAC-SHA1              |
| Avec médias            | Upload → POST `/2/tweets` avec media_ids | Max 4 médias                      |

**Authentification OAuth 1.0a** :
- Signature HMAC-SHA1 construite manuellement
- L'upload média utilise l'API v1.1, la création de tweet l'API v2
- Max 4 médias par tweet

---

### Threads

**Fichier** : `app/Services/Adapters/ThreadsAdapter.php`
**API** : Threads Graph API v1.0
**Credentials** : `user_id`, `access_token`

| Cas                    | Processus                                | Notes                              |
|------------------------|------------------------------------------|------------------------------------|
| Texte seul             | Créer container → Publier                | media_type = TEXT                  |
| 1 image                | Créer container → Publier                | media_type = IMAGE                 |
| 1 vidéo                | Créer container → Poll status → Publier  | media_type = VIDEO                 |
| 2+ médias (Carousel)   | Créer children → Créer carousel → Publier| Jusqu'à 10 éléments               |

**Particularités** :
- Threads supporte les posts texte seul (contrairement à Instagram)
- Polling léger (10 tentatives × 1s) pour tous les types de containers
- Support du geolocation via `$options['location_id']`

---

### YouTube

**Fichier** : `app/Services/Adapters/YouTubeAdapter.php`
**API** : YouTube Data API v3 (upload résumable)
**Credentials** : `channel_id`, `access_token`, `refresh_token`

| Cas                    | Processus                                | Notes                              |
|------------------------|------------------------------------------|------------------------------------|
| Texte seul             | ❌ Non supporté                          | YouTube requiert une vidéo         |
| Vidéo                  | Refresh token → Init upload → Upload     | Resumable upload protocol          |

**Processus d'upload** :
1. Rafraîchit le token d'accès via `YouTubeTokenHelper::getFreshAccessToken()`
2. Initie un upload résumable avec les métadonnées (titre, description, tags)
3. Upload le contenu vidéo vers l'URL reçue

**Métadonnées** :
- Titre : extrait de la première ligne du contenu (max 100 chars)
- Tags : extraits des hashtags dans le contenu
- Catégorie : "People & Blogs" (categoryId=22)
- Privacy : public/unlisted/private

**YouTubeTokenHelper** (`app/Services/YouTubeTokenHelper.php`) :
- Méthode statique `getFreshAccessToken(SocialAccount $account)` pour rafraîchir les tokens expirés (1h)
- POST vers `https://oauth2.googleapis.com/token` avec le refresh_token
- Sauvegarde automatique du nouveau token dans les credentials

---

### Credentials par plateforme

| Plateforme | Champs dans `credentials`                                     |
|------------|---------------------------------------------------------------|
| Facebook   | `page_id`, `access_token`                                     |
| Instagram  | `account_id`, `access_token`                                  |
| Telegram   | `bot_token`, `chat_id`, `bot_name`, `bot_username`, `type`    |
| Twitter/X  | `api_key`, `api_secret`, `access_token`, `access_token_secret`|
| Threads    | `user_id`, `access_token`                                     |
| YouTube    | `channel_id`, `access_token`, `refresh_token`                 |

---

## 2. Services d'import historique

### Interface commune

**Fichier** : `app/Services/Import/PlatformImportInterface.php`

```php
public function importHistory(SocialAccount $account, int $limit = 50): Collection;
public function getQuotaCost(int $postCount): array;
```

Retourne une collection de `ExternalPost`. Quota cost retourne `['cost' => int, 'description' => string]`.

### ImportService (orchestrateur)

**Fichier** : `app/Services/Import/ImportService.php`

- `import(SocialAccount $account, int $limit)` → route vers le service spécifique
- `canImport(SocialAccount $account)` → vérifie les cooldowns
- Retourne `['success' => bool, 'imported' => int, 'error' => ?string]`

### FacebookImportService

**Fichier** : `app/Services/Import/FacebookImportService.php`
**API** : Graph API v21.0

- Récupère les posts avec `id,message,full_picture,permalink_url,created_time,likes.summary(true),comments.summary(true),shares`
- Fallback pour les champs d'engagement si `pages_read_engagement` manquant
- Récupère les vues vidéo séparément (extrait les video IDs des permalinks)
- Métriques : views, likes, comments, shares

### InstagramImportService

**Fichier** : `app/Services/Import/InstagramImportService.php`
**API** : Graph API v21.0

- Récupère les médias avec insights : `views,reach,likes,comments,shares,saved`
- Utilise `views` (métrique unifiée remplaçant `impressions`/`plays` deprecated v22.0+)
- Prend le max de views/reach

### TwitterImportService

**Fichier** : `app/Services/Import/TwitterImportService.php`
**API** : v2 (OAuth 1.0a)

- Import des tweets du timeline du compte

### YouTubeImportService

**Fichier** : `app/Services/Import/YouTubeImportService.php`
**API** : YouTube Data API v3

- Import des vidéos uploadées avec statistiques

### ThreadsImportService

**Fichier** : `app/Services/Import/ThreadsImportService.php`
**API** : Threads Graph API v1.0

- Import des posts Threads avec métriques

---

## 3. Services de statistiques

### Interface commune

**Fichier** : `app/Services/Stats/PlatformStatsInterface.php`

```php
public function fetchMetrics(PostPlatform $postPlatform): ?array;
```

**Retour** : `{views: ?int, likes: int, comments: int, shares: ?int, followers: ?int}` ou `null`

### StatsSyncService (orchestrateur)

**Fichier** : `app/Services/Stats/StatsSyncService.php`

- `syncPostPlatform(PostPlatform $pp)` → sync un post si publié avec external_id
- `syncMultiple($postPlatforms)` → sync multiple avec fréquence intelligente
- `shouldSync(PostPlatform $pp)` → détermine si sync nécessaire

**Logique de fréquence** :
- Skip Telegram (pas de stats disponibles)
- Intervalle configurable par plateforme : `Setting::get("stats_{slug}_interval", 24)` heures
- Posts > `Setting::get("stats_{slug}_max_days", 30)` jours → sync manuelle uniquement
- Délai de 100ms entre les requêtes (rate limiting)

### FacebookStatsService

**Fichier** : `app/Services/Stats/FacebookStatsService.php`

- Récupère likes, comments, shares via Graph API

### InstagramStatsService

**Fichier** : `app/Services/Stats/InstagramStatsService.php`

- Métriques : `views,reach,likes,comments,shares,saved` (v22.0+)

### TwitterStatsService

**Fichier** : `app/Services/Stats/TwitterStatsService.php`

- Métriques via API v2 : impressions, likes, retweets

### YouTubeStatsService

**Fichier** : `app/Services/Stats/YouTubeStatsService.php`

- Statistiques vidéo : views, likes, comments

### ThreadsStatsService

**Fichier** : `app/Services/Stats/ThreadsStatsService.php`

- Métriques d'engagement Threads

---

## 4. Services RSS

### RssFetchService

**Fichier** : `app/Services/Rss/RssFetchService.php`

- `fetchFeed(RssFeed $feed)` → fetch un flux, retourne le nombre de nouveaux items
- `fetchAllActiveFeeds()` → fetch tous les flux actifs

**Formats supportés** : RSS 2.0, Atom, RSS 1.0/RDF, XML Sitemaps, Sitemap indexes

**Fonctionnalités** :
- Support des sitemaps multi-parties (post-sitemap1.xml, post-sitemap2.xml...)
- Extraction d'images depuis media:content, media:thumbnail, enclosure
- Dérivation de titres lisibles depuis les URLs
- Déduplication sur GUID
- Filtrage des URLs non-pertinentes (wp-login, /feed, /cart, etc.)
- Cap à 500 URLs total pour les sitemap indexes

### ContentGenerationService

**Fichier** : `app/Services/Rss/ContentGenerationService.php`

- `generate(RssItem $item, Persona $persona, SocialAccount $account)` → génère du contenu

**Processus** :
1. Récupère le contenu complet de l'article via `ArticleFetchService`
2. Détermine la langue (français par défaut, override via compte)
3. Construit le prompt avec le titre, l'URL, le contenu, le nom du compte
4. Appelle OpenAI (gpt-4o-mini, temperature 0.7, max 2000 tokens)

### ArticleFetchService

**Fichier** : `app/Services/Rss/ArticleFetchService.php`

- `fetchArticleContent(string $url)` → extrait le texte principal (timeout 15s)
- `fetchPageTitle(string $url)` → extraction légère du titre (premiers 10KB)
- `fetchPageMeta(string $url)` → retourne `['title' => ..., 'content' => ...]`

**Extraction** : `<article>` → `<main>` → classes CSS communes → `<body>`. Supprime nav, header, footer, scripts. Tronque à 10 000 chars.

---

## 5. Services auxiliaires

### AiAssistService

**Fichier** : `app/Services/AiAssistService.php`

- `generate(string $content, Persona $persona, ?SocialAccount $account)` → génère ou réécrit du contenu
- Si contenu fourni : réécriture/amélioration
- Si contenu vide : génération from scratch
- Langues supportées : fr, en, pt, es, de, it
- OpenAI gpt-4o-mini, temperature 0.7, max 2000 tokens

### FollowersService

**Fichier** : `app/Services/FollowersService.php`

- `syncFollowers(SocialAccount $account)` → sync et retourne le count

| Plateforme | API/Méthode                           | Champ                              |
|------------|---------------------------------------|------------------------------------|
| Facebook   | Graph v21.0 GET /{pageId}             | followers_count                    |
| Instagram  | Graph v21.0 GET /{igUserId}           | followers_count                    |
| Twitter    | API v2 GET /users/me (OAuth 1.0a)     | public_metrics.followers_count     |
| YouTube    | Data API v3 GET /channels             | statistics.subscriberCount         |
| Telegram   | Bot API getChatMemberCount            | result                             |
| Threads    | graph.threads.net /threads_insights   | followers_count total_value        |

### TranslationService

**Fichier** : `app/Services/TranslationService.php`

- `translate(string $text, string $from, string $to, ?string $apiKey)` → traduction via OpenAI
- Modèle : gpt-4o-mini
- Réinterprétation naturelle (pas traduction littérale)
- Préserve emojis, formatage, ton
- Timeout : 30 secondes
