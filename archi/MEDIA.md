# Gestion des médias

## Architecture

Les médias sont stockés de manière **privée** dans `storage/app/private/media/`. Ils ne sont pas accessibles directement via URL publique.

```
storage/
  app/
    private/
      media/
        a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg
        f9e8d7c6-b5a4-3210-fedc-ba0987654321.mp4
        ...
```

---

## Accès aux médias

### Route
```
GET /media/{filename}
```

### Deux modes d'accès

1. **Utilisateur authentifié** (navigation web) : accès direct via la session
2. **URL signée** (APIs externes) : URL temporaire avec signature cryptographique

### MediaController

```php
public function show(Request $request, string $filename): StreamedResponse
{
    // Vérifie : user connecté OU signature valide
    if (! $request->user() && ! $request->hasValidSignature()) {
        abort(403);
    }
    // Sert le fichier depuis le stockage privé
}
```

**Headers** :
- `Content-Type` : détecté automatiquement (image/jpeg, video/mp4, etc.)
- `Cache-Control: private, max-age=86400` (24h, privé)
- `Accept-Ranges: bytes` pour les vidéos (support du streaming)

**HTTP Range** :
- Supporte les requêtes `Range: bytes=start-end` pour le streaming vidéo
- Permet le seeking (déplacement dans la timeline) directement dans le navigateur
- Retourne `206 Partial Content` avec les headers `Content-Range` et `Content-Length` appropriés

---

## Upload de médias

### Lors de la création/édition d'un post (PostController@store)

1. Le fichier est uploadé via le formulaire (max 50MB par fichier)
2. Un nom UUID est généré : `{uuid}.{extension}`
3. Le fichier est stocké dans `storage/app/private/media/`
4. L'entrée média est ajoutée au JSON du post :

```json
{
    "url": "/media/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg",
    "mimetype": "image/jpeg",
    "size": 245000,
    "title": "photo-originale.jpg"
}
```

---

## URLs signées pour la publication

Quand un post est publié vers une plateforme externe (Facebook, Instagram, etc.), les APIs ont besoin d'accéder aux fichiers médias. Le `PublishToPlatformJob` génère des **URLs signées temporaires** :

```php
URL::temporarySignedRoute(
    'media.show',
    now()->addHour(),     // Expire après 1 heure
    ['filename' => $filename]
);
```

**Résultat** :
```
https://rs-max.example.com/media/uuid.jpg?expires=1234567890&signature=abc123...
```

Les APIs des plateformes téléchargent les médias via ces URLs. Après 1h, les URLs expirent et ne sont plus accessibles sans authentification.

---

## Conversion automatique de vidéos

### Conversion HEVC/H.265 vers H.264

Pour garantir la compatibilité maximale (notamment avec Telegram), les vidéos encodées en HEVC/H.265 sont automatiquement converties en H.264/AAC MP4.

**Détection** :
- Utilise `ffprobe` pour analyser le codec vidéo
- Détecte les codecs : `hevc`, `h265`, `hvc1`

**Conversion** :
```bash
ffmpeg -i input.mp4 -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k output.mp4
```

**Paramètres** :
- `libx264` : codec H.264 compatible universellement
- `preset fast` : équilibre vitesse/qualité
- `crf 23` : qualité constante (18-28, 23 = bon compromis)
- `aac 128k` : audio AAC standard

**Comportement** :
- La conversion se fait lors de la publication vers Telegram
- Le fichier original est préservé
- Le fichier converti est stocké temporairement et supprimé après upload

---

## Compression adaptative d'images

**Objectif** : Réduire la taille des images pour optimiser les uploads tout en préservant la qualité visuelle.

**Paramètres** :
- Taille cible : 500 KB - 1 MB
- Qualité initiale : 85%
- Qualité minimale : 60%
- Résolution max : 4096×4096 pixels

**Algorithme** :
1. Si l'image > 1 MB : commence la compression
2. Réduit progressivement la qualité (-5% par itération)
3. S'arrête quand : taille < 1 MB OU qualité < 60%
4. Si toujours trop grande : redimensionne proportionnellement

**Support** :
- JPEG/JPG : compression avec qualité variable
- PNG : conversion en JPEG si > 1 MB
- WebP : compression native

---

## Commandes de migration

### `media:download`

Télécharge des médias distants (URLs http/https) vers le stockage local.

```bash
php artisan media:download           # Exécute le téléchargement
php artisan media:download --dry-run # Prévisualise sans télécharger
```

- Génère des noms : `post_{id}_{index}_{random}.{ext}`
- Met à jour les URLs dans le JSON du post
- Conserve l'URL originale dans `original_url`

### `media:secure`

Déplace les médias du stockage public vers le stockage privé avec des noms UUID.

```bash
php artisan media:secure           # Exécute la migration
php artisan media:secure --dry-run # Prévisualise sans déplacer
```

- Convertit `/storage/media/post_1_0_abc.jpg` → `/media/uuid.jpg`
- Déplace le fichier de `storage/app/public/media/` vers `storage/app/private/media/`
- Supprime le dossier public/media si vide

---

## Types de médias supportés

| Extension | MIME Type     | Utilisé par                          |
|-----------|---------------|--------------------------------------|
| jpg/jpeg  | image/jpeg    | Toutes les plateformes               |
| png       | image/png     | Toutes les plateformes               |
| gif       | image/gif     | Facebook, Twitter, Telegram          |
| webp      | image/webp    | Facebook, Telegram                   |
| mp4       | video/mp4     | Toutes les plateformes               |
| mov       | video/quicktime | Facebook, Instagram                |
| webm      | video/webm    | Telegram                             |

---

## Flux complet

```
Utilisateur upload un fichier
        │
        ▼
PostController@store
  - UUID filename
  - Stockage privé (storage/app/private/media/)
  - JSON: {url: "/media/uuid.ext", mimetype, size, title}
        │
        ▼
Affichage dans l'interface (auth required)
  - <img src="/media/uuid.jpg">
  - MediaController vérifie l'auth session
        │
        ▼
Publication vers plateforme externe
  - PublishToPlatformJob génère une URL signée (1h)
  - L'API externe télécharge le fichier via l'URL signée
  - L'URL expire après 1 heure
```
