# Engagement du fil « Pour vous » par script — stratégie à tester

Décidée le 14/11/2026 avec Xavier. Statut : **à construire, puis à tester une semaine**. Réseaux : X et Threads.
Suivi des coûts : `docs/hermes-couts-agent.md`.

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
- La recherche par requêtes reste disponible. On décidera après le test si elle passe aussi par le script.

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
- La liste des mots exclus et les modèles spintax sont à fournir par Xavier sur la fiche compte.
- Les plafonds de départ sont à fixer.
- Faut-il retenir tout ce qui n'est pas exclu pour le like, et réserver les mots-clés aux commentaires ?
- La commande d'envoi groupé et l'attente de la preuve sur Threads sont à construire.
