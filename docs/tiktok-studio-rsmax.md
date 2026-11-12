# TikTok dans le compositeur et les statistiques

- Sélectionner le compte TikTok relié à un profil NAS dans une publication simple ou dans un groupe de comptes. Ajouter une vidéo MP4 ou 1 à 35 photos JPEG, PNG ou WebP de la médiathèque. Le lot est limité à 250 Mo et chaque photo à 50 Mo. Les fichiers doivent être publics et hors dossiers privés. Enregistrer ou modifier la date dans RS-Max.
- À l’échéance, le worker RS-Max demande la publication publique dans TikTok Studio à Companion. La diffusion possède une clé durable. Contrôler la description, les médias et la visibilité avant l’unique autorisation de clic. Seule une publication retrouvée après rechargement reçoit son identifiant et son URL dans RS-Max. Si le résultat est incertain, contrôler Studio avant toute intervention.
- Dans Publications natives, les gestionnaires peuvent actualiser les comptes sélectionnés. L’import lit les publications publiques dans Studio et conserve les compteurs exacts disponibles. Le passage de fond toutes les six heures alimente également les statistiques par publication et la courbe d’abonnés. La photo de profil est copiée dans RS-Max après vérification de l’identité. L’import et l’adoption du flux natif restent dans l’interface web des gestionnaires.
- Inbox TikTok : priorité au plus ancien commentaire éligible. Au-delà de 24 h, relire la cible exacte dans Studio avant de préparer la réponse. Si elle est introuvable, reporter sa sélection et passer à la suivante. Les tentatives existantes restent exclues.

Companion 0.18.8 est requis. Appliquer `companion/patches/0.18.8-tiktok-studio.patch` sur 0.18.7. Le service natif NAS observe uniquement les réponses des requêtes que Studio effectue déjà et ne transmet que les champs nécessaires. Suspendre les futurs cycles et laisser la file se vider avant les deux redémarrages. Vérifier la version et le refus d’un compte sans session avant de reprendre.

### Preuve publique des réponses — Companion 0.18.9

Appliquer `companion/patches/0.18.9-tiktok-preuve-publique.patch` sur 0.18.8. La relecture anonyme privilégie le fil du parent exact et attend les chargements lents dans la fenêtre existante. Seuls le CID, le parent, la vidéo, l’auteur et le texte exacts, servis publiquement et visibles après rechargement, confirment la réponse et permettent le like.

`public_reply_absent` exige une lecture paginée complète du fil. `public_reply_unverified` indique une lecture incomplète ou une preuve visuelle manquante. Le code commentaire `2` a été observé après un clic accepté avec un reçu HTTP 200, sans visibilité publique. Sa signification interne reste inconnue. Garder la tentative verrouillée, même en cas de délai ou de preuve manquante.

### Découverte des comptes cibles — Companion 0.18.10

Appliquer `companion/patches/0.18.10-tiktok-comptes-cibles.patch` sur 0.18.9. L’action `feed.read` accepte `feed: targets` et un `target` facultatif. Le profil doit figurer dans les comptes cibles du compte TikTok associé. Sans profil explicite, RS-Max prend le premier compte cible valide. Companion relève jusqu’à dix vidéos publiques du profil sur le NAS.

Pour Hermès, envoyer ces paramètres à `POST /api/community-manager/read` avec `account_id` et `action: feed.read`. Lire les commentaires d’une vidéo avec `action: video.comments`, puis consulter jusqu’à vingt-cinq entrées avec `GET /api/community-manager/snapshots/{id}?limit=25`. Le client propose aussi `discover` et `snapshot`. Un like demande une préparation puis une exécution explicite. Vérifier la preuve `after_reload` et conserver un like déjà présent.

## Community manager TikTok — règles de X (12 novembre 2026)

Hermès répond seul aux commentaires reçus par le compte TikTok, du plus ancien au plus récent, avec le persona du compte, la légende, les échanges connus et les sources du Vault rattachées (`vault`). Companion local (Mac), le NAS et un VPS appliquent les mêmes règles, vérifiées par RS-Max.

| Règle | Où elle est appliquée |
| --- | --- |
| Simple merci sans question : like, jamais de réponse (tous réseaux ; like là où il existe) | `reply-context` (avant toute lecture Studio, `skip_item` + `not_relevant`, like programmé) ; `/extension/tiktok/suggest` répond 409 `like_only` et Companion 0.18.41 aime le commentaire si la recette l’autorise |
| Emojis seuls, autocollants (`[J'aime]`), autocollant ou GIF sans texte : un like et une réponse 🙂, 😊 ou 😄 (`reply_options`, imposé à `reply` ; tirée par RS-Max sans IA pour Companion local, TikTok et X) |  `InboxFollowup::isEmojiOnly()` ; Companion 0.18.43 ne saute plus une ligne sans texte |
| Relance sous la réponse du compte sans question : like | `reply-context`, d’après `reply_to_username` |
| Adressage : `interpelle` (commentaire sous la vidéo ou réponse au compte), `participant` (réponse à quelqu’un d’autre) | `TikTokAddressing`, dans `addressing` ; un remerciement en `participant` est refusé (422) |
| Aucun emoji de cœur, de bisou ou de rose, aucune question finale sans question reçue | `XReplyStyle` : `reply` (422), suggestion locale (une reformulation puis 422), commentaires de vidéos (`prepare`, générateur) |
| Pas de relecture inutile | 3 relectures `tiktok.verify` au plus par tentative ; aucune relecture différée si le reçu porte `comment_status` 2 ; un ancien commentaire introuvable deux fois dans Studio est écarté 7 jours |

**`comment_status` au reçu d’envoi.** 7 est le code normal. 2 n’a jamais été confirmé sur six jours (environ 30 cas, souvent des « Merci, c’est gentil 😊 » répétés) : TikTok retient la réponse. Elle reste verrouillée (`reply_failed`, 3 tentatives), sans relecture ni renvoi.

**`reply_to_username`.** Companion 0.18.41 (actif en 0.18.42 partout depuis le 12/11 au soir) lit le second lien d’auteur d’une ligne Studio (« auteur ▸ destinataire »). Sans lui, un commentaire est considéré comme adressé au compte.

### Incident du 12 novembre : file TikTok arrêtée

La gateway Hermès a été redémarrée par son watchdog à 11 h 21 (heure de Paris), le VPS étant en swap. Le cycle TikTok en cours est devenu « inconnu » et l’ordonnanceur l’a mis en pause (`Canonical cron unknown; no automatic re-execution`), sans alerte : aucune réponse de 11 h 27 à 17 h 45. RS-Max, la session et la file `nas-wildycaro` étaient sains (attente moyenne 7 s). Le moniteur X (`rsmax_x_watch.py`, toutes les 2 minutes) relance désormais une file `RS-Max — File …` en pause pour ce motif depuis plus de 5 minutes ; RS-Max reste juge des doublons (tentatives verrouillées, réservations).

Diagnostic d’une file muette : `enabled`, `state` et `paused_reason` des jobs dans `cron/jobs.json` du profil Hermès, puis `docker logs … | grep "missed 3 consecutive liveness"`, avant de soupçonner RS-Max ou l’agent.

### Réponses retenues : l’environnement d’envoi, pas le volume

Du 4 au 6 novembre, toutes les réponses partaient du Chrome de l’utilisateur (Mac) : 85 publiées, aucune incertaine. Les incertaines (code 2) apparaissent le 7-8 novembre avec le NAS. Le Mac et le NAS sortent par la même box. Restent trois différences non départagées : session copiée sur un second appareil, Chromium Linux sous Docker/Xvfb, saisie native. Ni le rang dans la journée ni la répétition du texte n’expliquent clairement le code 2.
