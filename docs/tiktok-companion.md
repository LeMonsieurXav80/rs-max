# Commentaires TikTok dans RS Companion 0.8.0

TikTok Studio `/tiktokstudio/comment` est piloté par l’extension. RS-Max conserve
les associations de profils, génère avec le persona du compte RS-Max associé et
réserve les envois. Aucune API TikTok privée ni cookie exporté.

## Utilisation

Dans TikTok Studio, choisir « TikTok — répondre et liker les commentaires ».
Associer une fois le profil TikTok au compte RS-Max qui porte son persona.
Le rattachement est mémorisé par handle TikTok, jamais globalement. Le serveur
contrôle le pivot utilisateur/compte actif et exige un persona actif et renseigné.
Les changements de persona sont relus ; un brouillon préparé avec un autre persona
ne peut pas être envoyé.

Choisir « Préparer une réponse à relire » ou « Répondre et liker le lot », puis le
nombre de commentaires (10 par défaut, maximum 100). Les commentaires de toutes
les vidéos chargées dans la liste sont traités ; la légende de chaque vidéo est
fournie à la génération. Le filtre natif « Pas de réponse » est obligatoire.
Un commentaire portant déjà une réponse est ignoré, même si ce n’est pas la nôtre.
Les commentaires du compte lui-même sont ignorés.

Le profil est relu depuis le menu du compte avant génération et avant envoi.
Le texte respecte le maxlength réel de l’éditeur (plafond 150 unités UTF-16),
le persona et la langue du commentaire. Une réponse trop longue n’est pas tronquée.
Un cœur plein n’est jamais recliqué. Le like et la réponse sont vérifiés séparément.
La preuve de publication exige le texte exact sous le commentaire, écrit par le
profil attendu. Une disparition du filtre, un éditeur vide ou un simple clic ne
constituent pas une preuve.

## Reprise et limites

`prepared` = texte généré, aucun envoi réservé ; `publishing` = réservation prise,
possible envoi ; `published` = réponse exacte confirmée dans le fil ; `uncertain` =
résultat non confirmé. Les trois derniers états interdisent une nouvelle tentative
automatique. Ne pas remettre à zéro un état sans contrôler TikTok.

Le navigateur sérialise les lots avec Web Locks ; RS-Max réserve chaque envoi par
une mise à jour atomique. Une coupure après réservation reste bloquée. Le lot ignore
les empreintes déjà tentées et poursuit les autres commentaires.

TikTok n’expose pas d’ID de commentaire dans le DOM observé : l’empreinte SHA-256
comprend auteur, texte complet, légende et chemin de miniature sans signature.
Deux commentaires strictement identiques du même auteur sur une même vidéo sont
traités comme un seul, par prudence. Un changement de légende peut changer
l’empreinte ; le filtre natif et les réponses visibles restent indispensables.
La fin de la liste signifie fin des commentaires chargés après trois défilements
sans nouveauté ; elle ne prouve pas l’exhaustivité de tout l’historique TikTok.

Les résultats sont dans `extension_tiktok_comments`, séparés des statistiques
Instagram/Facebook du compte qui fournit le persona. Les secrets restent dans le
service worker et dans les réglages RS-Max. Aucune consigne persona n’est renvoyée
au navigateur.

## API Sanctum

- `GET /api/extension/tiktok/context?username=handle` : association et comptes autorisés.
- `PUT /api/extension/tiktok/context` : `username`, `social_account_id`.
- `POST /api/extension/tiktok/suggest` : `username`, `fingerprint` (64 hex), `author`,
  `content`, `video_context`, `max_length` (1–150). Renvoie le brouillon réutilisable.
- `POST /api/extension/tiktok/{id}/begin` : `username`, `reply_text` exact ; une seule réservation.
- `POST /api/extension/tiktok/{id}/finish` : `username`, `status` (`published` ou `uncertain`),
  `liked` et `proof`. Aucune publication TikTok effectuée côté serveur.
- `GET /api/extension/tiktok/comments?username=handle` : 100 derniers états, lecture autorisée.

Validation : `php artisan test --filter='TikTokExtensionTest|ExtensionApiTest|InboxApiTest'` ;
`NODE_PATH=/chemin/deps node --test tests/*.test.cjs` dans l’extension ;
`NODE_PATH=/chemin/deps RS_TEST_OUTPUT=/tmp/preuve node tests/e2e/tiktok-comments.cjs`
sur une copie de l’extension sans `config.local.js`, Chromium jetable et réseau simulé.
