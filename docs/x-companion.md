# X — mentions avec contexte, RS Companion 0.9.0

Pour le routage des tâches actuelles sur le NAS, le VPS et Chrome local, voir
[Profils d’exécution Companion](companion-workers.md).

Depuis Companion 0.18.33, le composeur commun vérifie l’auteur, le texte et le parent immédiat de la réponse dans son fil. Si le fil n’affiche pas encore le parent, Companion relit le permalien dans un nouvel onglet. Une cible qui apparaît seulement comme ancêtre ne suffit pas à confirmer le reçu. Le clic de réponse est unique. Le [patch](../companion/patches/0.18.33-x-parent-immediat.patch) s’applique à Companion 0.18.32.

Companion 0.18.34 attend le rendu de l’onglet Abonnements sur Accueil. Au changement d’onglet, il vérifie la sélection et attend de nouvelles publications avant de lire. Le même parcours sert à Hermès et à la source **Accueil · Abonnements** du lot local. Le [patch](../companion/patches/0.18.34-x-fil-abonnements.patch) s’applique à Companion 0.18.33.

Depuis `https://x.com/notifications/mentions`, choisir l’action X, le compte RS-Max
si nécessaire, le mode aperçu ou publication, et un seul maximum (10 par défaut,
1–100). Cadence : 3–8 s avant like, 8–20 s entre like et réponse, 25–75 s entre
mentions ; pauses prolongées après 3–5 réponses. Réglages conservés dans le profil
Chrome. Le nouveau plafond unique et l’attente HTTP 429 s’appliquent aussi à TikTok.

## Contexte obligatoire

Chaque mention est ouverte dans son fil. Le parcours remonte en haut, ouvre les
échanges précédents signalés par X, développe les textes longs puis lit les
publications affichées du tweet d’origine jusqu’à la mention, dans l’ordre.
La génération reçoit l’origine, nos éventuelles réponses intermédiaires et la
mention, avec leurs auteurs et IDs. Citations et médias non interprétés sont
signalés séparément. L’origine sans texte lisible, une origine indisponible,
un texte encore tronqué ou une chaîne dépassant 20 publications font ignorer
la mention ; aucune réponse hors contexte.

Le contexte est celui que l’interface X rend accessible après remontée du fil.
Le DOM ne fournit pas le graphe exhaustif des parentés : une branche cachée par X
sans bouton d’ouverture peut manquer. Un média sans texte exploitable n’est pas
interprété par vision et une vidéo n’est pas transcrite. Commencer par l’aperçu.

Toute mention avec au moins une réponse visible dans le compteur est ignorée
(même si la réponse vient d’un tiers), ainsi que nos propres publications et les
mentions sans texte. Ce choix évite de répondre deux fois à un fil déjà traité.

## Identité et sécurité de reprise

Le profil provient de `AppTabBar_Profile_Link`, jamais de l’auteur consulté.
L’ID natif, quand disponible via le lien des suggestions du compte, est rapproché
d’un compte X actif autorisé dans RS-Max. Sinon, association manuelle mémorisée
par handle X. Aucun compte Instagram ni persona arbitraire ne peut être utilisé.
Un changement de compte, de persona, de texte ou de contexte bloque l’envoi.

Tables `extension_x_profiles` et `extension_x_replies`. Unicité par compte social
et ID réel du tweet, même si le handle change. États prepared → publishing →
published/uncertain. Réservation atomique ; aucune réémission automatique après
une réservation, une coupure ou un résultat incertain. Le compteur ne compte une
réponse qu’après apparition d’un nouveau post exact du compte dans le fil.

Les boutons X `data-testid=like` et `unlike` font foi ; aucun unlike sur un like
existant. Le bouton Répondre doit être actif et l’éditeur viser la bonne mention.
L’aperçu permet de vérifier le brouillon. RS-Max applique une borne prudente selon
la pondération de X et reformule une fois si le texte est trop long. Le bouton X
reste l’arbitre final de la limite.

## API Sanctum

- GET `/api/extension/x/context?username=…&platform_account_id=…` : résolution et
  comptes X autorisés, sans écriture ni export des prompts/credentials.
- PUT `/api/extension/x/context` : username, social_account_id, ID natif facultatif.
- POST `/api/extension/x/suggest` : username, tweet_id, author, content, root_id,
  context_complete=true, conversation (1–20 entrées id/author/text/quote/media).
  Le serveur exige que l’origine soit la première entrée et la mention la dernière.
- POST `/api/extension/x/{id}/begin` : username et reply_text exact.
- POST `/api/extension/x/{id}/finish` : username, published/uncertain, liked, proof.

Le token reste dans le service worker. Aucun cookie ni API X privée exportés.
Les requêtes de traitement sont espacées de 2,5 s ; Retry-After est respecté sur
429, reprise bornée de la seule étape rejetée. Pas de reprise sur réseau/HTTP 500.

Validation : XExtensionTest + TikTokExtensionTest + ExtensionApiTest + InboxApiTest ;
tests Node de l’extension ; Chromium jetable avec extension réelle, deux profils
simulés, origine + réponse intermédiaire + mention, clic de like unique et réponse
confirmée. Aucun envoi réel requis pour ces tests.

Companion 0.18.35 propose **Ajouter aux signets** dans **X — Traiter les publications récentes**. Le signet déjà visible est ignoré. Pour un nouveau signet, le lot réserve la cible dans RS-Max, clique une fois et vérifie après rechargement. Le reçu `x.bookmark` source `extension` partage la réservation avec Hermès. Le [patch](../companion/patches/0.18.35-x-signets-locaux.patch) s’applique à Companion 0.18.34.
