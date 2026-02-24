# Routes et Contrôleurs

## Routes web (`routes/web.php`)

### Publiques (aucun middleware)
| Méthode | URL                    | Action                  | Description                           |
|---------|------------------------|-------------------------|---------------------------------------|
| GET     | `/`                    | Closure                 | Redirige vers /dashboard ou /login    |
| GET     | `/media/{filename}`    | MediaController@show    | Sert les médias privés (auth OU URL signée) |

### Authentifiées + vérifiées (`auth`, `verified`)
| Méthode | URL                           | Action                              | Nom                |
|---------|-------------------------------|-------------------------------------|---------------------|
| GET     | `/dashboard`                  | DashboardController@index           | dashboard           |
| GET     | `/posts`                      | PostController@index                | posts.index         |
| GET     | `/posts/create`               | PostController@create               | posts.create        |
| POST    | `/posts`                      | PostController@store                | posts.store         |
| GET     | `/posts/{post}`               | PostController@show                 | posts.show          |
| GET     | `/posts/{post}/edit`          | PostController@edit                 | posts.edit          |
| PUT     | `/posts/{post}`               | PostController@update               | posts.update        |
| DELETE  | `/posts/{post}`               | PostController@destroy              | posts.destroy       |
| GET     | `/accounts`                   | SocialAccountController@index       | accounts.index      |
| GET     | `/accounts/create`            | SocialAccountController@create      | accounts.create     |
| POST    | `/accounts`                   | SocialAccountController@store       | accounts.store      |
| GET     | `/accounts/{account}/edit`    | SocialAccountController@edit        | accounts.edit       |
| PUT     | `/accounts/{account}`         | SocialAccountController@update      | accounts.update     |
| DELETE  | `/accounts/{account}`         | SocialAccountController@destroy     | accounts.destroy    |
| PATCH   | `/accounts/{account}/toggle`  | SocialAccountController@toggleActive| accounts.toggle     |

### Authentifiées (`auth`)
| Méthode | URL         | Action                    | Nom             |
|---------|-------------|---------------------------|-----------------|
| GET     | `/profile`  | ProfileController@edit    | profile.edit    |
| PATCH   | `/profile`  | ProfileController@update  | profile.update  |
| DELETE  | `/profile`  | ProfileController@destroy | profile.destroy |

### Scheduler (`routes/console.php`)
```php
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping();
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
Liste les posts avec vue liste (paginée, 15/page) et vue calendrier (groupée par jour).

**Paramètres query** :
- `status` - Filtre par statut
- `month` (YYYY-MM) - Mois affiché sur le calendrier

### `create(Request $request)`
Formulaire de création. Charge les comptes actifs de l'utilisateur groupés par plateforme.

### `store(Request $request)`
Crée un post + ses entrées PostPlatform.

**Validation** :
```php
'content_fr'     => 'required|string'
'content_en'     => 'nullable|string'
'hashtags'       => 'nullable|string|max:500'
'auto_translate' => 'boolean'
'media'          => 'nullable|array'
'media.*'        => 'file|max:51200'  // 50MB max par fichier
'link_url'       => 'nullable|url|max:2048'
'telegram_channel' => 'nullable|string|max:255'
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

### `show(Request $request, int $id)`
Détail du post avec timeline de publication par plateforme + logs.

### `edit(Request $request, int $id)`
Formulaire d'édition (interdit si status = publishing ou published).

### `update(Request $request, int $id)`
Met à jour le post et re-sync les PostPlatform (supprime les anciens, crée les nouveaux).

### `destroy(Request $request, int $id)`
Supprime le post, ses PostPlatform et PostLogs (en transaction). Interdit si status = publishing.

---

## SocialAccountController

**Fichier** : `app/Http/Controllers/SocialAccountController.php`

### `index(Request $request)`
Liste les comptes groupés par plateforme.

### `create()`
Formulaire avec sélection de plateforme et champs dynamiques selon `platform.config.credential_fields`.

### `store(Request $request)`
Crée un compte. Les credentials sont chiffrés (encrypted:array cast).
Ne stocke que les champs attendus par la config de la plateforme.

### `edit / update`
Édition. Les credentials existants sont conservés si le champ est laissé vide (merge).

### `destroy(Request $request, int $id)`
Supprime le compte. Interdit si des posts sont en pending/publishing.

### `toggleActive(Request $request, int $id)`
Toggle `is_active`. Retourne du JSON (utilisé en AJAX avec Alpine.js).

---

## MediaController

**Fichier** : `app/Http/Controllers/MediaController.php`

### `show(Request $request, string $filename)`
Sert un fichier média privé depuis `storage/app/private/media/`.

**Accès autorisé si** :
1. L'utilisateur est authentifié (navigation web), **OU**
2. L'URL a une signature valide (URLs signées pour les APIs externes)

**Headers** : `Content-Type` détecté automatiquement, `Cache-Control: private, max-age=86400`
