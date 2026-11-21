# Profils Facebook et Instagram par Companion

Première étape : connecter une session personnelle à RS-Max, la transmettre depuis le Chrome local et vérifier son identité sur le VPS ou le NAS. Les comptes API existants gardent leur parcours.

Dans Ajouter un compte, sélectionner Facebook ou Instagram puis Companion sur le VPS/NAS. Renseigner l’identifiant numérique, le profil du worker et le nom Instagram pour Instagram. Companion propose « Identifier mon compte » dans le navigateur connecté pour relever ces informations publiques. Choisir ensuite le persona dans la fiche du compte.

Dans le même Chrome, lancer « Facebook — transmettre la session à RS-Max » ou « Instagram — transmettre la session à RS-Max ». Les cookies restent dans le service worker et dans `browser_session`, chiffré en base. Le serveur ne les place pas dans le payload de la tâche ni dans les journaux. Seul le worker administrateur prenant la tâche peut demander la session du compte associé.

La session n’est connectée qu’après vérification sur le navigateur distant. Pour Facebook, le profil actif (`i_user`, sinon `c_user`) doit aussi correspondre à l’acteur de la page. Pour Instagram, l’identifiant de la session et le nom du profil dans la navigation doivent correspondre au compte attendu. Une autre identité, une connexion à confirmer ou un contrôle Facebook/Instagram font échouer la vérification ; aucune action sociale n’est exécutée.

Une tâche à la fois par worker. Les sessions X, Facebook et Instagram concernent des domaines différents. La restauration Meta ne touche pas aux cookies X. Le Chrome local ne change jamais automatiquement de compte et ne relève pas la file du VPS.

## Parité locale et distante

Chaque nouveau parcours doit être utilisable depuis Companion sur le Mac et depuis le worker distant, avec les mêmes identités, réservations, quotas et preuves. Les premières actions ajoutées sont l’identification locale et la transmission de session, avec vérification distante. Companion 0.18.62 ajoute les deux gestes Facebook de groupe et les gestes Instagram décrits ci-dessous. Les réponses inbox et publications Meta personnelles restent hors de ce parcours. Un compte Meta en mode navigateur ne retombe pas sur les API Pages/Instagram.

API authentifiée, compte actif attribué à l’utilisateur :

- `GET /api/extension/meta/{facebook|instagram}/session?platform_account_id=ID`
- `POST /api/extension/meta/{facebook|instagram}/session` avec `platform_account_id` et cookies de ce seul réseau.

La transmission demande une extension actualisée et rechargée. Conserver `config.local.js` propre à chaque installation. Réserver les files libres avant les deux redémarrages distants et vérifier la version active. Le Mac reste sous le contrôle de Xavier pour le rechargement de l’extension.

## Ciblage choisi le 20 novembre 2026

Facebook personnel : partir de groupes choisis par compte, puis de leurs publications et commentaires. Deux gestes indépendants, avec activation, quota et déduplication distincts : `comment` pour commenter une publication de groupe avec le persona du compte ; `comment.like` pour aimer un commentaire de cette publication sans publier de texte. Le like des commentaires ne doit pas déclencher un commentaire de publication, ni l’inverse. La cible et son groupe doivent être confirmés dans les deux parcours. Les réponses inbox restent un troisième parcours (`reply`). Les deux gestes ont un interrupteur et un quota indépendants dans Automatisation → Facebook ; désactivés par défaut.

Instagram : partir des hashtags, aimer des publications et commentaires, suivre des comptes et vérifier le retour d’abonnement avant un éventuel désabonnement après le délai réglé. Ne pas appliquer la méthode Instagram aux profils Facebook.

La fiche d’un compte Facebook Companion propose `agent_strategy.facebook_groups` : une liste de liens HTTPS directs de groupes. Le même champ est lisible et modifiable avec l’API stratégie habituelle. Les variantes `web`, `m` et les paramètres de partage sont normalisés ; les liens de publications, raccourcis `/share/`, liens externes et domaines ressemblants sont refusés. La liste appartient au compte et un PATCH partiel la conserve ; `[]` la vide. Cette configuration est commune aux futurs exécutants local et distant.

Enregistrer les groupes n’active aucun geste. Les lectures et actions se règlent séparément dans Automatisation. Aucune adhésion à un groupe ni demande d’ami n’est automatisée.


## Exécution des actions (Companion 0.18.62)

Dans Automatisation → Facebook / Instagram, activer les lectures et les gestes voulus pour le compte, régler leurs quotas et enregistrer. Le bloc « Agir depuis le VPS » lit un groupe choisi / hashtag / profil / publication puis propose les gestes sur les seules cibles lues. Le commentaire Facebook se prépare avec le persona ou un texte fourni, avec aperçu à blanc possible avant envoi. Les autres réseaux et comptes API conservent leur chemin.

Dans Companion local, recharger l’extension et l’onglet puis choisir une recette Facebook / Instagram. « Exécuter les gestes » est décoché par défaut. Deux recettes Facebook indépendantes : commenter la publication du groupe et aimer ses commentaires (limite réglable). Instagram : lire un hashtag ou une publication, aimer la publication, aimer ses commentaires, suivre / désuivre le profil ouvert, vérifier un retour d’abonnement. Le Mac ne change pas de compte, n’interroge pas la file distante et exécute uniquement les tâches du lancement manuel, attachées au jeton qui les a créées.

Moteur commun : `MetaBrowserAgent`, tâches `facebook.agent.{read,act}` / `instagram.agent.{read,act}`, reçus permanents `meta_engagements`. Réservation sous verrou avant prise, autorisation à usage unique juste avant le clic, identité et groupe vérifiés de nouveau, résultat confirmé uniquement après rechargement. Après autorisation sans preuve, résultat incertain et aucun renvoi ; la suppression d’une tâche ou la purge du journal ne libèrent pas sa cible. Les deux actions Facebook ont des clés de cible et compteurs distincts. Toute lecture reste limitée au DOM chargé ; `complete:false` ne prétend pas couvrir la discussion entière.

API gestionnaire, compte attribué actif : `POST /api/agent/accounts/{id}/meta/read`, puis `GET /api/meta/tasks/{task}` ; `GET /api/meta/tasks/{task}/context` expose le persona et le contexte pour rédiger sans appel IA RS-Max. `POST /api/meta/tasks/{task}/draft` est la proposition manuelle avec le générateur configuré dans RS-Max. `POST /api/agent/accounts/{id}/meta/act` reçoit `read_task_id`, `action`, `target_id` pour un like de commentaire, et `text` + `persona_hash` pour commenter une publication. `dry_run:true` par défaut ; envoi explicite avec `dry_run:false` et `request_key` UUID. Lectures possibles : Facebook `group.read` / `thread.read` ; Instagram `search.read` (`hashtag`), `thread.read` (`url`), `profile.read` et `followback.read` (`handle`). Les alias lecture/envoi/contexte/état sous `/api/community-manager/` gardent le périmètre du jeton Hermès et ne lui ouvrent pas la session du worker.

Local : `/api/extension/meta/{platform}/agent/{read,act}`, avec l’identité tirée des cookies par le service worker. Les tâches sont prises par leur ID (`POST /api/meta/tasks/{task}/claim`) avec le même jeton et profil `mac-local`, jamais via la relève administrative. Autorisation finale : `POST /api/meta/tasks/{task}/authorize`. Le résultat passe par la route de fin standard ; aucun cookie ni jeton ne traverse les messages de page.

Instagram : `instagram:unfollow-due [--account=ID] [--commit]` (aperçu par défaut), planifié toutes les cinq minutes de 06 h à 22 h Lisbonne. Il ne fonctionne que si le désabonnement et ses lectures sont activés. Seuls les suivis nouveaux confirmés par RS-Max sont enregistrés avec `follow_source=agent` ; un compte déjà suivi reste un suivi manuel. Après `unfollow_after_days` (7 par défaut), deux recherches dans la liste personnelle des abonnés, espacées d’au moins 15 minutes dans les dernières 24 h, doivent conclure explicitement à l’absence de retour. Une lecture de profil fraîche précède la préparation, puis le worker vérifie une dernière fois la liste personnelle juste avant le clic. Absence de badge, erreur de lecture, demande privée en attente, suivi manuel ou retour positif : aucun désabonnement. Le parcours local applique les mêmes conditions.

Companion 0.18.62 charge identité, moteur et recettes Meta dans une seule déclaration de scripts, dans cet ordre. Les likes incertains peuvent être rapprochés d’une nouvelle lecture avec `POST /api/meta/tasks/{task}/reconcile` (`read_task_id`) : vérification seule, sans second clic ni nouvelle réservation.

Facebook : le filtre « Tous les commentaires » ne conditionne pas la lecture. Seuls les commentaires chargés sont proposés, avec `complete:false`. Un like ouvre la cible par son identifiant natif ; un commentaire publié est relu par son identifiant renvoyé. Les URL de preuve sont construites sur la même publication, avec `comment_id` et, pour une réponse, `reply_comment_id`.

## Likes de commentaires Instagram quotidiens (21 novembre 2026)

Le passage VPS s’active dans la fiche Instagram Companion avec « Aimer automatiquement les commentaires des publications Instagram sur le VPS » (`agent_strategy.instagram_comment_likes_enabled`, accès gestionnaire complet). Il utilise les hashtags et exclusions de cette fiche. Les trois actions `search.read`, `thread.read`, `comment.like` doivent être activées dans Automatisation. Leur quota est conservé par compte ; pour le démarrage de Nina et Lucie, `comment.like` et le plafond global du compte sont fixés à 50 par jour, cinq gestes par heure. Le compteur repart à minuit à Lisbonne, local et VPS compris. Un résultat incertain consomme aussi son emplacement : cinquante est un maximum, pas un volume garanti.

`instagram:like-comments [--account=ID] [--commit]` planifie un seul ordre par compte et passage : découverte d’un hashtag, lecture d’une publication, puis likes unitaires de ses commentaires. Le cron passe toutes les cinq minutes entre 06 h et 22 h, avec priorité au compte servi le moins récemment. Cinq commentaires au maximum par publication et par jour ; commentaires du compte, de l’auteur, déjà aimés, déjà réservés ou contenant une exclusion sont ignorés. Aucun follow ni like de publication n’est déclenché par ce passage. Les mêmes reçus permanents et preuves après rechargement s’appliquent aux recettes locales « Instagram — liker les commentaires de cette publication ».

Un incident du passage suspend les nouveaux ordres quinze minutes. Une limitation Instagram explicitement relevée dans `result.platform_limit` (`source=platform_ui`) suspend tout le profil navigateur Instagram : une heure pour `temporary`, jusqu’à minuit Lisbonne pour `daily`. Le local du même compte respecte cette pause. Aucun changement d’empreinte ni renvoi d’un geste incertain. Une session transmise pendant cette pause est enregistrée chiffrée en `pending` (HTTP 202) ; le passage vérifiera son identité après la pause, avant toute action. Une connexion erronée ou expirée reste bloquée.
