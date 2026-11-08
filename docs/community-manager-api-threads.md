# Community Manager — API et navigateur Threads

Le cycle découvre les comptes attribués, personas, actions et cadences dans RS-Max. Aucun compte dans les consignes. Réponses rédigées avec l’abonnement Hermès, jamais avec l’API IA RS-Max. Fenêtre 06–22 h Lisbonne ; bilans Telegram 08 h et 20 h.

## Inbox API
Facebook, Instagram, Threads et YouTube passent par `accounts/{id}/inbox`, `inbox/{id}/context`, `inbox/{id}/reply` et `inbox/{id}` sous `/api/community-manager`. Activer `reply` par compte. La réponse est sauvegardée puis envoyée immédiatement par le worker, sans validation humaine. Une tentative consomme la cadence et le quota durable ; purge des logs sans effet sur ces compteurs. Une exception ou une réponse non confirmée interdit tout renvoi automatique.

Facebook : activer aussi `comment.like`. Après réponse, le like suit la cadence. `POST inbox/{id}/like` (`dry_run:true` par défaut) et `inbox-like ID --commit` permettent un like seul. Lecture de `user_likes` avant et après ; aucun retrait d’un like déjà présent. Tentatives anti-doublon conservées hors des logs de sept jours. `like_required` indique s’il faut attendre ce second geste. Aucun like attendu pour les réponses Instagram, Threads ou YouTube.

## Threads hors inbox
Le compte reste en driver `api` pour ses réponses et publications. Son `browser_profile` et sa session Companion sont utilisés uniquement pour les actions browser : `feed.read`, `search.read`, `thread.read`, `profile.read`, `like`, `comment`. Les activer séparément dans Automatisation → Threads. Pas de suivi/désuivi ni repost dans ce lot.

Depuis le Chrome personnel connecté : Companion → **Threads — transmettre la session au NAS**. Cookies chiffrés côté RS-Max ; domaines Threads uniquement. Identité du compte recoupée par l’API, puis par le profil réellement connecté sur le NAS. Le profil NAS peut être repris des comptes navigateur autorisés partageant le persona, s’il est unique. `threads:session-check` contrôle la session chaque heure pendant la journée et alerte Telegram en cas d’expiration. Le Chrome personnel ne prend pas les tâches NAS.

Routes `/api/community-manager` :
- `POST accounts/{id}/threads/read` : `action`, plus `query`, `url` ou `handle` selon la lecture ; limite 20, instantané 15 min, nombre lu et complétude explicites.
- `GET accounts/{id}/threads/context?snapshot_id=UUID` : fil et persona après une lecture `thread.read`.
- `POST accounts/{id}/threads/actions` : `action:like|comment`, `snapshot_id`, `target_id` si plusieurs cibles, `text` et `context_id` pour commenter. Aperçu par défaut. Exécution avec `dry_run:false` et `request_key` UUID conservé avant HTTP.
- `POST threads/actions/{task}/verify` : relecture seule ; aperçu par défaut, `dry_run:false` rapproche la preuve sans renvoyer ni recompter.
- `GET threads/actions/{task}` : résultat durable. Une tâche incertaine ne se relance pas, même sous une nouvelle clé.

Client : `threads-read`, `threads-context`, `threads-action` (`--commit --request-key UUID`), `threads-status`, `threads-check [--commit]`. Relecture du compte et de la cible avant le geste, un seul clic, vérification après rechargement. Aucun repli automatique navigateur/API après une incertitude. Les commentaires utilisent le persona actif ; ne jamais prétendre avoir lu toute une discussion partielle.

Companion 0.18.0 : patch sans configuration privée dans `companion/patches/0.18.0-threads.patch`, à appliquer après 0.17.1. Suspendre les cycles, attendre la fin du cycle et la file vide, préserver la configuration locale, mettre à jour et redémarrer deux fois. Vérifier le refus sans session, puis reprendre les cycles.

## Validation et mise en service — 08/11/2026
Les quatre réseaux API ont reçu une réponse de test via le jeton Hermès. Relecture API HTTP 200, texte et auteur exacts ; like Facebook confirmé. Rattacher le compte technique aux comptes sociaux activés et arrêter le moteur historique de likes Facebook sur ceux-ci. Cadence initiale API : 20 gestes/h, 100/jour, intervalle 60 s, limites propres à chaque compte. Les consignes de base Hermès sont versionnées dans `hermes/community-manager-soul.md`.

Companion 0.18.0 installé sur le NAS, double redémarrage et refus sans session vérifiés. Tests Chromium simulés réussis ; validation réelle Threads navigateur en attente du rechargement local de Companion et de la transmission de session. La recherche autonome exige aussi une stratégie explicite ; ne pas confondre ce contrôle restant avec les réponses Threads API déjà relues.
