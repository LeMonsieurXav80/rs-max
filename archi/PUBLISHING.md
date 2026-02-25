# Pipeline de publication

## Vue d'ensemble

```
                    ┌─────────────────┐
                    │  Utilisateur    │
                    │  crée un post   │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │   Post créé     │
                    │  (draft ou      │
                    │   scheduled)    │
                    └────────┬────────┘
                             │
              ┌──────────────▼──────────────┐
              │  Scheduler (chaque minute)   │
              │  posts:publish-scheduled     │
              └──────────────┬──────────────┘
                             │
              ┌──────────────▼──────────────┐
              │  PublishingService::publish()│
              │  1. Auto-traduction          │
              │  2. Status → publishing      │
              │  3. Dispatch jobs            │
              └──────────────┬──────────────┘
                             │
           ┌─────────────────┼─────────────────┐
           │                 │                  │
    ┌──────▼──────┐  ┌──────▼──────┐  ┌───────▼──────┐
    │ Job:        │  │ Job:        │  │ Job:         │
    │ Telegram    │  │ Facebook    │  │ Instagram    │
    │ Adapter     │  │ Adapter     │  │ Adapter      │
    └──────┬──────┘  └──────┬──────┘  └───────┬──────┘
           │                │                  │
           └─────────────────┼─────────────────┘
                             │
              ┌──────────────▼──────────────┐
              │  Mise à jour des statuts     │
              │  PostPlatform + Post         │
              └─────────────────────────────┘
```

---

## 1. Scheduler

**Fichier** : `routes/console.php`

```php
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping();
```

La commande `posts:publish-scheduled` (`PublishScheduledCommand`) :
1. Cherche les posts avec `status = scheduled` ET `scheduled_at <= now()`
2. Pour chaque post trouvé, appelle `PublishingService::publish()`

---

## 2. PublishingService

**Fichier** : `app/Services/PublishingService.php`

### `publish(Post $post)`

1. **Traduction automatique** : Si `auto_translate = true` et `content_en` est vide, traduit `content_fr` en anglais via le `TranslationService`
2. **Change le statut** du post à `publishing`
3. **Dispatch un job** `PublishToPlatformJob` pour chaque `PostPlatform` en statut `pending`

### `getContentForAccount(Post $post, SocialAccount $account)`

Construit le contenu final pour un compte spécifique :

1. **Sélection de la langue** selon `account.languages` (JSON array) :
   - Si une seule langue : utilise la traduction correspondante (ou content_fr par défaut)
   - Si plusieurs langues : concatène les traductions avec séparateur "\n\n---\n\n"
   - Ordre : FR d'abord, puis les autres langues
   - Exemple : `["fr", "en"]` → content_fr + "\n\n---\n\n" + translations['en']

2. **Ajout des hashtags** si présents : `\n\n{hashtags}`

3. **Ajout du branding** si `account.show_branding = true` : `\n\n{branding}`

4. **Options additionnelles** : Passe `location_id` et `location_name` via le paramètre `$options` aux adapters

---

## 3. TranslationService

**Fichier** : `app/Services/TranslationService.php`

### `translate(string $text, string $from, string $to, ?string $apiKey)`

- Utilise l'API OpenAI avec le modèle `gpt-4o-mini`
- Clé API : celle du user OU celle du `.env` (`services.openai.api_key`)
- Prompt : réinterprétation naturelle (pas traduction littérale)
- Préserve les emojis, le formatage, et le ton
- Timeout : 30 secondes
- Retourne `null` en cas d'erreur (loggé)

---

## 4. PublishToPlatformJob

**Fichier** : `app/Jobs/PublishToPlatformJob.php`

### Configuration
- **Tentatives** : 2 (`$tries = 2`)
- **Délai entre tentatives** : 30 secondes (`$backoff = 30`)

### Exécution (`handle()`)

1. Charge le PostPlatform avec ses relations
2. Vérifie que account/post/platform existent
3. Sélectionne l'adapter via `getAdapter(slug)`
4. Construit le contenu via `PublishingService::getContentForAccount()`
5. Crée un log `submitted`
6. **Résout les URLs médias** : convertit `/media/uuid.jpg` en URLs signées temporaires (1h)
7. **Prépare les options** : `location_id`, `location_name` si définis dans le post
8. Appelle `adapter->publish(account, content, media, options)`
9. **Succès** : met à jour PostPlatform (published, external_id, published_at) + log
10. **Échec** : met à jour PostPlatform (failed, error_message) + log
11. Met à jour le statut global du Post

### Mise à jour du statut global

- **Tous published** → Post = published
- **Mix published/failed** → Post = published (si au moins un publié), sinon failed
- **Encore en cours** → pas de changement

---

## 5. Cycle de vie des statuts

### Post
```
draft ──► scheduled ──► publishing ──► published
                                  └──► failed
```

### PostPlatform
```
pending ──► publishing ──► published
                      └──► failed
```

### PostLog (actions)
```
submitted → published   (succès)
submitted → failed      (erreur)
submitted → retried     (nouvelle tentative)
```

---

## 6. Gestion des erreurs

- **Job échoué** : `markFailed()` met à jour le PostPlatform + crée un log
- **Exception non catchée** : `failed()` appelle `markFailed()` avec le message d'exception
- **Retry** : Le job est automatiquement réexécuté 1 fois après 30 secondes
- **Logs** : chaque action est enregistrée dans `post_logs` avec les détails JSON
