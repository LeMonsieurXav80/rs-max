# Commandes Artisan

## Publication

### `posts:publish-scheduled`

Publie les posts programmés dont la date est passée. Exécutée automatiquement par le scheduler chaque minute.

```bash
php artisan posts:publish-scheduled
```

**Logique** :
1. Cherche les posts avec `status = scheduled` ET `scheduled_at <= now()`
2. Pour chaque post, appelle `PublishingService::publish()`
3. Affiche le nombre de posts traités

**Scheduler** (dans `routes/console.php`) :
```php
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping();
```

---

## Statistiques

### `stats:sync`

Synchronise les métriques de publication depuis les plateformes sociales.

```bash
php artisan stats:sync                          # Sync standard (respecte fréquences)
php artisan stats:sync --post-id=123            # Sync un post spécifique
php artisan stats:sync --platform=instagram     # Sync une plateforme uniquement
php artisan stats:sync --force                  # Force même si récemment synced
php artisan stats:sync --days=7                 # Posts des 7 derniers jours (défaut: 30)
```

**Options** :
- `--post-id=` - Sync un post spécifique
- `--platform=` - Filtre par plateforme (facebook, instagram, twitter, youtube, threads)
- `--force` - Force la synchronisation même si récemment effectuée
- `--days=` - Nombre de jours en arrière (défaut: 30)

**Logique** :
1. Requête les PostPlatform publiés avec external_id
2. Skip sauf si `--force` ou `shouldSync()` renvoie true
3. Affiche une barre de progression
4. Délai de 100ms entre les requêtes (rate limiting)
5. Résultat : synced / skipped / failed

**Scheduler** :
```php
// Fréquence configurable via Settings (défaut: hourly)
Schedule::command('stats:sync')->{fréquence}->withoutOverlapping();
// Options: every_15_min, every_30_min, hourly, every_2_hours, every_6_hours, every_12_hours, daily
```

---

## RSS

### `rss:generate`

Génère des posts programmés à partir des articles de flux RSS en utilisant l'IA.

```bash
php artisan rss:generate                # Génère pour tous les flux actifs
php artisan rss:generate --feed=5       # Génère pour un flux spécifique
php artisan rss:generate --dry-run      # Prévisualise sans créer de posts
```

**Options** :
- `--feed=` - ID du flux RSS spécifique
- `--dry-run` - Prévisualise les posts qui seraient créés

**Logique** :
1. Récupère l'utilisateur admin pour l'attribution des posts
2. Charge les flux actifs avec les comptes sociaux où `auto_post = true`
3. Pour chaque combinaison flux + compte :
   - Vérifie la persona (requise via `pivot->persona_id`)
   - Respecte `max_posts_per_day` (défaut: 1)
   - Trouve les articles non encore postés pour ce compte
   - Génère le contenu via `ContentGenerationService` (OpenAI gpt-4o-mini)
   - Crée un Post (status=scheduled, source_type=rss)
   - Planifie dans la fenêtre 9h-20h
   - Délai de 500ms entre les appels API
4. Cap total : 50 posts par exécution

**Scheduler** :
```php
Schedule::command('rss:generate')->cron('0 */6 * * *')->withoutOverlapping();
```

---

## Médias

### `media:download`

Télécharge les médias distants (URLs http/https) vers le stockage local.

```bash
php artisan media:download           # Exécute
php artisan media:download --dry-run # Prévisualise
```

**Cas d'usage** : après l'import NocoDB, les médias sont des URLs distantes. Cette commande les rapatrie en local.

---

### `media:secure`

Déplace les médias du stockage public vers le stockage privé avec renommage UUID.

```bash
php artisan media:secure           # Exécute
php artisan media:secure --dry-run # Prévisualise
```

**Cas d'usage** : sécuriser les médias déjà téléchargés pour qu'ils ne soient plus accessibles publiquement.

---

### `media:convert-videos`

Convertit toutes les vidéos non-H.264 en MP4 (H.264/AAC) et met à jour les références en base.

```bash
php artisan media:convert-videos           # Exécute
php artisan media:convert-videos --dry-run # Prévisualise
```

**Logique** :
1. Trouve le binaire ffmpeg (essaie : `which` → `/opt/homebrew/bin` → `/usr/local/bin` → `/usr/bin`)
2. Scanne les fichiers vidéo : .mov, .avi, .webm, .mkv, .mp4
3. Pour chaque vidéo :
   - Si pas MP4 → convertit
   - Si MP4 avec codec non-H.264 (HEVC, VP9, AV1) → ré-encode
4. Commande de conversion :
   ```bash
   ffmpeg -i input -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart -y output
   ```
5. Met à jour les références dans les Posts si le nom de fichier change
6. Supprime les anciens thumbnails si existants

---

### `media:recompress`

Recompresse les images existantes selon les paramètres actuels de Settings.

```bash
php artisan media:recompress           # Exécute
php artisan media:recompress --dry-run # Prévisualise
```

**Paramètres Settings utilisés** :
- `image_max_dimension` (défaut: 2048)
- `image_target_min_kb` (défaut: 200)
- `image_target_max_kb` (défaut: 500)
- `image_min_quality` (défaut: 60)

**Logique** :
1. Scanne : .jpg, .jpeg, .png
2. Skip si la taille est déjà dans la plage cible
3. Redimensionne si > max_dimension
4. Recherche binaire de la qualité optimale entre min et 85%
5. Sauvegarde à la meilleure qualité trouvée

---

## Import

### `import:nocodb`

Importe les données depuis l'instance NocoDB (migration one-shot depuis l'ancien système).

```bash
php artisan import:nocodb                                  # Import complet
php artisan import:nocodb --admin-email=admin@example.com  # Définit l'admin
php artisan import:nocodb --scheduled-only                 # Uniquement les posts programmés
```

**Étapes** :
1. Importe les clients NocoDB → crée des Users avec mot de passe aléatoire
2. Marque le user admin si `--admin-email` fourni
3. Crée les SocialAccounts par plateforme (credentials extraits du JSON NocoDB)
4. Importe les publications → crée des Posts + PostPlatform
5. Mapping des statuts : Programme→scheduled, Publie→published, Erreur→failed, Brouillon→draft

---

## Développement

### Scripts composer

```bash
composer setup    # Installation complète (install, key:generate, migrate, npm install+build)
composer dev      # Lance 4 services en parallèle :
                  #   - php artisan serve (port 8000)
                  #   - php artisan queue:listen --tries=1
                  #   - php artisan pail (logs en temps réel)
                  #   - npm run dev (Vite hot reload)
composer test     # Clear config + run PHPUnit tests
```

### Seeding

```bash
php artisan db:seed                           # Seed complet (user test + plateformes)
php artisan db:seed --class=PlatformSeeder    # Seed des plateformes uniquement
```

**User de test créé** :
- Email : `test@example.com`
- Mot de passe : `password`
