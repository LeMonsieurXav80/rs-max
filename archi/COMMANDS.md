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

**Particularités Telegram** : crée un compte social par channel (un client peut avoir plusieurs channels).

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
