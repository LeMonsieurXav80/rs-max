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
