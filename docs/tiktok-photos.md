# Photos et carrousels TikTok — Companion NAS

Depuis **Médias**, choisir une photo ou une sélection, puis **Publier sur TikTok**. Réordonner les images, choisir le compte, fournir titre et description et choisir Tout le monde ou Moi uniquement. **Vérifier les médias** n’ouvre pas TikTok. **Tester sur le NAS** importe et prépare sans cliquer sur Post. **Publier** demande l’envoi ; seul `confirmed:true` après rechargement prouve sa réussite.

## API Hermès

Toutes les routes suivantes sont sous `/api/community-manager`, accessibles avec le jeton dédié et le compte attribué.

- `GET media/folders` : dossiers publics, ancêtres compris.
- `GET media/search?folder=SLUG&limit=10` : médias et descriptions ; mêmes filtres que l’API média, notamment exclusion des publications récentes par compte.
- `POST accounts/{id}/tiktok/photo-posts` : `media_ids` ordonnés (1–35 distincts), `caption` (4 000 unités UTF-16), `title` facultatif (90), `visibility` (`public` ou `private`). Aperçu serveur avec `dry_run:true` par défaut.
- Même appel avec `dry_run:false`, `rehearse:true` et `request_key` UUID stable : répétition sur le NAS, aucun clic de publication.
- Même appel avec `dry_run:false`, `rehearse:false` et une autre clé stable : publication. L’action `photo.publish` doit être activée pour ce compte dans Automatisation → TikTok ; maximum initial proposé 3/jour. Une publication, quel que soit le nombre de photos, consomme un geste et ce quota.
- `GET tiktok/photo-posts/{id}` : état durable (`pending`, `running`, `done`, `failed`, `uncertain`, `cancelled`), résultat, preuve, erreur et diffusion RS-Max associée. `rehearsed:true` ne signifie jamais publié.
- `POST tiktok/photo-posts/{id}/verify` : relecture seule d’un envoi incertain. Aperçu par défaut ; `dry_run:false` rapproche une preuve complète sans renvoyer ni recompter les médias.

Commandes du client : `media-folders`, `media-search --folder SLUG`, `photo-post --account ID --media ID_MEDIA… --title "…" --caption "…" --visibility private|public [--request-key UUID --rehearse --commit]`, `photo-status ID`, `photo-check ID [--commit]`.

Conserver la clé **avant** HTTP. Même clé et autre contenu → 409. Même clé et même contenu → même tâche. Un contenu identique déjà en attente, incertain ou publié dans les dernières 24 h est aussi rapproché sans seconde tâche. Après timeout : lire l’état, jamais créer une nouvelle clé pour contourner l’incertitude.

## Validation et preuve

JPEG, PNG, WebP locaux, 50 Mo/photo et 250 Mo/lot maximum, sans conversion vidéo ni remplacement de l’original. Les photos privées, `never_publish` et les dossiers privés hérités sont refusés. Les fichiers et leur ordre sont figés, contrôlés par SHA-256 au départ, à la prise de tâche et sur les vignettes blob de l’éditeur. Si Studio réencode les vignettes, comparaison des pixels normalisés (écart RMS ≤ 6 %, attribution unique avec une marge de 2 %) ; un ordre ambigu est refusé. La cadence et le quota sont réservés avant le geste. Compte, médias, activation, visibilité, texte et fenêtre 06–22 h Lisbonne sont recontrôlés avant Post.

Companion 0.17.0 ouvre un éditeur neuf. Une intention locale précède le clic ; un checkpoint serveur conserve le reçu après le clic avant toute vérification. La lecture suivante exige une URL native `/photo/`, l’auteur attendu, le titre, la description et le nombre de photos après rechargement. Si les données de réhydratation manquent, lire uniquement la carte photo : lien auteur, description exacte, titre dans les textes alternatifs et indices de slides distincts ; le libellé Privé est exigé pour un post privé. Les recommandations voisines ne comptent pas. Sans preuve complète : `uncertain`, aucun retry. Une panne avant envoi reste distincte. Le délai d’écriture est de 20 min ; une tâche déjà prise a 5 min supplémentaires pour lire sa preuve, sans autorisation de cliquer après l’échéance.

Les publications confirmées entrent dans `posts`/`post_platform`, héritent des partenaires et alimentent `media_publications` une seule fois. Brouillons de test et envois incertains ne marquent aucun média publié. Les compteurs et tâches restent durables ; les diagnostics courts suivent la rétention de sept jours. Aucun repli API TikTok ni navigateur VPS.

Le texte est fourni par Hermès via son abonnement ou par l’utilisateur. **Aucun persona pour une publication**, aucun appel IA ni traduction automatique dans ce parcours. L’ancien cycle d’inbox ne publie pas spontanément des photos ; utiliser la commande de publication pour une mission explicite.

## Déploiement et vérifications

Le code Companion vit dans le projet Chrome séparé ; le patch sans configuration privée est archivé dans `companion/patches/0.17.0-tiktok-photos.patch`. Préserver `config.local.js` lors d’une mise à jour NAS. Suspendre les nouveaux cycles, attendre leur fin et l’absence de tâches pending/running, puis copier les fichiers et redémarrer deux fois. Tester le refus d’un compte sans session. Reprendre le cycle à la fin.

Validation locale : `TikTokPhotoPublishingTest`, tests budget/file/inbox ; tests CLI ; `tests/e2e/tiktok-photos.cjs` dans une copie sans `config.local.js`, avec Chromium jetable et TikTok simulé. Le test Chromium couvre photo seule, carrousel, ordre inversé refusé, confidentialité, texte Unicode, preuve après reload, double appel et résultat incertain. Pour l’horloge du test Chromium, exécuter entre 06–22 h Lisbonne.

Essai réel du 08/11/2026 : répétition de deux photos réussie via le jeton Hermès, puis un seul carrousel technique privé envoyé. La relecture DOM retrouve deux slides, le texte et la visibilité privée ; rapprocher cette preuve par `photo-check --commit`, jamais par un nouvel envoi.

Correction Companion 0.17.1 : les descriptions natives représentent les paragraphes par `<br>`, parfois ignorés par `innerText`. Reconstituer ces retours avant la comparaison exacte du texte ; conserver auteur, titre et nombre de slides. Patch incrémental `companion/patches/0.17.1-tiktok-photos-paragraphes.patch`, à appliquer après 0.17.0. Le carrousel public de trois images du 08/11 a aussi été relu sans connexion sur le NAS. Une incohérence de lecture se corrige par `photo-check --commit`, sans nouveau clic Post.
