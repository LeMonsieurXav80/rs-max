# Pipeline de publication

## Vue d'ensemble

```
                    ┌─────────────────┐
                    │  Utilisateur    │        ┌─────────────────┐
                    │  crée un post   │        │  RSS Generate   │
                    └────────┬────────┘        │  (toutes les 6h)│
                             │                 └────────┬────────┘
                    ┌────────▼────────┐                 │
                    │   Post créé     │◄────────────────┘
                    │  (draft ou      │   source_type = rss
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
              │  1. Status → publishing      │
              │  2. Dispatch jobs            │
              └──────────────┬──────────────┘
                             │
     ┌───────────┬───────────┼───────────┬───────────┬───────────┐
     │           │           │           │           │           │
┌────▼────┐┌────▼────┐┌─────▼────┐┌─────▼────┐┌────▼────┐┌────▼────┐
│Telegram ││Facebook ││Instagram ││ Twitter  ││ Threads ││ YouTube │
│ Adapter ││ Adapter ││ Adapter  ││ Adapter  ││ Adapter ││ Adapter │
└────┬────┘└────┬────┘└─────┬────┘└─────┬────┘└────┬────┘└────┬────┘
     │           │           │           │           │           │
     └───────────┴───────────┼───────────┴───────────┴───────────┘
                             │
              ┌──────────────▼──────────────┐
              │  Mise à jour des statuts     │
              │  PostPlatform + Post         │
              └──────────────┬──────────────┘
                             │
              ┌──────────────▼──────────────┐
              │  Stats sync (configurable)   │
              │  stats:sync                  │
              └─────────────────────────────┘
```

---

## 1. Scheduler

**Fichier** : `routes/console.php`

```php
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('rss:generate')->cron('0 */6 * * *')->withoutOverlapping();
Schedule::command('stats:sync')->{fréquence configurable}->withoutOverlapping();
```

La commande `posts:publish-scheduled` :
1. Cherche les posts avec `status = scheduled` ET `scheduled_at <= now()`
2. Pour chaque post trouvé, appelle `PublishingService::publish()`

---

## 2. PublishingService

**Fichier** : `app/Services/PublishingService.php`

### `publish(Post $post)`

1. **Change le statut** du post à `publishing`
2. Récupère tous les PostPlatform en statut `pending` avec les relations
3. Met chaque PostPlatform en statut `publishing`
4. **Dispatch un job** `PublishToPlatformJob` pour chaque PostPlatform
5. Si aucun PostPlatform pending, marque le post comme `failed`

### `getContentForAccount(Post $post, SocialAccount $account)`

Construit le contenu final pour un compte spécifique :

1. **Sélection de la langue** selon `account.languages` (JSON array, défaut: `["fr"]`) :
   - Si une seule langue : utilise la traduction correspondante (ou content_fr par défaut)
   - Si plusieurs langues : concatène avec drapeaux emoji (🇫🇷 🇬🇧 🇵🇹 🇪🇸 🇩🇪 🇮🇹)
   - Ordre : FR d'abord, puis les autres langues
   - Séparateur : `\n\n---\n\n`

2. **Traduction on-the-fly** via `getTranslation()` :
   - Vérifie le cache `post->translations[lang]`
   - Fallback vers `content_en` pour l'anglais (rétro-compatibilité)
   - Auto-traduit si `post->auto_translate` activé via `TranslationService`

3. **Ajout des hashtags** si présents : `\n\n{hashtags}`

4. **Ajout du branding** si `account.show_branding = true` : `\n\n{branding}`

5. **Options additionnelles** : Passe `location_id` et `location_name` via `$options`

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
- **Tentatives** : 1 (`$tries = 1`)
- **Timeout** : 300 secondes / 5 minutes (`$timeout = 300`)

### Garde d'idempotence
```php
if ($this->postPlatform->status === 'published') {
    return; // Skip si déjà publié
}
```

### Exécution (`handle()`)

1. Charge le PostPlatform avec ses relations
2. Vérifie que account/post/platform existent
3. **Vérifie l'idempotence** (skip si déjà published)
4. Sélectionne l'adapter via `getAdapter(slug)` : telegram, facebook, instagram, threads, twitter, youtube
5. Construit le contenu via `PublishingService::getContentForAccount()`
6. Crée un log `submitted`
7. **Résout les URLs médias** : convertit `/media/uuid.jpg` en URLs signées temporaires (4h)
8. **Prépare les options** : `location_id`, `location_name` si définis
9. Appelle `adapter->publish(account, content, media, options)`
10. **Succès** : met à jour PostPlatform (published, external_id, published_at) + log
11. **Échec** : met à jour PostPlatform (failed, error_message) + log
12. Met à jour le statut global du Post

### Mise à jour du statut global

- **Tous published** → Post = published, published_at = now()
- **Mix published/failed (au moins un publié)** → Post = published
- **Tous failed** → Post = failed
- **Encore en cours** → pas de changement

---

## 5. Publication manuelle

**Fichier** : `app/Http/Controllers/PublishController.php`

En plus du scheduler automatique, les posts peuvent être publiés manuellement :

- `publishAll(Post $post)` → publie toutes les PostPlatform pending/failed
- `publishOne(PostPlatform $postPlatform)` → publie une PostPlatform spécifique de manière synchrone
- `resetOne(PostPlatform $postPlatform)` → remet en pending pour retenter

---

## 6. Pipeline RSS

**Fichiers** : `app/Services/Rss/`, `app/Console/Commands/RssGenerateCommand.php`

### Flux de données

```
RssFetchService (fetch toutes les 6h)
   ├── Parse RSS 2.0 / Atom / Sitemaps
   └── Crée des RssItems (déduplication sur GUID)
        │
        ▼
RssGenerateCommand (génération toutes les 6h)
   ├── Pour chaque feed + account (auto_post = true)
   ├── ArticleFetchService → récupère le contenu de l'article (timeout 15s)
   ├── ContentGenerationService → génère le contenu via IA (persona)
   ├── Crée un Post (source_type=rss, status=scheduled)
   ├── Planifie dans la fenêtre 9h-20h
   └── Respecte max_posts_per_day
        │
        ▼
→ Le scheduler classique (posts:publish-scheduled) prend le relais
```

---

## 7. Synchronisation des statistiques

**Fichiers** : `app/Services/Stats/`, `app/Console/Commands/SyncPostStats.php`

### Flux

```
stats:sync (fréquence configurable)
   ├── Requête les PostPlatform publiés avec external_id
   ├── shouldSync() vérifie l'intervalle par plateforme
   ├── Appelle le service de stats spécifique (Facebook/Instagram/Twitter/YouTube/Threads)
   ├── Skip Telegram (pas de stats)
   ├── Met à jour metrics + metrics_synced_at
   └── Délai 100ms entre requêtes (rate limiting)
```

---

## 8. Cycle de vie des statuts

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
```

---

## 9. Gestion des erreurs

- **Job échoué** : `markFailed()` met à jour le PostPlatform + crée un log
- **Exception non catchée** : `failed()` appelle `markFailed()` avec le message d'exception
- **Pas de retry** : tries = 1, l'utilisateur peut utiliser `resetOne()` pour retenter manuellement
- **Logs** : chaque action est enregistrée dans `post_logs` avec les détails JSON
