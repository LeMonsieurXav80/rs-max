# Publier depuis son ordinateur, sans la compression de RS-Max

Octobre 2026. Deux morceaux : une entrée d'API qui stocke l'octet exact, et un
CLI Python qui l'utilise depuis un poste Mac ou Windows.

## Le problème

RS-Max recompressait tout ce qui entrait, et la normalisation avait lieu **à
l'upload**, une seule fois, pour tous les réseaux.

| Ce qui entrait | Ce qu'il devenait |
| --- | --- |
| PNG 2160×2700, 6 Mo | JPEG réduit à 2048 px, 200–500 ko ([`ProcessesImages`](../app/Concerns/ProcessesImages.php)) |
| MP4 1080×1920, 180 Mo | H.264 recalculé pour tenir dans 50 Mo, **original supprimé** |

Les deux cibles sont dans les réglages (`image_target_min_kb`,
`image_target_max_kb`, `image_max_dimension`, `video_target_size_mb`), et ce
comportement est **voulu** pour une photo de flux : elle n'a pas besoin de ses
12 Mo. Il ne l'est pas pour un visuel fabriqué afin d'être publié.

Deux conséquences qui coûtaient cher :

1. **On perdait deux fois.** Le réseau recompresse par-dessus. La première
   perte, la nôtre, était évitable ; celle du réseau ne l'est pas, pas même en
   publiant depuis le téléphone.
2. **La contrainte la plus sévère gagnait.** Normaliser une fois pour tous les
   réseaux, c'est se caler sur Bluesky et ses 50 Mo. Instagram, qui accepte
   bien davantage, recevait quand même le fichier dégradé.

Et il n'existait aucun moyen d'envoyer une **vidéo** par API :
`POST /api/media/ingest` n'accepte que des images et exige un `phash`, qui n'est
calculable que sur une image.

## Ce qui a changé côté serveur

### `media_files.preserve_original`

Une colonne, pas un réglage global : les quatre chemins d'entrée historiques
(upload web, `/media/ingest`, téléchargement d'URL, import externe) gardent
**exactement** le comportement qu'ils avaient. L'intention est portée par la
ligne, ce qui permet aux deux régimes de cohabiter.

### `POST /api/media/upload`

Images **et** vidéos, l'octet exact — ni GD ni ffmpeg sur le fichier stocké.

| Champ | |
| --- | --- |
| `file` | requis. jpeg, png, gif, webp, mp4, mov, webm. Plafonds : réglages `image_max_upload_mb` (50) et `video_max_upload_mb` (500) |
| `folder_path` / `folder_id` | dossier de la médiathèque, créé au besoin |
| `description_fr`, `thematic_tags[]`, `brands[]` | optionnels |
| `intimacy_level` | `public` par défaut ; un dossier privé escalade en `never_publish` |

**Idempotent par `content_hash`** (SHA-256 des octets) : renvoyer le même
fichier rend `200` + `status: "exists"` au lieu de créer un doublon. C'est ce
qui rend un lot relancé après coupure réseau sans danger. `/ingest` garde son
idempotence par `phash`, qui ne vaut que pour les images.

L'extension n'est jamais changée : pas de PNG → JPEG, c'est précisément la
conversion qu'on évite.

### La normalisation est passée à la publication

[`MediaVariantService`](../app/Services/Media/MediaVariantService.php) confronte
un fichier `preserve_original` aux plafonds du réseau **visé**
([`config/media_variants.php`](../config/media_variants.php)) et rend :

- l'original, s'il passe — le cas courant ;
- une **variante écrite à côté** sinon, l'original restant intact.

La variante est un vrai fichier dans `media/`, pas un temporaire, pour deux
raisons dont la seconde est la vraie :

1. la publication est asynchrone, un job par compte — un temporaire serait à
   refaire pour chaque réseau ;
2. **Threads, Bluesky et Telegram ne reçoivent pas d'octets** mais une URL
   qu'ils viennent lire eux-mêmes. Il faut donc qu'elle soit servable par
   `media.show`, ce qui exige un fichier sur le disque.

Son nom porte le plafond, pas le réseau (`slide--i1.jpg`, `reel--v50.mp4`) :
deux réseaux aux mêmes contraintes partagent la même variante. Elle est
réutilisée tant qu'elle est plus récente que l'original.

**En cas d'échec — ffmpeg absent, image illisible — l'original est envoyé** et
l'incident journalisé. Le refus du réseau sera plus parlant qu'une publication
annulée ici sans explication.

### Un seul endroit décide

Les quatre chemins qui publient un `Post` portaient chacun sa copie de
`resolveMediaUrls()`, `guessMimetypeFromFilename()` compris. Elles sont
remplacées par [`ResolvesPublishableMedia`](../app/Concerns/ResolvesPublishableMedia.php) :
trois copies, c'étaient trois occasions d'oublier un plafond.

La résolution prend désormais **la plateforme en paramètre obligatoire**.
`PublishController::publishAll()` la faisait une fois avant sa boucle : elle est
passée **dans** la boucle, sinon tous les réseaux recevraient la variante
calculée pour le premier. `ThreadPublishingService` garde la sienne (les fils
publient par URL, sans `local_path`) mais reçoit aussi la plateforme.

## Le CLI

[`cli/rsmax.py`](../cli/rsmax.py) — **bibliothèque standard uniquement** : ni
pip, ni venv, ni compilation. Python 3.8+ suffit, ce qui compte sur un poste
Windows où l'on ne veut pas installer une chaîne de build pour publier une photo.

```bash
python3 cli/rsmax.py config --profil dix --url https://rs.dix-10.com/api --token <jeton>
python3 cli/rsmax.py comptes
python3 cli/rsmax.py envoyer ~/Videos/septembre/ --dossier "Videos DIX"
python3 cli/rsmax.py publier --texte "8 g de citrulline 60 min avant la séance." \
    --comptes 23,21,29 --le "2026-10-08 18:00" --media ~/Videos/citrulline.mp4
python3 cli/rsmax.py etat --statut scheduled
python3 cli/rsmax.py etat --id 503
```

`--dry-run` sur n'importe quelle commande montre ce qui serait fait sans rien
envoyer. `--json` donne la sortie brute.

Tests (aucun appel réseau) : `python3 -m unittest discover -s cli/qa`.

### Trois pièges qu'il encode

- **Un seul compte par réseau et par publication.** L'API accepte deux comptes
  Bluesky (`201`) mais ne publie rien : l'anomalie est silencieuse et ne se voit
  qu'à l'heure prévue. Le CLI la refuse avant l'envoi. Pour publier côté Cyril
  **et** côté DIX, deux publications distinctes.
- **Le fuseau est joint explicitement.** `--le "2026-10-08 18:00"` est résolu
  dans le fuseau du poste. Sans fuseau, le serveur interpréterait l'heure dans
  le sien et la publication partirait à côté.
- **Les fichiers cachés sont écartés.** Copier vers un NAS ou un disque exFAT
  sème des `._fichier.jpg` (AppleDouble) qui portent une extension d'image sans
  en être. Un dossier est par ailleurs trié par nom : l'ordre des slides d'un
  carrousel doit être prévisible, pas celui que rend le système de fichiers.

### Le jeton ne vit pas à côté du code

Lu dans l'ordre : `RSMAX_TOKEN` / `RSMAX_URL`, puis
`%APPDATA%\rs-max\config.json` (Windows) ou `~/.config/rs-max/config.json`.
Jamais depuis le dépôt.

C'est délibérément le disque système et pas le disque d'outils : **un disque
exFAT ne porte aucune permission de fichier** — le `chmod` est sans effet et le
disque se rebranche ailleurs tel quel. Le jeton donne le droit de publier au nom
du compte. Même raison que pour les jetons YouTube.

Un jeton par personne (`php artisan api:token --user=<email> --name=<libellé>`) :
les journaux disent alors qui a publié, et révoquer l'un ne coupe pas l'autre.

## Ce que ça ne fait pas

- **La compression du réseau reste.** Instagram, Facebook et les autres
  recompressent à la réception, et personne ne l'évite.
- **L'upload web n'a pas changé.** Déposer une photo depuis l'interface la
  compresse toujours. Étendre `preserve_original` à ce chemin est un réglage à
  ajouter, pas un oubli.
- **Exporter aux dimensions finales reste nécessaire.** Instagram réduit tout ce
  qui dépasse, avec un redimensionnement médiocre : 1080×1350 pour une slide,
  1080×1920 / 30 ips / H.264 pour une vidéo verticale. Préserver l'original
  n'évite pas un export trop grand.
- **Les plafonds de `config/media_variants.php` sont conservateurs.** Un plafond
  trop bas ne coûte qu'une variante inutile ; trop haut, il coûte un échec de
  publication.

## Voir aussi

- [`docs/API.md`](API.md) — référence de l'API
- [`docs/media-catalog-api.md`](media-catalog-api.md) — catalogue et filiation
- Installation sur le poste Windows de Cyril : vault DIX,
  `Technique/installation-cli-rsmax-windows.md`
