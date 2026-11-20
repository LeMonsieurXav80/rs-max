# Profils Facebook et Instagram par Companion

Première étape : connecter une session personnelle à RS-Max, la transmettre depuis le Chrome local et vérifier son identité sur le VPS ou le NAS. Les comptes API existants gardent leur parcours.

Dans Ajouter un compte, sélectionner Facebook ou Instagram puis Companion sur le VPS/NAS. Renseigner l’identifiant numérique, le profil du worker et le nom Instagram pour Instagram. Companion propose « Identifier mon compte » dans le navigateur connecté pour relever ces informations publiques. Choisir ensuite le persona dans la fiche du compte.

Dans le même Chrome, lancer « Facebook — transmettre la session à RS-Max » ou « Instagram — transmettre la session à RS-Max ». Les cookies restent dans le service worker et dans `browser_session`, chiffré en base. Le serveur ne les place pas dans le payload de la tâche ni dans les journaux. Seul le worker administrateur prenant la tâche peut demander la session du compte associé.

La session n’est connectée qu’après vérification sur le navigateur distant. Pour Facebook, le profil actif (`i_user`, sinon `c_user`) doit aussi correspondre à l’acteur de la page. Pour Instagram, l’identifiant de la session et le nom du profil dans la navigation doivent correspondre au compte attendu. Une autre identité, une connexion à confirmer ou un contrôle Facebook/Instagram font échouer la vérification ; aucune action sociale n’est exécutée.

Une tâche à la fois par worker. Les sessions X, Facebook et Instagram concernent des domaines différents. La restauration Meta ne touche pas aux cookies X. Le Chrome local ne change jamais automatiquement de compte et ne relève pas la file du VPS.

## Parité locale et distante

Chaque nouveau parcours doit être utilisable depuis Companion sur le Mac et depuis le worker distant, avec les mêmes identités, réservations, quotas et preuves. Les premières actions ajoutées sont l’identification locale et la transmission de session, avec vérification distante. La lecture du fil, les likes, commentaires, réponses inbox et publications Meta personnelles restent à implémenter et à vérifier sur les deux exécutants ; aucune de ces actions n’est annoncée prête par ce lot. Un compte Meta en mode navigateur ne retombe pas sur les API Pages/Instagram.

API authentifiée, compte actif attribué à l’utilisateur :

- `GET /api/extension/meta/{facebook|instagram}/session?platform_account_id=ID`
- `POST /api/extension/meta/{facebook|instagram}/session` avec `platform_account_id` et cookies de ce seul réseau.

La transmission demande une extension actualisée et rechargée. Conserver `config.local.js` propre à chaque installation. Réserver les files libres avant les deux redémarrages distants et vérifier la version active. Le Mac reste sous le contrôle de Xavier pour le rechargement de l’extension.
