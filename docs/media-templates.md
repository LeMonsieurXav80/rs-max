# Media Templates

Systeme centralise de templates visuels pour la generation d'images optimisees par plateforme sociale (Pinterest, Instagram, Facebook, YouTube, etc.).

## Concept

Plutot que de coder des templates en dur dans chaque service (ex: `PinterestImageService`), le Studio centralise la creation et la gestion de templates reutilisables. Chaque template definit :

- **Format** : dimensions cible selon la plateforme
- **Layout** : disposition des elements (photo, titre, texte)
- **Typographie** : polices Google Fonts avec poids et taille configurables
- **Couleurs** : fond, texte, accent/bandeau, opacite overlay
- **Bordure** : couleur unie ou motif pattern tileable (ex: azulejo pour carrousels)

## Formats supportes

| Format | Plateforme | Dimensions | Usage |
|---|---|---|---|
| `pinterest_pin` | Pinterest | 1000x1500 | Pin standard RSS auto-publish |
| `instagram_post_square` | Instagram | 1080x1080 | Post carre |
| `instagram_post_portrait` | Instagram | 1080x1350 | Post portrait (4:5) |
| `instagram_story` | Instagram | 1080x1920 | Story / Reel cover |
| `instagram_carousel` | Instagram | 1080x1080 | Slides carrousel (max 10) |
| `facebook_post` | Facebook | 1200x630 | Post paysage |
| `youtube_thumbnail` | YouTube | 1280x720 | Miniature video |

## Layouts disponibles

| Layout | Description |
|---|---|
| `overlay` | Photo plein cadre + gradient sombre + titre en bas |
| `split` | Photo en haut (55%) + bloc colore avec titre en bas |
| `bold_text` | Fond uni + texte large centre (pas d'image) |
| `numbered` | Numero geant + separateur + titre |
| `framed` | Photo encadree avec bordure + titre en bandeau |
| `collage` | Disposition multi-photos |

## Google Fonts

### Polices curees (38 polices en 4 categories)

**Sans-Serif** : Montserrat, Poppins, Raleway, Open Sans, Lato, Roboto, Oswald, Nunito, Inter, Bebas Neue

**Serif** : Playfair Display, Merriweather, Lora, Cormorant Garamond, EB Garamond, Libre Baskerville, Crimson Text, DM Serif Display

**Manuscrite / Brush** : Pacifico, Dancing Script, Caveat, Satisfy, Kalam, Permanent Marker, Indie Flower, Sacramento, Great Vibes, Amatic SC

**Display / Impact** : Anton, Righteous, Archivo Black, Bungee, Abril Fatface, Alfa Slab One, Passion One, Teko, Staatliches, Black Ops One

### Telechargement automatique

Le `GoogleFontsService` telecharge les fichiers TTF depuis l'API Google Fonts CSS2 a la creation/modification d'un template. Les polices sont stockees dans `storage/app/fonts/` au format `{Family}-{Weight}.ttf`.

**Poids supportes** : Thin (100), ExtraLight (200), Light (300), Regular (400), Medium (500), SemiBold (600), Bold (700), ExtraBold (800), Black (900)

## Architecture

### Fichiers

```
app/Models/MediaTemplate.php              # Modele Eloquent
app/Http/Controllers/MediaTemplateController.php  # CRUD + download fonts + preview
app/Services/GoogleFontsService.php       # Telechargement Google Fonts TTF
database/migrations/..._create_media_templates_table.php
resources/views/media/templates.blade.php # Interface de gestion
```

### Table `media_templates`

| Colonne | Type | Description |
|---|---|---|
| `name` | string | Nom du template |
| `slug` | string (unique) | Identifiant URL |
| `format` | string(30) | Format cible (pinterest_pin, etc.) |
| `width` / `height` | unsigned int | Dimensions en pixels |
| `layout` | string(30) | Type de layout |
| `title_font` | string | Famille Google Font pour le titre |
| `title_font_weight` | string(20) | Poids du titre (Bold, ExtraBold...) |
| `title_font_size` | smallint | Taille en px |
| `body_font` | string (nullable) | Famille Google Font pour le corps |
| `body_font_weight` | string(20) (nullable) | Poids du corps |
| `body_font_size` | smallint (nullable) | Taille en px |
| `colors` | json | `{background, text, accent, overlay_opacity, title_band_color, title_band_opacity}` |
| `border` | json (nullable) | `{enabled, type, color, thickness, inner_padding, pattern_image}` |
| `config` | json (nullable) | Config supplementaire specifique au layout |
| `preview_path` | string (nullable) | Chemin vers l'image preview generee |
| `is_active` | boolean | Actif/inactif |

### Bordure pattern

Pour les carrousels Instagram (style azulejo par exemple) :
- Type `pattern` : upload d'une image tileable qui sera repetee en mosaique autour du contenu
- Type `solid` : bordure de couleur unie
- `thickness` : epaisseur en pixels
- `inner_padding` : marge entre la bordure et le contenu

### Routes

```
GET    /media/templates                    # Liste des templates
POST   /media/templates                    # Creer un template
PUT    /media/templates/{template}         # Modifier un template
DELETE /media/templates/{template}         # Supprimer un template
POST   /media/templates/download-font      # AJAX: telecharger une Google Font
POST   /media/templates/{template}/preview # AJAX: generer un apercu
```

### Navigation

Accessible via : **Sidebar > Contenu > Studio > Templates**

## Integration avec Pinterest

Les templates de format `pinterest_pin` sont destines a remplacer les 4 layouts codes en dur dans `PinterestImageService` (overlay, split, bold_text, numbered). Le `PinterestFeed` pourra reference un `media_template_id` au lieu d'un simple string `template`.

## Roadmap

- [x] Migration + modele + CRUD
- [x] Google Fonts service avec telechargement auto
- [x] Interface de gestion avec apercu live des polices
- [ ] Rendu server-side des templates (Intervention Image / GD)
- [ ] Connecter les templates Pinterest au `PinterestImageService`
- [ ] Preview genere cote serveur avec image sample
- [ ] Templates Instagram carrousel avec bordure pattern (azulejo)
- [ ] Export PDF pour carrousels LinkedIn
