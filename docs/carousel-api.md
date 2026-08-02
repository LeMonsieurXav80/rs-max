# API Carrousel & Images RS-Max

Fabrication d'images à partir de **templates HTML/CSS** : soit une image seule
(visuel de tweet, vignette d'article), soit un carrousel de plusieurs slides.

Document séparé de [`API.md`](API.md) — l'API de publication — dont il partage la
base URL, l'authentification et les conventions.

---

## Table des matières

1. [Le modèle en trois notions](#1-le-modèle-en-trois-notions)
2. [Authentification et base URL](#2-authentification-et-base-url)
3. [Découvrir le contrat : `GET /carousel/bricks`](#3-découvrir-le-contrat--get-carouselbricks)
4. [Les polices : `GET /carousel/fonts`](#4-les-polices--get-carouselfonts)
5. [Une image depuis un template : `POST /carousel/image`](#5-une-image-depuis-un-template--post-carouselimage)
6. [Un carrousel complet : `POST /carousel/render`](#6-un-carrousel-complet--post-carouselrender)
7. [Aperçu HTML sans rendu : `POST /carousel/preview`](#7-aperçu-html-sans-rendu--post-carouselpreview)
7 bis. [Ouvrir le Studio pré-rempli : `POST /carousel/studio-link`](#7-bis-ouvrir-le-studio-pré-rempli--post-carouselstudio-link)
8. [Gérer ses propres templates : CRUD `/carousel/bricks`](#8-gérer-ses-propres-templates--crud-carouselbricks)
9. [Enchaîner avec une publication](#9-enchaîner-avec-une-publication)
10. [Référence : briques fournies](#10-référence--briques-fournies)
11. [Erreurs, limites et performance](#11-erreurs-limites-et-performance)

---

## 1. Le modèle en trois notions

**Brique** (ou *template*) — un gabarit de slide : une mise en page nommée, qui
déclare les champs qu'elle attend. `photo-title-bl` = une photo plein cadre avec un
titre positionnable ; `stat-grid` = une grille de chiffres. Neuf briques sont
fournies ; on peut en créer d'autres (§ 8).

**Slot** — un champ d'une brique, **typé**. C'est le type qui dicte la validation :

| Type | Valeur attendue | Notes |
|---|---|---|
| `text` | chaîne | `max_length` annoncé par le manifeste (défaut 300) |
| `textarea` | chaîne multiligne | pour les briques listes : **une ligne = un item**, deux colonnes séparées par `\|` |
| `image` | id de MediaFile (int) **ou** `/media/<fichier>` | toute autre valeur est écartée (voir § 11) |
| `position` | une des 9 ancres (`bottom-left`, `middle-center`…) | grille 3×3 |
| `range` | nombre borné | ex. `offset` = décalage vertical en % de la hauteur |
| `select` | une des options annoncées | ex. `columns` |

**Thème** — l'apparence, commune à toute la production : 4 couleurs, 2 polices et
2 échelles typographiques. Identique pour l'image seule et pour le carrousel.

```json
{
  "background":  "#0f0f1a",
  "text":        "#ffffff",
  "accent":      "#0083ff",
  "overlay":     "#000000",
  "title_font":  "Montserrat",
  "body_font":   "Poppins",
  "title_scale": 1,
  "body_scale":  1
}
```

> ⚠️ **Les couleurs doivent être en hexadécimal à 6 chiffres.** `#000` ou
> `rgb(0,0,0)` sont refusés (422) : le dégradé de lisibilité concatène un canal
> alpha à la couleur, une forme courte casserait le rendu **sans erreur visible**.

Toute clé omise reprend la valeur par défaut, exposée par `GET /carousel/bricks`.

### Taille du texte : `title_scale` et `body_scale`

Les briques ne fixent pas des tailles en pixels : elles composent en **fraction de
la hauteur du slide** (un titre à ~6 % de la hauteur), ce qui garde le même
gabarit lisible en `1:1` comme en `9:16`. Ces deux facteurs multiplient ces
fractions — c'est le seul réglage de taille, il n'y en a pas par slide.

| Clé | Défaut | Bornes | Ce qu'elle touche |
|---|---|---|---|
| `title_scale` | `1` | `0.6` – `1.8` | tout ce qui est composé en police de titre : titres, chiffres de `stat-grid`, citation et guillemet de `quote`, numéro de `numbered`, pastille de `cta-end` |
| `body_scale` | `1` | `0.6` – `1.8` | sous-titres, corps, libellés, lignes de `table-rows` |

`1` = tailles natives des briques, donc un thème qui ne dit rien rend exactement
comme avant l'ajout du réglage. Hors bornes → **422** : en dessous de `0.6` le
texte n'est plus lisible sur mobile, au-dessus de `1.8` il déborde du cadre — et
un débordement ne se voit pas à la génération, seulement une fois publié.

L'auto-rétrécissement des titres longs (au-delà de ~60 puis ~90 caractères) reste
appliqué **avant** le facteur : un titre long à `title_scale: 1.4` reste plus
petit qu'un titre court à la même valeur.

Pour un template maison (§ 8), les deux facteurs sont exposés en variables CSS :

```css
.titre { font-size: calc(6cqh * var(--title-scale)); }
.corps { font-size: calc(3cqh * var(--body-scale)); }
```

---

## 2. Authentification et base URL

**Base URL** : `https://<votre-domaine>/api`

```
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

Token via `php artisan api:token --user=<email> --name=<libellé>` (voir
[`API.md § 1`](API.md#1-base-url-et-authentification)). Toutes les routes exigent
un token ; sans lui, `401`.

---

## 3. Découvrir le contrat : `GET /carousel/bricks`

Le manifeste est **la** source de vérité : les briques, leurs slots, les ratios, les
ancres et le thème par défaut. Une nouvelle brique y apparaît automatiquement — il
n'y a rien à mettre à jour côté client au-delà de la relecture de cet endpoint.

**Réponse** (extrait) :

```json
{
  "ratios": {
    "1:1":    { "w": 1080, "h": 1080, "label": "Carré" },
    "4:5":    { "w": 1080, "h": 1350, "label": "Portrait" },
    "3:4":    { "w": 1080, "h": 1440, "label": "Portrait haut" },
    "9:16":   { "w": 1080, "h": 1920, "label": "Story / Reel" },
    "4:3":    { "w": 1080, "h": 810,  "label": "Paysage" },
    "1.91:1": { "w": 1080, "h": 566,  "label": "Paysage lien" }
  },
  "default_ratio": "4:5",
  "positions": { "top-left": "Haut gauche", "…": "…" },
  "theme": { "background": "#0f0f1a", "text": "#ffffff", "…": "…" },
  "bricks": [
    {
      "slug": "photo-title-bl",
      "name": "Photo + titre positionnable",
      "description": "…",
      "ratios": ["*"],
      "slots": [
        { "key": "image",    "label": "Image de fond", "type": "image",    "default": null },
        { "key": "title",    "label": "Titre",         "type": "text",     "default": null, "max_length": 300 },
        { "key": "position", "label": "Emplacement",   "type": "position", "default": "bottom-left",
          "options": { "top-left": "Haut gauche", "…": "…" } },
        { "key": "offset",   "label": "Décalage vertical", "type": "range", "default": 0,
          "min": -25, "max": 25, "step": 1, "unit": "%" }
      ]
    }
  ]
}
```

`ratios: ["*"]` sur une brique = compatible avec tous les ratios.

---

## 4. Les polices : `GET /carousel/fonts`

`theme.title_font` et `theme.body_font` n'acceptent qu'une famille du **catalogue
Google Fonts** (~1900). Cet endpoint donne les valeurs valides.

| Paramètre | Défaut | Rôle |
|---|---|---|
| `q` | — | filtre sur le nom (insensible à la casse) |
| `limit` | `100` | 1 à 2000 |

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://<domaine>/api/carousel/fonts?q=bebas"
```

```json
{
  "total": 2,
  "fonts": [
    { "family": "Bebas Neue", "category": "Display", "installed": true }
  ]
}
```

`installed: false` n'est **pas** un obstacle : la police est téléchargée
automatiquement au premier rendu qui l'utilise (une fois, quelques secondes de
plus). Le rendu s'appuie toujours sur la copie locale, jamais sur le CDN Google —
sinon une police non chargée produirait une image en police système, sans erreur.

---

## 5. Une image depuis un template : `POST /carousel/image`

**Une brique employée seule ⇒ une image.** C'est la voie à prendre pour illustrer un
tweet ou un post simple : inutile de fabriquer un carrousel pour en jeter les autres
slides.

### Corps de requête

| Champ | Requis | Défaut | Description |
|---|---|---|---|
| `brick` | ✅ | — | slug de la brique |
| `data` | — | `{}` | les slots de la brique (§ 1) |
| `theme` | — | thème par défaut | couleurs, polices et échelles typographiques (§ 1) |
| `ratio` | — | `4:5` | une clé de `ratios`, **ou `auto`** |
| `format` | — | `jpg` | `jpg` ou `png` |
| `quality` | — | `88` | 40 à 100 (JPEG) |

**`ratio: "auto"`** — la sortie épouse le **ratio natif de l'image de fond**
(plafonné à 1080 sur le grand côté) au lieu de la recadrer. C'est le comportement de
l'incrustation de titre à la publication. `auto` sans slot `image` renseigné est
refusé (422) plutôt que de retomber en silence sur un ratio arbitraire.

### Exemple — visuel de tweet en paysage

```bash
curl -X POST https://<domaine>/api/carousel/image \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "brick": "photo-title-bl",
    "ratio": "1.91:1",
    "theme": { "accent": "#ff5c00", "title_font": "Bebas Neue" },
    "data": {
      "image": 4213,
      "title": "Le titre incrusté sur la photo",
      "subtitle": "Une précision en second rideau",
      "position": "bottom-left",
      "offset": 0
    }
  }'
```

**Réponse `201`** :

```json
{
  "brick": "photo-title-bl",
  "ratio": "1.91:1",
  "image": {
    "id": 4877,
    "filename": "carousel_h7ZWNjtnMD6kvuyTwWdIQu71.jpg",
    "url": "/media/carousel_h7ZWNjtnMD6kvuyTwWdIQu71.jpg",
    "thumbnail_url": "/media/thumb/…",
    "width": 1080,
    "height": 566
  }
}
```

L'image est ajoutée à la médiathèque (`source: "api"`), avec sa vignette. Son `id`
est directement réutilisable pour publier (§ 9).

Elle atterrit dans le dossier choisi dans **Paramètres → Studio** (racine par
défaut) — le même que celui du compositeur web, pour qu'une image ne change pas
de place selon son point de départ. Le réglage n'est pas surchargeable par
requête ; pour ranger ailleurs, déplacer après coup via l'API médiathèque.

### Exemple — image sans photo, texte seul

```json
{
  "brick": "bold-text",
  "ratio": "1:1",
  "theme": { "background": "#111827", "accent": "#22d3ee" },
  "data": { "title": "3 erreurs à éviter", "subtitle": "en 60 secondes", "position": "middle-left" }
}
```

---

## 6. Un carrousel complet : `POST /carousel/render`

Même pipeline, plusieurs slides. **Un carrousel est verrouillé sur un seul ratio**
(contrainte Instagram) et le thème s'applique à toutes ses slides.

### Corps de requête

| Champ | Requis | Défaut | Description |
|---|---|---|---|
| `ratio` | ✅ | — | une clé de `ratios` (pas de `auto` ici) |
| `slides` | ✅ | — | 1 à 20 entrées, dans l'ordre de publication |
| `slides[].brick` | ✅ | — | slug de la brique de CETTE slide |
| `slides[].data` | — | `{}` | les slots de cette brique |
| `theme` | — | thème par défaut | couleurs, polices et échelles typographiques (§ 1), pour tout le carrousel |
| `format` | — | `jpg` | `jpg` ou `png` |
| `quality` | — | `88` | 40 à 100 |

Chaque slide choisit **sa propre brique** : couverture photo, puis chiffres, puis
citation, puis appel à l'action.

```bash
curl -X POST https://<domaine>/api/carousel/render \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ratio": "4:5",
    "theme": {
      "background": "#0f0f1a", "text": "#ffffff",
      "accent": "#0083ff", "overlay": "#101820",
      "title_font": "Montserrat", "body_font": "Poppins"
    },
    "slides": [
      { "brick": "photo-title-bl",
        "data": { "image": 4213, "title": "La créatine, ce qu'\''en dit la science",
                  "position": "bottom-left", "offset": 0 } },
      { "brick": "stat-grid",
        "data": { "title": "Les chiffres", "columns": 2,
                  "items": "26|essais contrôlés\n1 036|participants" } },
      { "brick": "quote",
        "data": { "quote": "Aucun effet rénal chez le sujet sain.",
                  "author": "Méta-analyse, 2024", "position": "middle-left" } },
      { "brick": "cta-end",
        "data": { "title": "Abonne-toi", "subtitle": "un décryptage par semaine",
                  "handle": "@moncompte" } }
    ]
  }'
```

**Réponse `201`** — les images **dans l'ordre des slides** :

```json
{
  "ratio": "4:5",
  "items": [
    { "id": 4878, "filename": "carousel_….jpg", "url": "/media/…", "thumbnail_url": "/media/thumb/…", "width": 1080, "height": 1350 },
    { "id": 4879, "…": "…" }
  ]
}
```

### Les briques listes (`stat-grid`, `table-rows`)

Le slot est un `textarea` : **une ligne = un item**, et le caractère `|` sépare deux
colonnes.

```
26|essais contrôlés
1 036|participants
```

→ deux cellules, chacune avec son chiffre en couleur d'accent et son libellé. La
barre verticale n'apparaît jamais dans l'image.

---

## 7. Aperçu HTML sans rendu : `POST /carousel/preview`

Même corps que `/carousel/render`, mais renvoie le **HTML** de la bande
(`Content-Type: text/html`) sans lancer le navigateur : instantané, sans coût.

Utile pour contrôler une composition avant de payer la rasterisation — un
`<iframe srcdoc>` suffit à l'afficher. Pour prévisualiser une image seule, envoyer
une composition d'une seule slide (le ratio `auto` n'est pas disponible ici).

---

## 7 bis. Ouvrir le Studio pré-rempli : `POST /carousel/studio-link`

Le cas **« l'IA dégrossit, l'humain peaufine »**. Au lieu de produire des images
définitives, on dépose une composition et on récupère un **lien vers le Studio**
déjà rempli : ratio, thème et slides en place, aperçu affiché. Il ne reste qu'à
ajuster à la main puis générer.

Le corps est **exactement celui de `/carousel/render`** (§ 6).

```bash
curl -X POST https://<domaine>/api/carousel/studio-link \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "ratio": "4:5",
        "theme": { "accent": "#ff5c00", "title_font": "Bebas Neue" },
        "slides": [
          { "brick": "photo-title-bl", "data": { "image": 4213, "title": "Mon titre" } },
          { "brick": "stat-grid", "data": { "title": "Les chiffres", "items": "26|essais" } }
        ] }'
```

**Réponse `201`** :

```json
{
  "url": "https://<domaine>/carousel/studio?draft=xK3f…",
  "expires_at": "2026-08-03T17:42:11+00:00"
}
```

### Ce qu'il faut savoir

- **Le lien n'est pas un partage public.** Le Studio exige une session RS-Max :
  un visiteur non connecté est redirigé vers la page de login, puis ramené sur le
  brouillon. Pour montrer un rendu à quelqu'un d'extérieur, générer les images
  (§ 5 ou § 6) et les lui envoyer.
- **Rien n'est persisté.** La composition vit en cache pendant
  `CAROUSEL_DRAFT_TTL_HOURS` (24 h par défaut) puis disparaît. Ce n'est pas une
  bibliothèque de carrousels : c'est une page intermédiaire.
- **Le brouillon n'est pas consommé à l'ouverture** : recharger la page le
  réapplique tant qu'il n'a pas expiré. Corollaire — un `F5` après des
  modifications manuelles les écrase.
- **Aucune image n'est rendue** : l'endpoint ne lance pas Chromium, il répond
  instantanément. La rasterisation se fait depuis le bouton « Générer les
  images » du Studio.
- Un jeton inconnu ou expiré n'est pas une erreur : le Studio s'ouvre vierge.
- Les polices du thème sont téléchargées **au moment de créer le lien**, pour que
  l'aperçu s'affiche dans la bonne typo dès la première ouverture.

---

## 8. Gérer ses propres templates : CRUD `/carousel/bricks`

En plus des 9 briques fournies, on peut enregistrer ses propres gabarits. Un
template = **un gabarit HTML + une feuille de style**. Les champs se **déduisent du
gabarit** : écrire `{{ titre }}` crée le slot `titre`.

| Méthode | Route | Effet |
|---|---|---|
| `POST` | `/carousel/bricks` | crée (`slug`, `name`, `html` requis) |
| `PUT` | `/carousel/bricks/{id}` | modifie |
| `DELETE` | `/carousel/bricks/{id}` | supprime |

```json
{
  "slug": "mon-template",
  "name": "Mon template",
  "html": "<div class=\"wrap\"><h1>{{ titre }}</h1>{{#if sous_titre}}<p>{{ sous_titre }}</p>{{/if}}</div>",
  "css": ".wrap{padding:8cqw} h1{font-size:9cqh;font-family:var(--title-font);color:var(--text)}",
  "ratios": ["4:5", "1:1"]
}
```

Le gabarit n'est **jamais** compilé comme du code : substitution échappée
uniquement, avec `{{#if}}`, `{{#unless}}` et `{{#each}}`. Sont refusés à
l'enregistrement (422) : `<script>`, `<iframe>`, attributs `on*`, URL externes, PHP.

Conventions utiles dans le gabarit :

- **tailles en unités container query** — `6cqh` = 6 % de la hauteur de la slide, ce
  qui rend le template valable à tous les ratios ;
- **variables CSS du thème** — `--text`, `--bg`, `--accent`, `--overlay`,
  `--title-font`, `--body-font`, `--justify`, `--align`, `--text-align`, `--shift` ;
- **classe `.brick-scrim`** — le voile de lisibilité par-dessus une photo ;
- le type d'un slot est **inféré par son nom** : `image`/`photo`/`visuel`/`fond` →
  image ; `items`/`rows`/`body`/`quote` → textarea ; `position` → position ;
  `offset` → range. `{{#each x}}` fait de `x` un textarea.

Les 9 briques fournies sont en lecture seule. Pour repartir de l'une d'elles, la
dupliquer depuis **Studio > Templates** (l'interface pré-remplit son gabarit HTML,
stocké dans `resources/carousel-templates/<slug>.html`), puis l'ajuster par API.

---

## 9. Enchaîner avec une publication

L'image produite est un média de la bibliothèque : son `url` (`/media/<fichier>`)
alimente directement le champ `media` de l'API de publication — dont le format fait
foi dans [`API.md § 7`](API.md#7-endpoints--posts-contenu-simple).

```bash
# 1) fabriquer le visuel
URL=$(curl -s -X POST https://<domaine>/api/carousel/image \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"brick":"photo-title-bl","ratio":"1.91:1",
       "data":{"image":4213,"title":"Mon titre"}}' | jq -r '.image.url')

# 2) publier le tweet avec ce visuel
curl -X POST https://<domaine>/api/posts \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"content_fr\":\"Mon tweet\",\"status\":\"draft\",
       \"media\":[{\"type\":\"image\",\"url\":\"$URL\"}],\"accounts\":[12]}"
```

Pour un carrousel, reprendre les `items[]` **dans l'ordre** — c'est celui des slides.

---

## 10. Référence : briques fournies

| Slug | Nom | Slots |
|---|---|---|
| `photo-title-bl` | Photo + titre positionnable | `image`, `title`, `subtitle`, `position`, `offset` |
| `image-full` | Image seule (plein cadre) | `image` |
| `text-on-image` | Texte sur image de fond | `image`, `title`, `body`, `position`, `offset` |
| `bold-text` | Texte plein (sans image) | `title`, `subtitle`, `position`, `offset` |
| `stat-grid` | Grille de chiffres | `title`, `items`, `columns` |
| `quote` | Citation | `quote`, `author`, `image`, `position`, `offset` |
| `table-rows` | Tableau | `title`, `rows`, `note` |
| `numbered` | Slide numérotée | `number`, `title`, `body`, `image`, `position`, `offset` |
| `cta-end` | Slide de fin (appel à l'action) | `title`, `subtitle`, `handle`, `image`, `position`, `offset` |

Défauts notables : `photo-title-bl` ancre en `bottom-left`, `text-on-image` et
`cta-end` en `middle-center`, `bold-text`/`quote`/`numbered` en `middle-left`,
`stat-grid` sur 2 colonnes. Le manifeste fait foi.

---

## 11. Erreurs, limites et performance

### Codes

| Code | Cas |
|---|---|
| `401` | token absent ou invalide |
| `422` | brique inconnue, ratio inconnu, slot hors bornes, couleur non hexadécimale 6 chiffres, police hors catalogue, `auto` sans image |
| `429` | throttle dépassé |
| `500` | échec du rendu (navigateur headless indisponible) |

Les erreurs `422` suivent le format Laravel — la clé fautive est nommée
(`data.position`, `theme.overlay`, `slides.2.data.offset`).

### Limites

| | |
|---|---|
| Slides par carrousel | **20** |
| `POST /carousel/render` | **20 requêtes/minute** |
| `POST /carousel/image` | **60 requêtes/minute** |
| `GET`/`preview` | non limités |

### Performance

Le rendu est **synchrone** et lance un navigateur headless **par slide** : compter
**~2 s par image**. Un carrousel de 10 slides prend donc ~20 s — prévoir un timeout
client généreux. `/carousel/preview` (HTML) est instantané : s'en servir pour itérer,
et ne rasteriser qu'à la fin.

### Sécurité des images

Un slot `image` n'accepte qu'un **id de MediaFile** ou une référence locale
`/media/<fichier>`. Toute autre valeur (URL externe, chemin arbitraire) est
**silencieusement écartée** — la slide est rendue sans image. C'est délibéré :
aucune URL fournie par un client n'atteint le navigateur de rendu (anti-SSRF).
Pour utiliser une image externe, l'importer d'abord dans la médiathèque.

---

## Voir aussi

- [`API.md`](API.md) — publication, planification, génération IA, stats
- [`media-catalog-api.md`](media-catalog-api.md) — médiathèque (upload, recherche, tags)
