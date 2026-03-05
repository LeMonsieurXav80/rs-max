# Messagerie (Inbox)

## Vue d'ensemble

Boite de reception unifiee pour les commentaires, reponses et messages prives provenant des 8 plateformes : Facebook, Instagram, Threads, YouTube, Bluesky, Telegram, Reddit, Twitter.

---

## Architecture

### Composants principaux

| Composant | Fichier | Role |
|---|---|---|
| `InboxController` | `app/Http/Controllers/InboxController.php` | Routes, filtres, regroupement conversations, reponses IA |
| `InboxSyncService` | `app/Services/Inbox/InboxSyncService.php` | Orchestration du sync (all / platforms / account) |
| `PlatformInboxInterface` | `app/Services/Inbox/PlatformInboxInterface.php` | Contrat pour chaque service plateforme |
| `InboxItem` | `app/Models/InboxItem.php` | Modele Eloquent |
| 8 services plateforme | `app/Services/Inbox/{Platform}InboxService.php` | `fetchInbox()` + `sendReply()` par plateforme |

### Pattern `conversation_key`

Chaque service plateforme assigne un `conversation_key` a chaque item. Ce champ determine le regroupement en conversations dans l'UI.

| Plateforme | Type | conversation_key | Comportement |
|---|---|---|---|
| Facebook | comment | `{comment_id}` | Chaque commentaire = conversation |
| Instagram | comment | `{comment_id}` | Commentaire racine = conversation |
| Instagram | reply | `{parent_comment_id}` | Reponses groupees avec parent |
| YouTube | comment | `{top_comment_id}` | Commentaire racine = conversation |
| YouTube | reply | `{top_comment_id}` | Reponses groupees avec parent |
| Twitter | comment | `{replied_to_id ou tweet_id}` | Standalone ou thread avec parent |
| Threads | reply | `post:{thread_id}` | Toutes les reponses du meme thread |
| Bluesky | comment | `post:{post_uri\|cid}` | Toutes les reponses du meme post |
| Bluesky | dm | `dm:{convo_id}` | Messages d'une meme conversation |
| Telegram | dm | `dm:{chat_id}` | Messages d'un meme chat |
| Reddit | comment | `{parent_id ou external_id}` | Thread avec parent ou standalone |
| Reddit | dm | `dm:{external_id}` | Chaque DM standalone |

### Fusion des chaines de reponse

Quand quelqu'un repond a NOTRE reponse (`parent_id` pointe vers notre `reply_external_id`), le controleur fusionne automatiquement dans la conversation originale. Ce mecanisme est agnostique de la plateforme.

```
Item A (conversation_key = "abc123")
  └─ Notre reponse (reply_external_id = "xyz789")
      └─ Item B (parent_id = "xyz789") → fusionne dans conversation_key "abc123"
```

Le regroupement final utilise la cle composite `{social_account_id}:{conversation_key}`.

---

## Modele de donnees

### Table `inbox_items`

| Colonne | Type | Description |
|---|---|---|
| `social_account_id` | FK | Compte ayant recu l'item |
| `platform_id` | FK | Plateforme |
| `type` | string(20) | `comment`, `reply`, `dm` |
| `external_id` | string | ID plateforme de l'item |
| `external_post_id` | string? | ID du post/thread parent |
| `parent_id` | string? | ID de l'item parent (pour les reponses) |
| `conversation_key` | string | Cle de regroupement (defini par le service plateforme) |
| `author_name` | string? | Nom affiche de l'auteur |
| `author_username` | string? | Username de l'auteur |
| `author_avatar_url` | string? | Avatar de l'auteur |
| `author_external_id` | string? | ID plateforme de l'auteur |
| `content` | text? | Contenu du message |
| `media_url` | string? | URL du media attache |
| `media_type` | string? | `gif`, `sticker`, `image`, `video` |
| `post_url` | string? | Lien vers l'item original |
| `posted_at` | timestamp? | Date de creation |
| `status` | string(20) | `unread`, `read`, `replied`, `archived` |
| `reply_content` | text? | Texte de notre reponse |
| `reply_external_id` | string? | ID plateforme de notre reponse |
| `replied_at` | timestamp? | Date de reponse |
| `reply_scheduled_at` | timestamp? | Heure de reponse programmee |

---

## Routes

| Methode | URL | Action | Description |
|---|---|---|---|
| GET | `/inbox` | index | Liste des conversations avec filtres |
| POST | `/inbox/mark-read` | markRead | Marquer comme lu |
| POST | `/inbox/archive` | archive | Archiver des items |
| POST | `/inbox/{item}/reply` | reply | Repondre a un item |
| POST | `/inbox/{item}/ai-suggest` | aiSuggest | Suggestion de reponse IA |
| POST | `/inbox/bulk-ai-reply` | bulkAiReply | Generer des reponses IA en masse (max 50) |
| POST | `/inbox/bulk-send` | bulkSend | Envoyer plusieurs reponses (avec spread optionnel) |
| GET | `/inbox/scheduled-status` | scheduledStatus | Statut des reponses programmees |
| POST | `/inbox/sync` | sync | Declenchement manuel du sync (admin) |

---

## Filtres

| Filtre | Type | Valeurs |
|---|---|---|
| Status | single | `unreplied` (= unread + read), `unread`, `read`, `replied`, `archived` |
| Type | multi-select | `comment`, `reply`, `dm` |
| Plateforme | multi-select | les 8 plateformes |
| Compte | single | un social account specifique |

Sans filtre de status, les items `archived` sont exclus par defaut.

Quand un filtre de status est actif, le controleur recupere les items lies (meme `conversation_key`) pour afficher le contexte complet de la conversation.

Le filtre `unreplied` exclut aussi les conversations dont le dernier message provient du compte propre (on a deja repondu, pas de nouveau message).

---

## Reponse IA

- Utilise l'API OpenAI avec le `system_prompt` de la persona du compte
- Prompt wrapper configurable via `inbox_reply_prompt` (Setting)
- Modele configurable via `ai_model_inbox` (fallback sur `ai_model_text`)
- Adapte le style au message recu : emoji-only, texte + emojis, texte pur
- Inclut le contexte de conversation (jusqu'a 10 messages precedents)
- Repond dans la meme langue que le message recu
- 500ms de delai entre les appels API en mode bulk

---

## Reponses programmees (Spread)

L'envoi en masse peut etaler les reponses sur une periode (`spread_minutes`, max 1440 min = 24h).

1. `bulkSend` avec `spread_minutes > 0` : stocke `reply_content` + `reply_scheduled_at` sans envoyer
2. `inbox:send-scheduled` (chaque minute via scheduler) : envoie les reponses dont l'heure est passee
3. L'UI affiche la progression via `scheduledStatus`

L'intervalle entre chaque reponse = `(spread_minutes * 60) / nombre_de_reponses` secondes.

---

## Synchronisation

### Automatique

**Fichier** : `routes/console.php`

```php
Schedule::command('inbox:sync')->{frequence configurable}->withoutOverlapping(10);
Schedule::command('inbox:send-scheduled')->everyMinute()->withoutOverlapping(5);
```

Frequence configurable via Setting `inbox_sync_frequency` (defaut : `every_15_min`).

### Manuelle

POST `/inbox/sync` (admin uniquement). Supporte :
- `account_id` : sync d'un seul compte
- `platforms` : sync de plateformes specifiques
- Sans parametre : sync de tout

### Activation par plateforme

Chaque plateforme peut etre activee/desactivee via Setting `inbox_platform_{slug}_enabled` (defaut : `true`).

### Deduplication

`InboxSyncService::storeItems()` verifie les `external_id` existants pour la meme `platform_id` avant insertion. Pas de doublons.

---

## Services plateforme

Chaque service implemente `PlatformInboxInterface` :

```php
interface PlatformInboxInterface
{
    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection;
    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array;
}
```

- `fetchInbox()` : retourne une Collection de tableaux avec les champs `InboxItem` (non persistes)
- `sendReply()` : retourne `['success' => bool, 'external_id' => ?string, 'error' => ?string]`

Le mapping service/plateforme est dans `InboxSyncService::getServiceForPlatform()`.
