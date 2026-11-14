# Engagement du fil « Pour vous » par script — stratégie à tester

Décidée le 14/11/2026 avec Xavier. Statut : **à construire, puis à tester une semaine**. Réseaux : X et Threads.
Suivi des coûts : `docs/hermes-couts-agent.md`.

## Avancement

**Étape 1 livrée le 14/11/2026** (RS-Max `2bd84f3`, Companion 0.18.52 sur le Mac, le NAS et le VPS DIX) :
- réglages `feed_engagement` dans la stratégie, remplissables par l'API, interrupteur coupé ;
- moteur spintax (`app/Services/Agent/Feed/Spintax.php`), correspondance (`FeedMatcher`), décision par
  publication avec son motif (`FeedEngagementPlanner`), détection simple du français (`FrenchText`) ;
- relevé à blanc : `php artisan x:feed-engage <compte> [--json]`. Il lit le vrai fil (jusqu'à 12 écrans), n'envoie
  rien et journalise la lecture (`agent.feed.read`, `origin=script`, `dry_run=true`) ;
- Companion donne la langue marquée par X (`lang`), les publicités (mention « Ad » ou « Sponsorisé » seulement :
  `placementTracking` entoure aussi chaque vidéo) et les reposts ;
- la prélecture n'envoie plus au modèle que l'audience, l'objectif, les exclusions et la langue de la stratégie.

**Premier relevé à blanc** (Wildy Caro, X, 14/11/2026, environ 13 h) : 25 publications lues, 5 retenues, 5 likes,
3 spintax, aucune pour le modèle.
- Le fil « Pour vous » est surtout en anglais : like et emojis seulement sous ces publications.
- **2 des 5 publications retenues n'auraient pas dû l'être** : une publication sexualisée retenue pour « haltères », et
  une provocation hommes/femmes retenue pour « gym ». Un mot-clé ne dit rien du ton : la liste d'exclusions doit
  couvrir ce vocabulaire avant toute activation.
- Des publications sport ont été manquées faute de mot-clé : « muscle », « biceps », « triceps », « cut »…
- Depuis `2bd84f3`, une exclusion peut être un emoji (💦), et le pseudo de l'auteur est examiné (« mrfunkyxxx »
  contient « xxx »). Un mot exclu court peut écarter à tort un pseudo qui le contient : c'est le prix de la prudence.

**Étape 2 livrée le 14/11/2026** (RS-Max `e55d185`) : exécution des likes et du spintax.
- `php artisan x:feed-engage <compte> --commit` : refusé tant que l'interrupteur de la fiche est coupé. Planifié
  `x:feed-engage --all --commit` toutes les 20 minutes, pour les seuls comptes allumés.
- `FeedEngagementExecutor` : un geste à la fois, par le circuit des gestes d'Hermès (`XEngagement`, cadence et délai
  minimal, tâche Companion `x.agent.act`, preuve après rechargement). Un résultat incertain n'est jamais renvoyé, et
  arrête les autres gestes sur la même publication. Arrêt du passage sur un refus de cadence, de quota ou de réservation.
- Plafonds : du jour (`daily_likes`, `daily_spin_comments`), comptés sur les reçus `XEngagement` `origin=feed_script`
  depuis minuit à Lisbonne, et du passage (`pass_max_actions`, 8 par défaut).
- Un modèle spintax doit passer les règles serveur d'une réponse : longueur X, pas de « merci » en tête
  (`XAddressing::OUTSIDER`). Sinon, un autre modèle est tiré.
- Journal : `feed.like` et `feed.spin` dans `bot_action_logs` (statut, auteur, mots-clés, langue, texte), plus
  `agent.feed.read` par passage (résumé, gestes, motif d'arrêt). **Bilan à J+7** : les reçus `XEngagement`
  (`payload->origin = feed_script`) restent en base au-delà de la purge de 7 jours.
- Quand l'engagement d'un compte est allumé, Hermès ne relit plus son fil (`rsmax_x_watch.discovery_source`).
  **Tant que l'étape 3 n'est pas livrée, les publications « pour le modèle » ne reçoivent que le like** : les
  commentaires rédigés du fil s'arrêtent pour ce compte.

**Listes au 14/11/2026** : Wildy Caro X et Threads 129 recherches et 78 exclusions, Cyril 136 et 81.

**Étape 3 livrée le 14/11/2026** (RS-Max `7b48659`) : lots de commentaires rédigés par Hermès.
1. **[script, 0 jeton]** Le passage réserve les publications « pour le modèle » (`FeedDraftService::request`, reçu
   `XEngagement` « reply » préparé, `draft_state=awaiting`), dans le plafond `daily_llm_comments` qui compte aussi les
   demandes en attente. Journal `feed.llm` `status=requested`.
2. **[script, 0 jeton]** Le catalogue porte `feed_drafts` ; la prélecture X l'annonce dans `priority_feed_drafts`,
   même quand la cadence retient les autres gestes (aucun navigateur en jeu).
3. **[modèle, jetons]** Hermès : `feed-drafts --account ID` (persona une fois par session, jusqu'à 5 publications avec
   leur texte, citation, médias décrits et langue), puis un seul `feed-drafts-submit --account ID --drafts '[…]'`
   (texte ou `skip` avec motif). Consigne dans la skill `rsmax-cycle-x` (+0,7 k caractères).
4. **[script, 0 jeton]** RS-Max valide chaque texte : longueur, pas de « merci » en tête (tiers), persona inchangé
   depuis la lecture du lot, publication de moins de 14 jours, aucune réponse existante. Refus motivé, sinon
   `draft_state=ready`.
5. **[script, 0 jeton]** Le passage suivant envoie d'abord ces textes, par le même circuit que les likes, avec preuve
   après rechargement (journal `feed.llm`). Une demande non remise expire après 3 heures.

**Activé le 14/11/2026 à 13 h 37 UTC sur Wildy Caro (X, compte 7), palier 1** : 150 likes, 20 spintax, 40 rédactions
par jour, 8 gestes par passage, 40 % de spintax. Début de la semaine de test : point à J+7, le 21/11/2026.
- Premier passage (manuel) : 25 lues, 10 retenues, 8 gestes demandés et 8 confirmés en 3 min 30 (7 likes, 1 spintax
  « 💪🔥👏 »), 1 rédaction réservée. Hermès l'a écartée (allusion sexuelle, vidéo non décrite) au réveil suivant.
- Le passage planifié suivant, tombé pendant le passage manuel, s'est arrêté sur « Délai minimal non écoulé » sans
  geste. Corrigé par `1822951` : attente du délai et nouvel essai, une fois.
- Les passages partagent la file du navigateur avec Hermès (mentions) : une tâche à la fois par profil, donc un passage
  de 8 gestes peut durer plus de 10 minutes quand les mentions affluent.
- Une allusion sexuelle (« missionnaire ») a reçu un like : les exclusions par mots ne voient pas tout. À compléter au fil
  des relevés.

**Prochaine étape** : lancement manuel en local et relevé des versions (étape 4), puis Threads et Cyril.

## Exploitation et suivi

**État au 14/11/2026, 14 h 30 UTC** : allumé sur Wildy Caro (X, compte 7) seulement. Trois passages réels : 11 likes et
4 commentaires spintax confirmés, 1 like en échec (pas incertain : aucun risque de doublon), 1 rédaction écartée par
Hermès. Threads (#36) et Cyril (DIX #23) ont leurs listes et leurs modèles, interrupteur coupé.

**Commandes** (conteneur RS-Max de l'instance) :
- relevé à blanc d'un compte : `php artisan x:feed-engage 7` (`--json` pour le détail) ;
- passage réel à la main : `php artisan x:feed-engage 7 --commit` (interrupteur requis ; éviter les minutes 0, 20 et
  40, où tourne le passage planifié) ;
- lot d'Hermès, côté profil Hermès : `python3 scripts/community_manager.py --config community-manager.json feed-drafts --account 7`.

**Allumer, couper, régler** : `PATCH /api/accounts/{id}/strategy` avec un accès gestionnaire, bloc
`agent_strategy.feed_engagement` (`enabled`, plafonds, modèles). Le jeton Community Manager d'Hermès ne peut ni allumer,
ni couper, ni relever un plafond (403). Couper rend aussitôt le tri du fil à Hermès.

**Ce qu'il faut regarder** :
- reçus durables : `x_engagements` où `payload->origin = 'feed_script'` (action, `draft_source` = `script_spintax` ou
  `hermes_subscription`, statut) ;
- journal 7 jours : `bot_action_logs` `feed.like`, `feed.spin`, `feed.llm` (statut, auteur, mots-clés, texte) et un
  `agent.feed.read` par passage (`summary`, `done`, `requested`, `stopped`) ;
- coût : `scripts/rsmax_cost_report.py --history` côté Hermès, colonne « Jetons/geste ».

**Bilan à J+7 (21/11/2026)** : gestes confirmés par jour et par origine, taux d'échecs et d'incertains, motifs d'arrêt
des passages, rédactions écartées par Hermès et leurs motifs, jetons par geste comparés au 12/11 et au 13/11, abonnés X
exacts, réponses reçues sous les commentaires spintax comparées aux commentaires rédigés.

## Threads

**Étape 1 livrée le 14/11/2026** (Companion 0.18.53) : lecture « Pour toi », repères Companion et relevé à blanc.
- `php artisan threads:feed-engage <compte> [--json]` lit `https://www.threads.com/` (« Pour toi », pas `/following/`),
  jusqu'à 25 publications sur 12 écrans au plus, n'envoie rien et journalise la lecture (`agent.feed.read`,
  `origin=script`, `dry_run=true`). `--commit` reste refusé jusqu'à l'étape 2.
- **Hermès lit aussi « Pour toi » en priorité** depuis le 14/11/2026 (décision de Xavier) : `feed.read` ouvre
  `https://www.threads.com/`, et le plan de la prélecture propose les abonnements (`feed: following`) en repli, une fois,
  quand la première lecture ne donne aucune cible (`threads-read --feed following`).
- Code commun à X et Threads : `FeedNetwork` (`XFeedNetwork`, `ThreadsFeedNetwork`) porte ce qui change d'un réseau à
  l'autre (identifiant, ancienneté, langue, longueur d'un commentaire, gestes déjà faits). Les règles de tri restent
  dans `FeedEngagementPlanner`, la lecture et l'affichage dans `FeedEngageCommand`.
- Gestes déjà faits, pour le like, le commentaire et le délai « même auteur » : tâches `threads.agent.act` en attente,
  en cours, faites ou incertaines (Hermès ou script). Un échec franc laisse réessayer.

**Étape 2 livrée le 14/11/2026** : exécution des likes et du spintax, le script attend lui-même la preuve.
- `php artisan threads:feed-engage <compte> --commit` : refusé tant que l'interrupteur de la fiche est coupé. Planifié
  `threads:feed-engage --all --commit` aux minutes 10, 30 et 50 (X tourne à 0, 20 et 40), pour les seuls comptes allumés.
- **Reçu durable : `threads_engagements`** (modèle `ThreadsEngagement`), sur le modèle de `x_engagements`. Choisi parce
  que les plafonds et le bilan à J+7 doivent survivre à la purge de 7 jours de `bot_action_logs`, que les rédactions
  d'Hermès (étape 3) doivent exister avant toute tâche (« en attente », sans geste), ce que `browser_tasks` ne peut pas
  porter sans devenir prenable par le NAS, et qu'une clé unique compte/geste/publication interdit un second geste.
  `browser_tasks` reste la file d'exécution ; chaque reçu pointe sa tâche.
- Un geste est une tâche `threads.agent.act` de même forme que celles d'Hermès (`idempotency_key = threads-feed:<reçu>`) :
  contrôle à la prise (`ThreadsAgent::claim`), autorisation avant le clic, preuve après rechargement
  (`ThreadsAgent::finish`), cadence (`AccountActionBudget`). Hermès et le script se voient : chacun refuse un geste
  déjà demandé par l'autre sur la même publication.
- **Le script attend la preuve** (`ThreadsEngagementService::result`, toutes les 5 s, au plus 9 min 20) : plus aucun
  `threads-status` répété par le modèle pour ces gestes. Un incertain n'est jamais renvoyé et arrête les autres gestes
  sur la même publication.
- Plafonds du jour (`daily_likes`, `daily_spin_comments`) comptés sur `threads_engagements` `origin=feed_script` depuis
  minuit à Lisbonne, en plus des quotas Threads du catalogue (`like`, `comment`) et de la cadence du compte.
- Journal : `feed.like`, `feed.spin` et `agent.feed.read` dans `bot_action_logs`, comme pour X, plus les lignes
  `threads.like`/`threads.comment` que la tâche écrit d'elle-même. **Bilan à J+7** : `threads_engagements` où
  `payload->origin = 'feed_script'`.
- Quand l'interrupteur est allumé, Hermès ne planifie plus de découverte du fil pour ce compte (`priority_threads`).
  **Tant que l'étape 3 n'est pas livrée, les publications « pour le modèle » ne reçoivent que le like.**
- Code commun : `FeedEngagementExecutor` ne connaît plus `XEngagement` ; `FeedNetwork` réserve, envoie et lit l'issue.
- `FrenchText` reconnaît aussi l'espagnol et le portugais courants, sans les mots qu'ils partagent avec le français.
- **Second relevé à blanc** (14/11/2026, 16 h 30 Lisbonne, tâche 10800) : 25 lues, 6 retenues, 6 likes, 3 spintax,
  1 pour le modèle. Une première lecture a échoué juste avant (« Page du navigateur non chargée », tâche 10798) : le
  passage s'arrête et le suivant reprend 20 minutes plus tard. « Um pouco de ousadia nunca sai de moda » passait encore
  pour du français : mots portugais complétés. Des publications sportives restent manquées quand le mot-clé est collé
  dans un hashtag (`#sandbagworkout`, `#hyroxathlete`).
- **Hashtags collés** (accord de Xavier, 14/11/2026) : un mot-clé ou une exclusion de 4 lettres ou plus est aussi
  cherché à l'intérieur d'un hashtag (`#sandbagworkout` → workout, `#onlyfanslink` → onlyfans), sur X et Threads.
  Hors hashtag, les mots restent entiers ; « gym », « abs », « dos » restent des mots entiers partout.
- **Palier 1 de Wildy Caro Threads**, réglé le 14/11/2026 à 16 h 45 (interrupteur coupé) : catalogue 100 likes et
  30 commentaires par jour (au lieu de 20 et 5), cadence du compte 40/h et 200/jour (au lieu de 20 et 100), délai
  minimal 60 s inchangé ; script 80 likes, 15 spintax, 15 rédactions par jour, 8 gestes par passage. Exclusions
  identiques à X (81, `#pub`, `shein`, `code promo` ajoutés sur les deux). @mpbaribeau (selfie + `#gymmotivation`)
  : accepté par Xavier.
- **Quotas Threads du catalogue** (fiche agent du compte) : like 20 et commentaire 5 par jour, partagés avec Hermès.
  Ils bornent le script avant `daily_likes` et `daily_spin_comments` : le 14/11 à 16 h 30, il restait 1 like et
  0 commentaire.
- Fiche de Wildy Caro Threads : mêmes 129 recherches et 12 hashtags que sur X (vérifié), plus 3 exclusions demandées
  par Xavier (`#pub`, `shein`, `code promo`) : 81 exclusions contre 78 sur X.

**Premier relevé à blanc** (Wildy Caro, Threads #36, 14/11/2026 à 14 h 53 UTC, tâche 10755, 25 s) : 22 publications lues
sur 12 écrans, 3 retenues, 3 likes, 0 spintax, 1 pour le modèle.
- Interface en anglais pour cette session aussi (22 sur 22) : 8 liens « Translate », aucune réponse ni publicité.
- Retenues : « Pilates » (publication `#pub` SHEIN, partenariat payé), « training/biceps/triceps » (anglais, question :
  like seul), « course à pied » (français : like et rédaction).
- **Faux français** : « Ni antes ni después, todo llega… » (espagnol) passait pour du
  français (`que` + accent). Corrigé à l'étape 2.
- Le fil « Pour toi » de ce compte est peu tourné vers le sport : 19 publications sans mot-clé sur 22.

**DOM vérifié sur le NAS le 14/11/2026** (CDP du Chromium `rsmax-browser`, file réservée, trois lectures, environ
120 publications) :
- **L'interface Threads était en anglais** (session de Planète de Caro installée ce jour-là) et le lien « Translate »
  figurait sous presque toutes les publications françaises (« Ça y est j'ai 43 ans », « Le deadlift et moi »). Il
  signale une langue différente de l'interface, pas une langue différente du français, et Threads l'omet parfois
  (une publication espagnole sans lien). Companion le transmet (`translatable`) avec la langue de l'interface
  (`ui_lang`, lue sur le bouton « Like »/« J'aime »). **`FrenchText` décide** ; sous une interface française seulement,
  le lien « Traduire » écarte le français.
- Le lien est un `div role="button"` dans le bloc de texte : il était déjà exclu du texte lu.
- **Une réponse apparaît dans le même bloc que sa publication** : 4 blocs de 2 cartes sur 45 entrées (publication
  d'origine, puis réponse d'un autre compte). Companion marque la seconde `reply_hint`, avec `reply_to`.
- Le sujet de l'auteur (« Fitness Threads », « Memes ») est un lien `serp_type=tags` : transmis en `topic`, il compte
  pour les mots-clés et les exclusions comme le texte. Les lieux (`serp_type=location_tag`) ne comptent pas.
- **Aucune publicité ni aucun repost** sur ces lectures : Companion reconnaît les mentions « Sponsored »,
  « Sponsorisé(e) », « Publicité » et « … reposted / a reposté / a republié », sans exemple réel pour l'instant.
- Les suggestions de comptes (« Follow », « 179K followers ») n'ont pas de lien de publication : déjà ignorées.
- Les médias portent une description dans `alt` (« May be an image of… ») : transmise en `media`, pour les lots
  d'Hermès (étape 3).
- Il faut environ 10 écrans pour 25 publications (2 à 4 nouvelles par écran de 960 px).

**Constats avant le code** (14/11/2026) :
- `ThreadsAgent::read()` ouvre `https://www.threads.com/following/` pour `feed.read` : c'est le fil **des abonnements**,
  pas « Pour toi » (`https://www.threads.com/`). X avait le même défaut, corrigé le 13/11.
- Les gestes Threads n'ont pas de reçu `XEngagement` : ce sont des `browser_tasks` `threads.agent.act` avec une
  `idempotency_key`. `threads-action` rend la main sans attendre la preuve (le modèle relance `threads-status`).
- La lecture Companion (`threads/agent.js`) ne donne ni langue, ni publicité, ni réponse, ni repost ; 3 écrans et
  20 publications au plus.
- Companion local n'a, pour Threads, que « Threads — transmettre la session au NAS ». Pour X, la recette locale
  « X — Traiter les publications récentes » (`x/batch.js`) existe, mais suit sa propre logique, pas ce script.

## Pourquoi

L'agent trie le fil « Pour vous » avec le modèle. À chaque passage, il lit environ 9 publications, en choisit une et
fait un seul geste. Or Xavier entraîne lui-même ce fil, en aimant des publications fitness pour Wildy Caro : le tri par
le modèle refait un travail déjà fait, et c'est lui qui coûte cher.

Mesuré sur 24 h au 14/11/2026 (lemonsieurxav) :

| Découverte | Jetons/jour | Actions réelles confirmées | Jetons par action |
|---|---|---|---|
| X | ~10 M | 78 commentaires, 88 likes | ~60 k |
| Threads | ~4 M | ~5 commentaires, ~20 likes (15 commentaires et 60 likes en 3 jours) | ~160 k, ~800 k par commentaire |
| Ensemble | ~14 M sur ~31 M | ~190 | **~73 k** |

Sur Threads, un réveil d'exploration fait environ 10 appels au modèle pour un geste. Parmi eux, environ 3,5 vérifications
de preuve (`threads-status`), parce que `threads-action` n'attend pas le résultat, contrairement à `x-action`.

Un passage de découverte reçoit au plus 10 publications (`--limit 10`, 2 écrans sur X), soit 8,6 en moyenne sur X et
8,1 sur Threads.

## Objectif

**Aimer et commenter beaucoup plus le fil, pour beaucoup moins de jetons par action réelle.**

L'indicateur est le nombre de **jetons par action réelle** : like ou commentaire confirmé après rechargement.
Cible estimée : **4 à 9 k** au lieu de ~73 k, sans que le coût dépende du volume. Les montants totaux viennent après.

## Principe

Le tri devient une règle appliquée par script. Le modèle ne choisit plus les publications : il rédige seulement une
partie des commentaires.

1. **[script, 0 jeton]** Companion lit le fil « Pour vous » sur environ 5 écrans, soit 25 à 30 publications par passage.
   RS-Max accepte déjà `limit` jusqu'à 30.
2. **[script, 0 jeton]** RS-Max écarte ce qui n'est pas utilisable :
   - les publications du compte lui-même ;
   - les réponses et les reposts ;
   - les publications de plus de 14 jours (`CommentFreshness`) ;
   - ce qui est déjà aimé ou commenté (`like_blocked_ids`, `reply_blocked_ids`, reçus `XEngagement`) ;
   - tout ce qui contient un mot exclu.
3. **[script, 0 jeton]** RS-Max retient ce qui correspond à la fiche Community Manager du compte. Le détail des règles
   est plus bas.
4. **[script, 0 jeton]** Chaque publication retenue reçoit un **like**.
5. **[script, 0 jeton]** **40 %** des publications retenues reçoivent un **commentaire spintax**, sans modèle.
6. **[modèle, jetons]** Les autres sont envoyées au modèle **par lots de 5 cibles déjà choisies**, avec leur contexte, en
   un seul appel, sur le modèle de `reply-contexts`. Il rédige les 5 commentaires avec le persona, et une commande
   d'envoi groupé les soumet.
7. **[script, 0 jeton]** Envoi et preuve après rechargement. Le script attend le résultat, y compris sur Threads :
   plus de `threads-status` répété par le modèle.

Les étapes 1 à 5 et 7 tournent dans RS-Max et Companion, sans réveiller Hermès. Hermès ne se réveille que lorsqu'un lot
de l'étape 6 attend.

## Règles du script

- **Champs de la fiche compte** : Xavier réécrit les trois champs actuels pour cette méthode.
  - Recherches spécifiques, hashtags et comptes à suivre servent à **retenir** une publication.
  - « Mots et comptes à exclure » devient une vraie **liste de mots et de comptes interdits**. Un script ne reconnaît
    pas une idée comme « attaques et polémiques ».
- **Correspondance** : insensible à la casse et aux accents, sur des mots entiers. Un auteur listé dans « Comptes à
  suivre » est retenu même sans mot-clé.
- **Exclusions prioritaires** : un mot ou un compte exclu écarte la publication pour toutes les actions.
- **Langue** :
  - X marque chaque publication d'un attribut `lang` sur son bloc de texte (`fr`, `en`… ; un code spécial pour un
    message sans texte).
  - Threads n'a pas de marque connue. On utilise le lien « Traduire » sous le message, complété par une détection
    simple du français (mots courants, accents). **À vérifier sur la page réelle du NAS avant de s'y fier.**
  - Publication en français : modèle spintax en phrase, ou rédaction par le modèle.
  - Autre langue ou sans texte : modèle fait seulement d'emojis.
- **Spintax** :
  - Nouveau champ « Commentaires automatiques » sur la fiche compte, un modèle par ligne. Exemple :
    `{Super|Énorme|Top} {séance|perf} {🔥|💪|👏}`.
  - Tirage avec `random_int()`, conformément à la convention du dépôt.
  - Le script mémorise les textes déjà envoyés par le compte et retire une variante sortie récemment.
  - Il ne commente jamais deux fois le même auteur en 7 jours, ni une publication qui pose une question.
- **Réglages par compte** :
  - un interrupteur, **coupé par défaut** ;
  - le pourcentage spintax, 40 % par défaut ;
  - un plafond quotidien de likes et un plafond quotidien de commentaires automatiques, en plus de la cadence
    existante.

## Champs à ajouter à la fiche Community Manager

Les nouveaux réglages vont dans `agent_strategy`, sous une clé `feed_engagement`. Aucune migration n'est nécessaire,
mais il faut étendre la liste des clés acceptées par `AccountStrategy::validate()`, le formulaire du compte et la vue
compacte de l'API. Un réglage par compte, donc séparément pour X et pour Threads.

| Champ | Contenu | Défaut |
|---|---|---|
| Engagement du fil par script | interrupteur | coupé |
| Commentaires spintax en français | un modèle par ligne, `{a\|b\|c}` | vide (aucun commentaire spintax) |
| Commentaires emojis seuls | un modèle par ligne, pour une autre langue ou sans texte | vide |
| Part des commentaires spintax | pourcentage des publications retenues | 40 % |
| Portée du like | tout ce qui n'est pas exclu, ou seulement ce qui contient un mot-clé | mot-clé (décidé le 14/11) |
| Plafond quotidien de likes par script | nombre | à fixer |
| Plafond quotidien de commentaires spintax | nombre | à fixer |
| Publications lues par passage | nombre, 30 au plus | 25 |

Champs existants **réutilisés** : recherches, hashtags et comptes à suivre pour retenir une publication ; « Mots et
comptes à exclure » comme liste de mots interdits ; `discovery_interval_minutes` pour la fréquence des passages.
Le délai « même auteur » (7 jours) reste une constante.

- **API** : tout se remplit par la route existante `PATCH /api/accounts/{id}/strategy`, rôle manager, la même que
  le formulaire (`AccountStrategy::save()`). Les listes s'envoient en tableaux, le formulaire en lignes. Exemple :

  ```json
  {"agent_strategy": {
    "search_queries": ["musculation", "leg day"],
    "hashtags": ["#fitness"],
    "exclusions": ["onlyfans", "deuil"],
    "feed_engagement": {
      "enabled": false,
      "spin_templates": ["{Belle|Super} {séance|perf} {💪|🔥}"],
      "emoji_templates": ["{💪|🔥}{👏|🙌}{💯|🔥}"],
      "spin_percent": 40,
      "like_scope": "keywords",
      "daily_likes": 150,
      "daily_spin_comments": 20,
      "read_limit": 25
    }
  }}
  ```

  `GET` sur la même route renvoie l'état, avec un tirage d'exemple de chaque modèle spintax et son nombre de
  combinaisons. Un modèle aux accolades mal fermées est refusé en 422.
- **Formulaire** : vérifier que les accolades sont équilibrées, afficher trois tirages d'exemple et le nombre de
  combinaisons possibles.
- **Sécurité** : comme pour la cadence adaptative, le jeton Community Manager d'Hermès (`/api/community-manager/…`)
  peut remplir les textes mais ne peut **ni activer l'interrupteur ni relever un plafond** (403). Un jeton manager le
  peut, par l'API comme par la fiche.
- **À coordonner** : `resources/views/accounts/edit.blade.php` porte des modifications locales non commitées dans le
  dépôt principal.

## Trois installations synchronisées

Companion et les scripts tournent à trois endroits. Chaque livraison les met tous à jour **dans la même intervention** :

| Où | Companion | Scripts et skills Hermès |
|---|---|---|
| Mac (local, Chrome) | dossier `DEV/Chrome/rs-max-companion`, rechargé dans chaque profil Chrome | — |
| NAS (`rsmax-browser`, `rsmax-browser-x`) | `/volume1/docker/rsmax-browser/ext` | — |
| VPS DIX (`rsmax-browser-dix`) | `/opt/rsmax-browser-dix/ext` | profil `rsmax-dix` |
| VPS lemonsieurxav | — | profil `community-manager` |

- Procédure Companion inchangée : réserver la file avec `browser:maintenance`, copier, redémarrer **deux fois**,
  libérer, garder le `config.local.js` de chaque installation. Le correctif est aussi enregistré dans `companion/patches/`.
- Contrôle après livraison : empreinte de chaque fichier comparée entre les trois installations, et scripts ou skills
  Hermès comparés au dépôt.
- **Écarts voulus** au 14/11/2026 : le compte par défaut est écrit en dur dans cinq fichiers (`lemonsieurxav` ou
  `dix` dans `pinterest/batch.js`, `shared/library.js`, `tiktok/batch.js`, `tiktok/discovery.js`), plus
  `shared/seed.json`, propre à chaque marque. À déplacer dans `config.local.js` pour que le code soit identique partout.
- **À construire** : Companion envoie sa version à chaque appel, et RS-Max garde la dernière version vue par
  installation (Mac, NAS, VPS) sur la page `/agent`. Aujourd'hui, la version n'apparaît que dans `extension_errors`, et
  le rechargement des profils Chrome du Mac ne se vérifie pas à distance.

## Exécution manuelle en local

Chaque action doit aussi pouvoir être lancée **à la main depuis Companion sur le Mac**, dans le Chrome de Xavier :
entrées « X — Engager mon fil » et « Threads — Engager mon fil » dans le menu de Companion, comme
« X — Actualiser mes statistiques » aujourd'hui.

- Mêmes routes RS-Max, mêmes règles de sélection, mêmes plafonds et même cadence que le passage automatique :
  le script vit dans RS-Max et Companion, il ne dépend pas de qui le déclenche.
- Les cibles confiées au modèle rejoignent la même file que celles du passage automatique, et Hermès les rédige.
  Le résultat est donc le même quel que soit le déclencheur.
- Journal identique (`bot_action_logs`), avec l'origine du déclenchement : `local` ou `nas`/`vps`.
- Relevé à blanc possible en local : sélection affichée, rien envoyé.
- Cela reste compatible avec la règle « le Mac n'est jamais worker » : le Mac n'exécute que ce que Xavier lance,
  jamais la file automatique.

## Garde-fous conservés

- Fenêtre 06 h–22 h à Lisbonne (`CommunityManagerWindow`).
- Cadence par compte : `AccountActionBudget` et `AdaptiveAccountLimits`, avec réservation durable avant écriture.
- Preuve après rechargement pour chaque geste. Un résultat incertain n'est **jamais renvoyé**.
- Une écriture à la fois par compte, et navigateur libre 5 minutes autour d'une publication planifiée.
- Ne jamais modifier l'empreinte du navigateur du NAS.
- Montée en volume **par paliers**, sur plusieurs jours, en surveillant les refus et les incertitudes. La limite sera
  celle des réseaux, pas celle des jetons. Wildy Caro répond déjà à ~150 messages par jour sur X (75 mentions, 78
  commentaires de découverte).

## Ce qui ne change pas

- Les réponses aux mentions et aux commentaires reçus (messagerie, `/inbox`) gardent leur parcours actuel.
- **La découverte par recherche est coupée depuis le 14/11/2026**, sur X et Threads (action `search.read` désactivée pour Wildy Caro X #7, Threads #36 et Cyril X DIX #23). On reste sur le fil « Pour vous ». Les listes de recherches servent désormais à **retenir** les publications du fil. Les mentions X ne sont pas touchées : leur recherche `to:compte` passe par la tâche `x.tweets`, indépendante de cette action. Conséquence : plus de suggestions d'abonnement issues de la recherche (`xFollowCandidate`), seulement celles des conversations et des comptes à suivre.

## Journalisation et point dans une semaine

- **Chaque action** est écrite dans le journal RS-Max (`bot_action_logs`), avec son origine :
  - `script_like` ;
  - `script_spintax` ;
  - `llm_comment` ;
  - et les lectures du fil (`agent.feed.read`).
- Chaque ligne porte la cible, le résultat (confirmé, incertain, refusé) et le texte envoyé, sans données brutes ni
  secrets, comme `AutomationJournal` aujourd'hui.
- **Purge** : `agent:purge-logs` supprime chaque heure les lignes de plus de 7 jours. C'est inchangé.
- La purge est glissante : le premier jour du test disparaît au huitième. Pour que le point tienne :
  - un **résumé quotidien** des actions par origine et par réseau est conservé, à côté de
    `workspace/cost-history.jsonl` ;
  - les reçus `XEngagement` et Threads restent en base.

**Au point à J+7**, comparer avec la semaine précédente :
- jetons par action réelle (`rsmax_cost_report.py --history`, colonne « Jetons/geste ») ;
- actions réelles par jour, par origine ;
- taux d'incertains et de refus, et toute limitation signalée par un réseau ;
- abonnés, désormais exacts sur X (Companion ≥ 0.18.50) ;
- réponses reçues sur les commentaires spintax comparées aux commentaires rédigés par le modèle.

## Déroulé du test

1. Construire sur X, interrupteur coupé.
2. **Relevé à blanc** sur un passage réel : ce qui aurait été retenu, aimé, commenté en spintax et confié au modèle.
   Rien n'est envoyé. Xavier valide.
3. Activer sur Wildy Caro (X) avec des plafonds bas, puis monter par paliers.
4. Étendre à Threads, après vérification de la détection de langue.
5. Point à J+7, puis décision : garder, ajuster ou revenir en arrière.

**Retour arrière** : couper l'interrupteur du compte. La découverte reprend alors son parcours actuel par le modèle.

## Points ouverts

- La détection de la langue sur Threads est à vérifier.
- La liste des mots exclus et les modèles spintax sont à fournir par Xavier sur la fiche compte, une fois les champs créés.
- Les plafonds de départ sont à fixer.
- Portée du like décidée le 14/11 : seulement les publications qui contiennent un mot-clé, élargie après le relevé à blanc si trop peu sont retenues.
- Les plafonds ne portent que sur l'engagement du fil par script : les réponses aux mentions et la recherche gardent leurs propres limites, et la cadence du compte s'applique à l'ensemble.
- La commande d'envoi groupé et l'attente de la preuve sur Threads sont à construire.

## Annexe : modèles spintax proposés le 14/11/2026

Unisexes : aucun mot ne qualifie l'auteur ni ne décrit une image, que le script ne voit pas. Pas de question, pas de
séduction, pas de vécu inventé. À enregistrer sur la fiche quand les champs existeront.

**Wildy Caro, en français**
```
{Belle|Super|Très belle|Grosse} {séance|perf|progression} {💪|🔥|👏}
{Bravo|Chapeau|Respect} pour {la régularité|le travail|la discipline} {💪|👏|🙌}
{Ça|Voilà qui} {motive|donne envie de s'y mettre|met de l'énergie} {🔥|💪|⚡️}
{La régularité|Le travail|La discipline} {paie|finit toujours par payer} {👏|💪|🙌}
{Top|Joli|Beau} {travail|boulot} {👌|💪|👏}
{Quelle|Belle} {énergie|motivation} {🔥|⚡️|💪}
{Inspirant|Motivant|Ça booste} {👏|🔥|💪}
{Superbe|Très belle|Jolie} {énergie|détermination|régularité} {🔥|💪|✨}
{Énorme|Gros|Beau} {boulot|travail|taf} {💪|🔥|👏}
{Rien ne remplace|Rien ne bat} {la régularité|le travail|la constance} {💪|👏|🙌}
Ça donne envie d'aller {à la salle|s'entraîner|soulever} {🔥|💪|😄}
{Propre|Solide|Impeccable}, {bravo|respect|chapeau} {👏|💪|🔥}
{Quelle|Belle} {régularité|constance|détermination} {👏|💪|🙌}
{Motivation|Énergie|Discipline} au top {🔥|💪|⚡️}
Vraiment {inspirant|motivant|stimulant} {👏|🔥|💪}
Merci pour {l'inspiration|la motivation|le partage} {🙏|💪|🔥}
{Bien joué|Bien vu|Joli} {💪|👏|🔥}{|🔥|💯}
Du {sérieux|solide|très propre} {💪|🔥|👏}
J'adore {l'énergie|la motivation|la détermination} {🔥|💪|⚡️}
{C'est|Voilà} ce qu'on appelle {de la régularité|du travail|de la détermination} {💪|👏|🔥}
```

**Wildy Caro, emojis seuls**
```
{💪|🔥|👏}{🔥|💪|🙌}{⚡️|💯|🔥}
{👏|🙌}{💪|🔥}{💯|👊}
{🔥|💯}{💪|👊}{👏|🙌}
{💪|🏋️|🔥}{💯|👏}{🔥|⚡️|💪}
{🙌|👏|💪}{🔥|💯}
{⚡️|🔥}{💪|🙌}{👊|💯|👏}
{💪🔥|🔥💪|💪💪}{👏|🙌}
```

**Cyril (DIX), en français** : ton de pro, sobre, sans mention de DIX. « Bon rappel » et « Point important »
seulement sous une publication avec du texte.
```
{Bonne|Belle|Très bonne} {approche|démarche|progression} {👍|💪|👏}
{La régularité|La constance|La rigueur} {fait la différence|reste la clé|paie toujours} {💪|👍|👌}
{Travail sérieux|Démarche sérieuse|Approche solide}, {bravo|bien joué|respect} {👏|💪|👍}
{Bon rappel|Rappel utile|Point important} {👍|👌|💪}
{Les bases|Les fondamentaux} {avant tout|d'abord|toujours} {💪|👍|👌}
{Progression|Discipline|Rigueur} {exemplaire|solide|au rendez-vous} {👏|💪|👍}
{Continue comme ça|Garde ce rythme|Belle dynamique} {💪|👊|👏}
{Bien vu|Juste|Très juste} {👍|👌|💯}
{Récupération|Nutrition|Sommeil} et {régularité|constance|rigueur} : {la base|le combo gagnant|l'essentiel} {💪|👍|👌}
{Sérieux et efficace|Simple et efficace|Méthodique}, {bravo|respect|bien joué} {👏|👍|💪}
```

**Cyril (DIX), emojis seuls**
```
{👍|💪|👏}{💯|👌}
{💪|👊}{👏|👍}
{👌|💯}{💪|👍}
```
