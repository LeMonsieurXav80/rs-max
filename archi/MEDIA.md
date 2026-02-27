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

## Médiathèque

L'application intègre une médiathèque complète accessible via `/media`.

### Routes

| Méthode | URL                            | Action                    | Description                   |
|---------|--------------------------------|---------------------------|-------------------------------|
| GET     | `/media`                       | MediaController@index     | Page médiathèque avec filtres |
| POST    | `/media/upload`                | MediaController@upload    | Upload d'un fichier (AJAX)    |
| GET     | `/media/list`                  | MediaController@list      | Liste des fichiers en JSON    |
| GET     | `/media/thumbnail/{filename}`  | MediaController@thumbnail | Thumbnail vidéo (ffmpeg)      |
| DELETE  | `/media/{filename}`            | MediaController@destroy   | Suppression d'un fichier      |
| GET     | `/media/{filename}`            | MediaController@show      | Sert le fichier (auth/signé)  |

### Filtrage

La médiathèque supporte le filtrage par type :
- `all` - Tous les fichiers
- `images` - JPEG, PNG, GIF, WebP
- `videos` - MP4, MOV, AVI, WebM

### Thumbnails vidéo

Les thumbnails vidéo sont générés via ffmpeg (frame extraite à 5 secondes) et mis en cache au format JPEG.

### Sélecteur de médias

La route `/media/list` retourne les fichiers en JSON pour le sélecteur intégré aux formulaires de création/édition de posts.

---

## Accès aux médias

### Route
```
GET /media/{filename}
```

### Deux modes d'accès

1. **Utilisateur authentifié** (navigation web) : accès direct via la session
2. **URL signée** (APIs externes) : URL temporaire avec signature cryptographique

### MediaController@show

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

### Via la médiathèque (MediaController@upload)

Upload AJAX avec compression automatique des images et conversion des vidéos.

**Validation** :
```php
'file' => 'required|max:51200|mimes:jpeg,jpg,png,gif,webp,mp4,mov,avi,webm'
```

**Traitement des images** :
- Redimensionnement si > max_dimension (paramétrable via Settings)
- Compression adaptative ciblant la plage target_min_kb - target_max_kb
- Gestion de la transparence (PNG → JPEG si transparent sans transparence réelle)

**Traitement des vidéos** :
- Détection du codec via ffprobe
- Conversion automatique des vidéos HEVC/VP9/AV1 en H.264/AAC MP4

### Via la création/édition d'un post (PostController@store)

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

Quand un post est publié vers une plateforme externe, les APIs ont besoin d'accéder aux fichiers. Le `PublishToPlatformJob` génère des **URLs signées temporaires** :

```php
URL::temporarySignedRoute(
    'media.show',
    now()->addHours(4),   // Expire après 4 heures
    ['filename' => $filename]
);
```

**Résultat** :
```
https://rs-max.example.com/media/uuid.jpg?expires=1234567890&signature=abc123...
```

---

## Compression adaptative d'images

**Paramètres configurables** (via Settings) :
- `image_max_dimension` - Dimension maximale en pixels (défaut: 2048)
- `image_target_min_kb` - Taille minimale cible en KB (défaut: 200)
- `image_target_max_kb` - Taille maximale cible en KB (défaut: 500)
- `image_min_quality` - Qualité minimale acceptable (défaut: 60%)
- `image_max_upload_mb` - Taille max d'upload en MB

**Algorithme** :
1. Si l'image > max_dimension : redimensionne proportionnellement
2. Essaie 90% : si ≤ max, utilise cette qualité
3. Sinon : recherche binaire entre min_quality et 85%
4. Sauvegarde à la meilleure qualité trouvée dans la plage cible

**Support** :
- JPEG/JPG : compression avec qualité variable
- PNG : conversion en JPEG si nécessaire (gestion transparence)
- WebP : compression native

---

## Conversion automatique de vidéos

### Conversion HEVC/H.265 vers H.264

Pour garantir la compatibilité maximale, les vidéos encodées en HEVC/H.265 (ou VP9/AV1) sont automatiquement converties en H.264/AAC MP4.

**Détection** :
- Utilise `ffprobe` pour analyser le codec vidéo
- Détecte les codecs : `hevc`, `h265`, `hvc1`, `vp9`, `av1`

**Conversion** :
```bash
ffmpeg -i input.mp4 -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart -y output.mp4
```

**Paramètres configurables** (via Settings) :
- `video_max_upload_mb` - Taille max d'upload en MB
- `video_bitrate_1080p` / `video_bitrate_720p` - Bitrates vidéo
- `video_codec` - Codec cible
- `video_audio_bitrate` - Bitrate audio

**Comportement** :
- La conversion se fait à l'upload (médiathèque) et à la publication (Telegram)
- Le fichier original est remplacé (conversion en place via fichier temporaire)

---

## Commandes de gestion

### `media:convert-videos`

Convertit toutes les vidéos non-H.264 du stockage en MP4 H.264/AAC.

```bash
php artisan media:convert-videos           # Exécute
php artisan media:convert-videos --dry-run  # Prévisualise
```

### `media:recompress`

Recompresse les images existantes selon les paramètres actuels de Settings.

```bash
php artisan media:recompress           # Exécute
php artisan media:recompress --dry-run  # Prévisualise
```

### `media:download`

Télécharge des médias distants (URLs http/https) vers le stockage local.

```bash
php artisan media:download           # Exécute
php artisan media:download --dry-run # Prévisualise
```

### `media:secure`

Déplace les médias du stockage public vers le stockage privé avec des noms UUID.

```bash
php artisan media:secure           # Exécute
php artisan media:secure --dry-run # Prévisualise
```

---

## Types de médias supportés

| Extension | MIME Type       | Utilisé par                            |
|-----------|-----------------|----------------------------------------|
| jpg/jpeg  | image/jpeg      | Toutes les plateformes                 |
| png       | image/png       | Toutes les plateformes                 |
| gif       | image/gif       | Facebook, Twitter, Telegram            |
| webp      | image/webp      | Facebook, Telegram                     |
| mp4       | video/mp4       | Toutes les plateformes                 |
| mov       | video/quicktime | Facebook, Instagram                    |
| avi       | video/x-msvideo | Converti en MP4 à l'upload             |
| webm      | video/webm      | Telegram (converti en MP4 si nécessaire)|

---

## Flux complet

```
Utilisateur upload un fichier (médiathèque ou formulaire)
        │
        ▼
MediaController@upload / PostController@store
  - UUID filename
  - Compression image adaptative (Settings)
  - Conversion vidéo non-H.264 → MP4
  - Stockage privé (storage/app/private/media/)
  - JSON: {url: "/media/uuid.ext", mimetype, size, title}
        │
        ▼
Affichage dans l'interface (auth required)
  - <img src="/media/uuid.jpg">
  - <video> avec thumbnail via /media/thumbnail/uuid.mp4
  - MediaController vérifie l'auth session
        │
        ▼
Publication vers plateforme externe
  - PublishToPlatformJob génère une URL signée (4h)
  - L'API externe télécharge le fichier via l'URL signée
  - L'URL expire après 4 heures
```
