# Profils d’exécution Companion

RS-Max envoie chaque tâche navigateur dans la file du `browser_profile` associé au compte. Companion relève uniquement la file indiquée par son `taskProfile`. Chaque file traite une tâche à la fois. Deux profils peuvent travailler en parallèle s’ils ont chacun leur Chromium et leur dossier de données.

| Parcours | Où il s’exécute | Comment vérifier |
| --- | --- | --- |
| Hermès ou publication RS-Max avec un compte en mode navigateur | Profil Companion configuré sur le NAS ou le VPS | Identité, version, profil et résultat dans le journal de la tâche |
| Companion local | Chrome ouvert sur le bon compte, jeton de son instance RS-Max | Identité et instance dans Companion, preuve et réservation dans RS-Max |

Sur un NAS qui héberge X et TikTok, attribuer un profil dédié aux comptes X à isoler. Une vérification de publication TikTok peut garder le navigateur occupé plusieurs minutes. Séparer les files permet de traiter X pendant ce contrôle. Conserver le profil TikTok existant, son stockage et le service natif associé.

## Ajouter ou déplacer un profil

1. Démarrer le nouveau conteneur avec la même image et les mêmes sources d’extension, un dossier de données indépendant et un `config.local.js` réservé au conteneur. Le réglage `taskProfile` doit être unique dans l’instance. Le port de débogage et le service natif restent en loopback dans le conteneur. Ne pas publier de port pour le nouveau conteneur s’il n’a pas d’interface distante à servir.
2. Initialiser le service natif dans le nouveau conteneur si le profil utilise les lectures publiques de secours. Garder son fichier d’authentification dans le dossier de données. Ne pas partager le dossier de données entre navigateurs.
3. Pour X, faire prendre au nouveau profil une tâche d’identité puis une lecture sans écriture. Companion restaure la session chiffrée via le parcours existant et vérifie l’identifiant natif. Contrôler le compte, le fil, le profil et la version dans RS-Max.
4. Attendre la fin des tâches déjà demandées pour le compte avant de changer son `browser_profile`. Lancer ensuite une lecture depuis la route normale de l’agent. Pour vérifier l’isolation, comparer les horodatages d’une tâche X et d’une tâche TikTok réellement prises par deux profils distincts.
5. Pour revenir en arrière, remettre l’ancien profil une fois les tâches du compte terminées et revérifier la session. Une écriture incertaine conserve son reçu et son blocage anti-doublon.

Si deux conteneurs montent le même dossier de code Companion en lecture seule, chacun garde sa configuration locale et son stockage. Après une mise à jour, redémarrer et vérifier les deux conteneurs. Confirmer aussi la version sur le VPS et dans Chrome local quand ils sont concernés.

## Redémarrer un navigateur distant

1. Relever les profils des conteneurs concernés et leurs publications proches. Choisir un créneau qui laisse le temps de terminer le redémarrage.
2. Depuis le conteneur applicatif RS-Max, lancer `php artisan browser:maintenance PROFIL --lease=90 --wait=30` avec l’entrée standard ouverte. Passer plusieurs profils en arguments si plusieurs navigateurs doivent être arrêtés ensemble. La commande attend une file vide et réserve les prises de tâche sur les seuls profils demandés.
3. Attendre le message `PRÊT`, puis redémarrer et contrôler Chromium dans un autre terminal pendant la durée indiquée. Un refus pour profil occupé signifie que la file continue à travailler. Revenir quand les tâches ont abouti.
4. Appuyer sur Entrée dès que Chromium est prêt. Les tâches arrivées entre-temps reprennent dans la même file. Le verrou expire automatiquement si la connexion disparaît. Vérifier le profil et la version avec une lecture dans le journal.

Pour l’agent, les comptes, le canal, le persona, la langue et les limites viennent de `accounts` et `strategy`. Le serveur choisit le profil, l’agent utilise le parcours `x-read` puis `x-context` et vérifie le reçu de toute écriture. Les consignes sont dans le [skill Community Manager](../hermes/skills/community-manager-x/SKILL.md).
