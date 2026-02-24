# Adapteurs de plateformes

## Interface commune

**Fichier** : `app/Services/Adapters/PlatformAdapterInterface.php`

```php
public function publish(SocialAccount $account, string $content, ?array $media = null): array;
```

**Retour attendu** :
```php
[
    'success'     => bool,
    'external_id' => ?string,  // ID du post sur la plateforme
    'error'       => ?string,  // Message d'erreur si échec
]
```

---

## Telegram

**Fichier** : `app/Services/Adapters/TelegramAdapter.php`
**API** : Telegram Bot API
**Credentials** : `bot_token`, `chat_id`

### Fonctionnement

| Cas                    | Méthode API             | Notes                              |
|------------------------|-------------------------|------------------------------------|
| Texte seul             | `sendMessage`           | parse_mode = HTML                  |
| 1 image                | `sendPhoto`             | caption = contenu                  |
| 1 vidéo                | `sendVideo`             | caption = contenu                  |
| 2+ médias              | `sendMediaGroup`        | caption sur le 1er élément         |

### Particularités
- Le `chat_id` peut être override par le champ `Post.telegram_channel`
- Parse mode HTML : supporte `<b>`, `<i>`, `<a>`, etc.
- Media group : caption uniquement sur le premier élément (limite API Telegram)

---

## Facebook

**Fichier** : `app/Services/Adapters/FacebookAdapter.php`
**API** : Graph API v21.0
**Credentials** : `page_id`, `access_token`

### Fonctionnement

| Cas                    | Endpoint                          | Notes                              |
|------------------------|-----------------------------------|------------------------------------|
| Texte seul             | `/{page_id}/feed`                 | Détecte les liens pour preview     |
| 1 image                | `/{page_id}/photos`               | message + url                      |
| 1 vidéo                | `/{page_id}/videos`               | description + file_url             |
| 2+ images              | Multi-photo (unpublished → feed)  | Upload chaque photo, puis post     |
| Mix image+vidéo        | 1er média seulement               | Fallback : publie le premier       |

### Particularités
- Les liens dans le contenu sont extraits automatiquement pour le `link` preview
- Multi-photo : chaque photo est d'abord uploadée en `published=false`, puis regroupée dans un post `/feed`
- Les vidéos ne supportent pas le multi-upload natif

---

## Instagram

**Fichier** : `app/Services/Adapters/InstagramAdapter.php`
**API** : Graph API v21.0 (Container API)
**Credentials** : `account_id`, `access_token`

### Fonctionnement

| Cas                    | Processus                                | Notes                              |
|------------------------|------------------------------------------|------------------------------------|
| Texte seul             | ❌ Non supporté                          | Instagram requiert un média        |
| 1 image                | Créer container → Publier                | image_url + caption                |
| 1 vidéo (Reel)         | Créer container → Poll status → Publier  | video_url + caption                |
| 2+ médias (Carousel)   | Créer children → Créer carousel → Publier| Jusqu'à 10 éléments               |

### Processus vidéo
1. Créer le container avec `media_type=REELS`
2. Poll le statut toutes les 5 secondes (max 30 tentatives = 2.5 min)
3. Attendre `status_code = FINISHED`
4. Publier le container

### Particularités
- Instagram ne supporte PAS les posts texte seul → retourne une erreur
- Les Carousels supportent un mix d'images et vidéos
- Les vidéos sont traitées de manière asynchrone côté Instagram

---

## Twitter/X

**Fichier** : `app/Services/Adapters/TwitterAdapter.php`
**API** : v2 (tweets) + v1.1 (media upload)
**Credentials** : `api_key`, `api_secret`, `access_token`, `access_token_secret`

### Fonctionnement

| Cas                    | Processus                                | Notes                              |
|------------------------|------------------------------------------|------------------------------------|
| Texte seul             | POST `/2/tweets`                         | OAuth 1.0a HMAC-SHA1              |
| Avec médias            | Upload → POST `/2/tweets` avec media_ids | Max 4 médias                      |

### Processus média
1. Télécharge chaque média en fichier temporaire
2. Upload vers `https://upload.twitter.com/1.1/media/upload.json`
3. Récupère le `media_id_string`
4. Crée le tweet avec les `media_ids` attachés
5. Supprime les fichiers temporaires

### Authentification OAuth 1.0a
- Signature HMAC-SHA1 construite manuellement
- Paramètres OAuth dans le header Authorization
- Nonce + timestamp générés à chaque requête
- Signature = base64(HMAC-SHA1(signing_key, base_string))

### Particularités
- L'upload média utilise l'API v1.1 (pas v2)
- La création de tweet utilise l'API v2
- Max 4 médias par tweet (limite Twitter)
- Le contenu est tronqué à 280 caractères (limite Twitter non gérée côté app)
