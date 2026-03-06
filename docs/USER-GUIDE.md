# RS-Max — Guide complet

> Plateforme de gestion et d'automatisation des reseaux sociaux.

---

## Table des matieres

1. [Installation et deploiement](#installation-et-deploiement)
2. [Variables d'environnement](#variables-denvironnement)
3. [Roles et permissions](#roles-et-permissions)
4. [Comptes sociaux](#comptes-sociaux)
5. [Publications (Posts)](#publications-posts)
6. [Fils de discussion (Threads)](#fils-de-discussion-threads)
7. [Assistance IA](#assistance-ia)
8. [Bot d'automatisation](#bot-dautomatisation)
9. [Prospection Bluesky](#prospection-bluesky)
10. [Boite de reception (Inbox)](#boite-de-reception-inbox)
11. [Sources de contenu](#sources-de-contenu)
12. [Personas](#personas)
13. [Hooks (accroches)](#hooks-accroches)
14. [Bibliotheque de medias](#bibliotheque-de-medias)
15. [Statistiques](#statistiques)
16. [Parametres](#parametres)
17. [Gestion des utilisateurs](#gestion-des-utilisateurs)
18. [Systeme de mise a jour](#systeme-de-mise-a-jour)
19. [Endpoint de sante](#endpoint-de-sante)
20. [Commandes Artisan](#commandes-artisan)
21. [Architecture technique](#architecture-technique)

---

## Installation et deploiement

L'application est conçue pour tourner dans un conteneur **Docker**. Le conteneur embarque tout ce qui est necessaire :

- **nginx** — serveur web
- **php-fpm** — traitement PHP
- **queue worker** — traitement des jobs en arriere-plan (publication, imports, sync)
- **scheduler** — execution des taches planifiees (cron)
- **FFmpeg** — compression video

Le fichier `docker/supervisord.conf` orchestre ces processus. L'arret gracieux est configure (`stopasgroup`, `killasgroup`, `stopwaitsecs=30`).

### Option 1 : Image pre-construite (Synology, VPS, serveur local)

C'est la methode la plus simple. Aucun build n'est necessaire — l'image Docker est publiee automatiquement sur GitHub Container Registry (GHCR).

**1. Recuperer les fichiers de deploiement**

Telecharger `docker-compose.prod.yml` et `.env.example` depuis le depot, ou les creer manuellement :

```bash
curl -O https://raw.githubusercontent.com/lemonsieurxav80/rs-max/main/docker-compose.prod.yml
curl -O https://raw.githubusercontent.com/lemonsieurxav80/rs-max/main/.env.example
cp .env.example .env
```

> Si le depot est prive, authentifiez-vous d'abord aupres de GHCR :
> ```bash
> echo VOTRE_TOKEN_GITHUB | docker login ghcr.io -u VOTRE_USERNAME --password-stdin
> ```

**2. Configurer le fichier `.env`**

```env
# URL publique de l'application (adapter a votre domaine ou IP)
APP_URL=https://rs-max.example.com

# Cle de chiffrement (sera generee automatiquement au premier demarrage si absente)
# APP_KEY=

# Base de donnees (ces valeurs sont utilisees par MySQL ET par l'app)
DB_DATABASE=rs_max
DB_USERNAME=rs_max
DB_PASSWORD=un-mot-de-passe-solide
DB_ROOT_PASSWORD=un-autre-mot-de-passe-solide

# Locale et fuseau horaire
APP_LOCALE=fr
APP_TIMEZONE=Europe/Paris

# Plateformes (remplir celles dont vous avez besoin)
# FACEBOOK_APP_ID=
# FACEBOOK_APP_SECRET=
# FACEBOOK_CONFIG_ID=
# THREADS_APP_ID=
# THREADS_APP_SECRET=
# YOUTUBE_CLIENT_ID=
# YOUTUBE_CLIENT_SECRET=

# IA (optionnel, configurable aussi depuis l'interface)
# OPENAI_API_KEY=

# Inscription publique (false = seul l'admin peut creer des comptes)
REGISTRATION_ENABLED=false
```

**3. Lancer**

```bash
docker compose -f docker-compose.prod.yml up -d
```

L'application demarre, attend que MySQL soit pret, lance les migrations et le seeder automatiquement.

**4. Creer le compte administrateur**

```bash
docker compose -f docker-compose.prod.yml exec app php artisan make:admin
```

Suivez les instructions (nom, email, mot de passe).

**5. Acceder a l'application**

Ouvrir `http://<ip-du-serveur>` (port 80). Pour du HTTPS, placez un reverse proxy devant (voir plus bas).

### Option 2 : Build depuis les sources (git clone)

Si vous preferez construire l'image vous-meme :

```bash
git clone <url-du-depot> rs-max
cd rs-max
cp .env.example .env
# Editer .env avec vos valeurs
docker compose up -d
```

Cette methode utilise `docker-compose.yml` qui compile l'image localement. Utile pour le developpement ou si vous voulez personnaliser le Dockerfile.

### Option 3 : Coolify

Dans Coolify, selectionnez **Docker Compose** comme build pack et pointez vers le depot Git.

Les variables d'environnement se configurent dans l'interface Coolify. Coolify gere automatiquement le HTTPS via Traefik.

Pour activer les mises a jour depuis l'interface RS-Max, ajoutez :

```env
DEPLOY_API_URL=https://votre-plateforme.com
DEPLOY_API_TOKEN=votre-token-api
DEPLOY_APP_UUID=identifiant-de-lapp
```

### Reverse proxy (HTTPS)

Si vous n'utilisez pas Coolify, vous pouvez placer un reverse proxy devant le conteneur pour le HTTPS. Exemples :

**Nginx Proxy Manager** (interface graphique, ideal pour Synology) :
- Pointez vers `http://localhost:80` (ou l'IP du conteneur)
- Activez le certificat SSL Let's Encrypt

**Traefik** (ajouter les labels au service `app` dans `docker-compose.yml`) :

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.rsmax.rule=Host(`rs-max.example.com`)"
  - "traefik.http.routers.rsmax.tls.certresolver=letsencrypt"
```

**Caddy** (Caddyfile) :

```
rs-max.example.com {
    reverse_proxy localhost:80
}
```

### Volumes et persistance

Le `docker-compose.yml` definit deux volumes persistants :

| Volume | Contenu | Importance |
|---|---|---|
| `mysql_data` | Base de donnees MySQL | Toutes les donnees de l'application |
| `media_data` | Fichiers medias uploades (images, videos) | Bibliotheque de medias |

> **Important** : Pensez a sauvegarder ces deux volumes regulierement.

### Sauvegarde

```bash
# Sauvegarder la base de donnees
docker compose exec database mysqldump -u root -p rs_max > backup.sql

# Sauvegarder les medias
docker compose cp app:/var/www/html/storage/app/private/media ./media-backup
```

### Mise a jour

**Avec l'image pre-construite (Option 1)** :

```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

**Avec le build depuis les sources (Option 2)** :

```bash
cd rs-max
git pull
docker compose up -d --build
```

Le conteneur relancera automatiquement les migrations et optimisera les caches.

### Cle APP_KEY

> **Important** : La cle `APP_KEY` est utilisee pour chiffrer les credentials des comptes sociaux (tokens, mots de passe API). Elle est generee automatiquement au premier demarrage si absente. **Ne la changez jamais apres le premier deploiement**, sous peine de perdre l'acces a tous les comptes connectes. Notez-la et conservez-la en lieu sur.

### Sur Synology (NAS)

1. Installer le paquet **Container Manager** (anciennement Docker) depuis le Centre de paquets
2. Copier `docker-compose.prod.yml` et `.env` sur le NAS (via File Station ou SSH)
3. Ouvrir Container Manager > **Projet** > **Creer**
4. Selectionner le dossier contenant les fichiers, il detectera le `docker-compose.prod.yml`
5. Configurer les variables d'environnement dans l'interface
6. Lancer le projet
7. Creer l'admin via SSH : `docker exec <nom-conteneur-app> php artisan make:admin`

> Si le depot est prive, connectez-vous a GHCR en SSH avant de lancer le projet :
> ```bash
> echo VOTRE_TOKEN | docker login ghcr.io -u VOTRE_USERNAME --password-stdin
> ```

Pour le HTTPS sur Synology, utilisez le **Reverse Proxy** integre (Panneau de configuration > Portail de connexion > Avance > Reverse Proxy) ou installez Nginx Proxy Manager.

---

## Variables d'environnement

### Application

| Variable | Description | Defaut |
|---|---|---|
| `APP_NAME` | Nom affiche dans l'interface | `RS-Max` |
| `APP_ENV` | Environnement (`local`, `production`) | `local` |
| `APP_KEY` | Cle de chiffrement (generee automatiquement) | — |
| `APP_DEBUG` | Mode debug (desactiver en production) | `true` |
| `APP_URL` | URL publique de l'application | `http://localhost` |
| `APP_LOCALE` | Langue de l'interface | `fr` |
| `APP_TIMEZONE` | Fuseau horaire | `Europe/Paris` |

### Base de donnees

| Variable | Description | Defaut |
|---|---|---|
| `DB_CONNECTION` | Pilote de base de donnees (`sqlite`, `mysql`, `pgsql`) | `sqlite` |
| `DB_HOST` | Hote de la base de donnees | `127.0.0.1` |
| `DB_PORT` | Port | `3306` |
| `DB_DATABASE` | Nom de la base | `rs_max` |
| `DB_USERNAME` | Utilisateur | — |
| `DB_PASSWORD` | Mot de passe | — |

En mode SQLite (par defaut), aucune configuration supplementaire n'est necessaire. Le fichier `database.sqlite` est cree automatiquement.

### Plateformes sociales

| Variable | Description | Requis pour |
|---|---|---|
| `FACEBOOK_APP_ID` | ID de l'application Meta | Facebook, Instagram |
| `FACEBOOK_APP_SECRET` | Secret de l'application Meta | Facebook, Instagram |
| `FACEBOOK_CONFIG_ID` | Config ID Facebook Login for Business | Facebook, Instagram |
| `THREADS_APP_ID` | ID de l'application Threads | Threads |
| `THREADS_APP_SECRET` | Secret de l'application Threads | Threads |
| `YOUTUBE_CLIENT_ID` | Client ID Google OAuth | YouTube |
| `YOUTUBE_CLIENT_SECRET` | Client Secret Google OAuth | YouTube |

**Plateformes sans variables d'environnement** :

- **Bluesky** — Les credentials (handle + app password) sont stockes par compte dans l'interface
- **Twitter/X** — Les cles API sont stockees par compte dans l'interface
- **Telegram** — Le token du bot est stocke dans l'interface
- **Reddit** — Les credentials (client_id, client_secret, username, password) sont stockes par compte

### Intelligence artificielle

| Variable | Description |
|---|---|
| `OPENAI_API_KEY` | Cle API OpenAI (peut aussi etre configuree dans Parametres) |

La cle OpenAI est stockee de maniere chiffree dans la base de donnees. Si elle est definie a la fois dans `.env` et dans l'interface, la valeur de l'interface prevaut.

### Inscription

| Variable | Description | Defaut |
|---|---|---|
| `REGISTRATION_ENABLED` | Autoriser l'inscription publique | `false` |

Par defaut, l'inscription est desactivee. Seul un administrateur peut creer des comptes utilisateur via l'interface ou la commande `php artisan make:admin`.

### Verification des mises a jour

| Variable | Description | Defaut |
|---|---|---|
| `DEPLOY_GIT_REPO` | URL du depot Git de reference | — |
| `DEPLOY_GIT_BRANCH` | Branche a surveiller | `main` |

Sans `DEPLOY_GIT_REPO`, la verification automatique des mises a jour est desactivee.

### Deploiement automatique (optionnel)

| Variable | Description |
|---|---|
| `DEPLOY_API_URL` | URL de l'API de votre plateforme (Coolify, webhook, etc.) |
| `DEPLOY_API_TOKEN` | Token d'authentification |
| `DEPLOY_APP_UUID` | Identifiant de l'application sur la plateforme |

Ces variables sont optionnelles. Sans elles, le deploiement automatique depuis l'interface est desactive.

### Autres

| Variable | Description | Defaut |
|---|---|---|
| `QUEUE_CONNECTION` | Pilote de file d'attente | `database` |
| `CACHE_STORE` | Pilote de cache | `database` |
| `SESSION_DRIVER` | Pilote de session | `database` |
| `MAIL_MAILER` | Pilote d'envoi d'emails | `log` |
| `BCRYPT_ROUNDS` | Nombre de tours de hachage | `12` |

---

## Roles et permissions

L'application utilise 3 niveaux de roles hierarchiques :

| Role | Niveau | Acces |
|---|---|---|
| **Utilisateur** | 0 | Publications, medias, inbox (ses comptes uniquement), statistiques |
| **Gestionnaire** (manager) | 1 | Tout ce que l'utilisateur peut faire + parametres, bot, sources de contenu, personas, hooks, import historique |
| **Administrateur** (admin) | 2 | Tout + gestion des utilisateurs, creation/suppression de comptes sociaux, systeme de mise a jour |

Les roles sont hierarchiques : un administrateur a automatiquement toutes les permissions d'un gestionnaire et d'un utilisateur.

---

## Comptes sociaux

### Plateformes supportees

| Plateforme | Methode de connexion | Fonctionnalites |
|---|---|---|
| **Facebook** | OAuth (Facebook Login for Business) | Publication, inbox, statistiques, bot (likes commentaires) |
| **Instagram** | OAuth (via app Meta) | Publication, inbox, statistiques |
| **Threads** | OAuth (app Threads dediee) | Publication, inbox |
| **Twitter/X** | Cles API manuelles | Publication, inbox |
| **Bluesky** | Handle + App Password | Publication, inbox, bot (auto-like, prospection) |
| **YouTube** | OAuth Google | Publication (Community posts), inbox (commentaires), statistiques |
| **Telegram** | Token de bot + Chat ID | Publication, inbox |
| **Reddit** | Credentials manuels | Publication, inbox |

### Connexion des comptes

Chaque plateforme a sa propre page de configuration accessible depuis le menu **Comptes** :

- **Facebook/Instagram** : Cliquer sur "Connecter avec Facebook" lance le flux OAuth. Les pages et comptes Instagram lies au portfolio business de l'app sont importes
- **Threads** : Flux OAuth dedie avec l'app Threads
- **YouTube** : Flux OAuth Google. Selectionnez la chaine a connecter
- **Bluesky** : Saisir le handle et un "App Password" (genere depuis les parametres Bluesky)
- **Twitter/X** : Saisir les 4 cles API (API Key, API Secret, Access Token, Access Token Secret)
- **Telegram** : 1) Enregistrer le token du bot, 2) Ajouter des channels/groupes via leur Chat ID
- **Reddit** : 1) Enregistrer les credentials de l'app Reddit, 2) Ajouter des subreddits

### Comptes actifs/inactifs

Chaque compte peut etre active ou desactive via le toggle sur la page des comptes. Un compte inactif n'apparait pas dans les formulaires de publication.

### Credentials chiffres

Tous les identifiants de connexion (tokens, mots de passe, cles API) sont chiffres dans la base de donnees avec la cle `APP_KEY` de l'application (cast `encrypted:array`). Ils ne sont jamais affiches en clair dans l'interface.

---

## Publications (Posts)

### Creer une publication

1. Aller dans **Publier** > **Nouveau post**
2. Rediger le contenu texte
3. Optionnellement, ajouter des medias (images, videos) depuis la bibliotheque
4. Selectionner les comptes cibles (les comptes par defaut sont pre-selectionnes)
5. Choisir : **Publier maintenant**, **Planifier** (date + heure), ou **Brouillon**

### Comptes par defaut

Un utilisateur peut definir ses comptes par defaut depuis le formulaire de creation. Ces comptes seront pre-coches a chaque nouveau post.

### Statuts d'une publication

| Statut | Description |
|---|---|
| `draft` | Brouillon, non publie |
| `scheduled` | Planifie pour publication automatique a une date/heure |
| `publishing` | En cours de publication (traitement queue) |
| `published` | Publie avec succes |
| `failed` | Echec de publication (erreur API, token expire, etc.) |

### Publication multi-plateforme

Chaque post peut cibler plusieurs comptes/plateformes. Le contenu est adapte automatiquement :
- Les limites de caracteres par plateforme sont respectees (configurables dans les Parametres)
- Les medias sont optimises selon les contraintes de chaque plateforme

### Actions disponibles

- **Publier** : Publier sur tous les comptes selectionnes
- **Publier un** : Publier sur un seul compte specifique
- **Reinitialiser** : Remettre un compte en statut "a publier" apres un echec
- **Sync stats** : Recuperer les statistiques de la publication depuis les APIs

---

## Fils de discussion (Threads)

Les fils de discussion permettent de publier des contenus longs en plusieurs segments (comme les threads Twitter).

### Plateformes segmentees vs compilees

| Type | Plateformes | Comportement |
|---|---|---|
| **Segmente** | Twitter, Threads, Bluesky | Chaque segment est publie comme un post dans un fil |
| **Compile** | Facebook, Telegram | Tous les segments sont concatenes en un seul post |

### Creer un fil

1. **Manuellement** : Ajouter des segments un par un
2. **Depuis une URL** : Coller une URL d'article et l'IA genere automatiquement un fil en plusieurs segments
3. **Depuis les sources** : Parcourir les articles RSS/WordPress/YouTube/Reddit importes

### Actions

- **Regenerer un segment** : Demander a l'IA de reecrire un segment specifique
- **Publier** : Publier le fil sur tous les comptes selectionnes ou un compte specifique
- **Reinitialiser** : Remettre a zero le statut de publication pour un compte

---

## Assistance IA

L'IA (OpenAI) est integree a plusieurs niveaux :

### Generation de texte

- **Post unique** : Generer ou reformuler le contenu d'un post a partir d'une instruction
- **Multi-plateforme** : Adapter automatiquement un texte pour chaque plateforme selectionnee (respect des limites de caracteres, ton adapte)
- **Depuis un media** : Analyser une image ou video et generer un texte descriptif (modele vision)

### Modeles IA configurables

Chaque usage a son propre modele configurable dans les Parametres :

| Usage | Cle de configuration | Defaut |
|---|---|---|
| Generation de texte | `ai_model_text` | `gpt-4o-mini` |
| Analyse d'images | `ai_model_vision` | `gpt-4o` |
| Traduction | `ai_model_translation` | `gpt-4o-mini` |
| Generation depuis sources | `ai_model_rss` | `gpt-4o-mini` |
| Reponses inbox | `ai_model_inbox` | `gpt-4o-mini` |

### Personas et IA

Quand une Persona est associee a un compte social, son `system_prompt`, son ton et sa langue sont injectes dans les prompts IA pour personnaliser les reponses.

### Contenu ancien

Pour les articles/videos de plus de 6 mois, l'IA ajoute automatiquement une formulation contextuelle (parmi 20 templates) pour signaler que le contenu date un peu, tout en restant naturel.

---

## Bot d'automatisation

Le bot permet d'automatiser des interactions sur les reseaux sociaux. Accessible aux **gestionnaires** (manager+).

### Bot Bluesky — Auto-like

#### Fonctionnement

1. **Termes de recherche** : Vous definissez des mots-cles (ex: "Laravel", "web dev")
2. Le bot recherche les posts correspondants sur Bluesky via l'API de recherche publique
3. Il like automatiquement les posts trouves (en evitant ses propres posts)
4. Si active, il like aussi les **reponses** aux posts trouves
5. **Like-back** : Le bot identifie les utilisateurs qui ont aime vos propres posts, puis like 3 de leurs posts recents (max 10 utilisateurs par run)

#### Parametres par terme

| Parametre | Description | Defaut |
|---|---|---|
| `term` | Mot-cle de recherche | — |
| `max_likes_per_run` | Nombre max de posts a liker par execution | 10 |
| `like_replies` | Liker aussi les reponses | Oui |

#### Delais et securite

- **500 ms** entre chaque like de post
- **300 ms** entre chaque like de reponse
- **500 ms** entre les utilisateurs pour le like-back
- Le bot evite de liker deux fois le meme contenu (verification via les logs)

### Bot Facebook — Likes de commentaires

#### Fonctionnement

1. Le bot recupere le feed de votre page Facebook (25 derniers posts)
2. Pour chaque post, il recupere les commentaires (50 derniers)
3. Il like automatiquement les commentaires des visiteurs (pas ceux de la page elle-meme)
4. Maximum **50 likes par execution**
5. **300 ms** entre chaque like

#### Pre-requis

La page Facebook doit avoir la permission `pages_manage_engagement` activee.

### Frequence d'execution

La frequence du bot est configurable **par compte** :

| Valeur | Intervalle |
|---|---|
| `disabled` | Desactive |
| `every_15_min` | Toutes les 15 minutes |
| `every_30_min` | Toutes les 30 minutes (defaut) |
| `hourly` | Toutes les heures |
| `every_2_hours` | Toutes les 2 heures |
| `every_6_hours` | Toutes les 6 heures |
| `every_12_hours` | Toutes les 12 heures |
| `daily` | Une fois par jour |

### Controles

- **Demarrer** : Active le bot pour un compte et lance une execution immediate
- **Arreter** : Desactive le bot et envoie un signal d'arret au run en cours
- **Historique** : Consultez les 100 dernieres actions (likes, follows) avec details
- **Vider l'historique** : Supprime tous les logs d'actions du bot

### Verification API Bluesky

Depuis la page du bot, vous pouvez tester le statut de l'API pour chaque compte Bluesky :
- **Auth** : Verification de l'authentification (refresh token, login)
- **API publique** : Test de l'API publique (profil)
- **PDS** : Test de l'API authentifiee
- **Rate limiting** : Detection du rate limiting (429)

---

## Prospection Bluesky

La prospection est une strategie d'engagement avancee, separee du bot classique.

### Concept

L'idee est de cibler les **likers d'un compte influent** dans votre niche :

1. Vous ajoutez un **compte cible** (handle Bluesky d'un influenceur)
2. Le bot analyse les **3 derniers posts** de ce compte
3. Pour chaque post, il parcourt la liste des **likers** (pagination complete)
4. Pour chaque liker, il :
   - Like **3 a 5 de ses posts recents** (nombre aleatoire)
   - **Follow 1 liker sur 5** (ratio configurable)

### Delais

Le delai entre chaque action est de **~2.4 secondes**, calibre pour etaler les actions sur environ 1 heure sans declencher de rate limiting.

### Reprise et progression

- La prospection **sauvegarde sa progression** (post en cours, cursor de pagination, compteurs)
- Si elle est arretee ou mise en pause, elle reprend la ou elle s'etait arretee
- Le statut passe par : `pending` → `running` → `paused` ou `completed`

### Actions disponibles

| Action | Description |
|---|---|
| **Lancer** | Demarre ou reprend la prospection |
| **Arreter** | Met en pause la prospection en cours |
| **Reinitialiser** | Remet tous les compteurs a zero et le statut a `pending` |
| **Supprimer** | Supprime le compte cible |

### Logs

Toutes les actions de prospection sont tracees dans les logs du bot avec les types :
- `prospect_like` — Like du post d'un liker
- `prospect_follow` — Follow d'un liker

---

## Boite de reception (Inbox)

L'inbox centralise les messages et commentaires de toutes les plateformes connectees.

### Synchronisation

La synchronisation est **automatique** selon la frequence configuree par plateforme (voir Parametres). Une synchronisation manuelle est aussi disponible (bouton "Sync" — gestionnaire+).

### Types de messages

| Type | Description |
|---|---|
| `comment` | Commentaire sur un post |
| `reply` | Reponse a un commentaire |
| `message` | Message prive (DM) |
| `mention` | Mention de votre compte |

### Filtres disponibles

- **Statut** : Nouveau, Lu, Archive, Repondu
- **Type** : Nouveau (messages parents uniquement), Relance (follow-ups uniquement)
- **Plateforme** : Filtrer par reseau social
- **Compte** : Filtrer par compte specifique

### Reponses

- **Reponse manuelle** : Rediger et envoyer directement une reponse
- **Suggestion IA** : L'IA genere une proposition de reponse basee sur le contexte et la Persona associee
- **Reponse IA en masse** : Generer des suggestions IA pour tous les messages non lus selectionnes
- **Envoi en masse** : Envoyer toutes les reponses generees d'un coup

### Prompt de reponse IA

Le comportement de l'IA pour les reponses est entierement configurable dans **Parametres > Messagerie**. Le prompt par defaut adapte automatiquement :
- La longueur de la reponse au message recu (emoji → emoji, question → 1-2 phrases)
- Le ton a la personnalite definie dans la Persona
- Pas de hashtags, pas de formules generiques

### Activation par plateforme

Chaque plateforme peut etre activee ou desactivee pour l'inbox, avec sa propre frequence de synchronisation (voir section Parametres).

---

## Sources de contenu

Les sources permettent d'importer du contenu externe pour le transformer en publications via l'IA. Accessible aux **gestionnaires** (manager+).

### Types de sources

| Type | Description |
|---|---|
| **RSS** | Flux RSS/Atom de n'importe quel site |
| **WordPress** | Articles d'un site WordPress via l'API REST |
| **YouTube** | Videos d'une chaine YouTube (hors Shorts < 60s) |
| **Reddit** | Posts d'un subreddit |

### Workflow commun

Chaque type de source suit le meme workflow en 4 etapes :

1. **Ajouter la source** : Configurer l'URL/identifiant et les comptes cibles
2. **Recuperer** (Fetch) : Importer les derniers articles/videos/posts
3. **Generer** : L'IA transforme les contenus importes en publications adaptees a chaque plateforme
4. **Previsualiser et confirmer** : Revoir chaque publication generee, regenerer si besoin, puis confirmer pour creer les posts

### Configuration par source

- **Comptes cibles** : Choix des comptes sociaux ou publier
- **Persona** : Persona IA a utiliser pour la generation
- **Hook** : Categorie de hook pour ajouter une accroche
- **Recuperation automatique** : Frequence de fetch automatique

### YouTube

La source YouTube utilise les credentials OAuth du compte YouTube connecte (pas de cle API separee). Les videos Shorts (< 60 secondes) sont automatiquement filtrees.

---

## Personas

Les personas definissent la personnalite de l'IA lors de la generation de contenu. Accessible aux **gestionnaires** (manager+).

### Champs

| Champ | Description |
|---|---|
| `name` | Nom de la persona (ex: "Community Manager Tech") |
| `description` | Description interne pour reference |
| `system_prompt` | Instructions systeme envoyees a l'IA avant chaque generation |
| `tone` | Ton de la voix (ex: "professionnel", "decontracte", "enthousiaste") |
| `language` | Langue principale des contenus generes |
| `is_active` | Active/inactive |

### Utilisation

Les personas sont utilisees dans :
- La generation de posts (AI Assist)
- La generation depuis les sources de contenu
- Les suggestions de reponse dans l'inbox
- La generation de fils de discussion

---

## Hooks (accroches)

Les hooks sont des phrases d'accroche pre-redigees, injectees dans les contenus generes par l'IA. Accessible aux **gestionnaires** (manager+).

### Organisation

Les hooks sont organises par **categories** (ex: "Question", "Statistique choc", "Anecdote"). Chaque categorie a :
- Un **nom** et un **slug** (genere automatiquement)
- Une **couleur** pour l'identification visuelle
- Un **ordre de tri**
- Un statut **actif/inactif**

### Rotation equitable

Le systeme utilise une rotation de type **round-robin** :
- A chaque utilisation, le hook le **moins utilise** de la categorie est selectionne
- En cas d'egalite, celui qui n'a **jamais ete utilise** ou le **plus ancien** en derniere utilisation est choisi
- Cela garantit une distribution equitable et evite les repetitions

### Compteurs

Chaque hook enregistre :
- `times_used` : Nombre total d'utilisations
- `last_used_at` : Date de derniere utilisation

Les compteurs peuvent etre reinitialises par categorie depuis l'interface.

---

## Bibliotheque de medias

### Fonctionnalites

- **Upload** : Glisser-deposer ou selection de fichiers (images, videos)
- **Dossiers** : Organisation des medias en dossiers (creer, renommer, deplacer des fichiers)
- **Vignettes** : Generation automatique de vignettes pour les images
- **Selection** : Depuis les formulaires de creation de post, naviguer et selectionner des medias

### Limites

Les tailles maximales d'upload sont configurables dans les Parametres :
- Images : defaut **10 MB**
- Videos : defaut **50 MB**

### Compression

Les images et videos sont automatiquement compressees avant publication selon les parametres configurables :

| Parametre | Description | Defaut |
|---|---|---|
| `image_max_dimension` | Dimension max (largeur ou hauteur) | 2048 px |
| `image_target_min_kb` | Poids cible minimum | 200 KB |
| `image_target_max_kb` | Poids cible maximum | 500 KB |
| `image_min_quality` | Qualite JPEG minimum | 60 |
| `video_bitrate_1080p` | Bitrate video 1080p | 6000 kbps |
| `video_bitrate_720p` | Bitrate video 720p | 2500 kbps |
| `video_codec` | Codec video | H.264 |
| `video_audio_bitrate` | Bitrate audio | 128 kbps |

---

## Statistiques

### Vue d'ensemble

Le dashboard des statistiques affiche :
- **KPIs** : Vues, likes, commentaires, partages agreres sur la periode
- **Audience** : Evolution du nombre de followers dans le temps (graphiques)
- **Publications** : Performance de chaque publication
- **Par plateforme** : Metriques detaillees par reseau social

### Synchronisation

Les statistiques sont synchronisees automatiquement depuis les APIs des plateformes. La configuration se fait dans **Parametres > Statistiques** :

| Parametre | Description | Defaut |
|---|---|---|
| Frequence de sync globale | A quelle frequence verifier les mises a jour | Toutes les heures |
| Intervalle par plateforme | Heures entre chaque sync pour une plateforme | 12-24h |
| Historique max | Nombre de jours de donnees a conserver | 14-30 jours |

### Import historique

L'import historique (gestionnaire+) permet de recuperer l'historique des publications passees d'un compte. Cette operation a un **cout en quota API** et un **cooldown** entre les imports.

### Sync des followers

Les snapshots de followers peuvent etre synchronises manuellement pour alimenter les graphiques d'audience.

---

## Parametres

Accessibles aux **gestionnaires** (manager+), les parametres sont organises en onglets.

### IA

- **Cle API OpenAI** : Stockee de maniere chiffree. Necessaire pour toute fonctionnalite IA
- **Modeles** : Choix du modele pour chaque usage (texte, vision, traduction, sources, inbox)
- Les modeles disponibles sont automatiquement recuperes depuis l'API OpenAI

### Compression

- Parametres de compression des images (dimension max, poids cible, qualite min)
- Parametres de compression video (bitrate par resolution, codec, audio)

### Statistiques

- Frequence de synchronisation globale
- Intervalle de sync par plateforme (Facebook, Instagram, Twitter, YouTube, Threads, Bluesky)
- Historique maximum par plateforme (en jours)

### Limites de caracteres

Limites par plateforme, utilisees par l'IA et affichees dans les formulaires :

| Plateforme | Defaut |
|---|---|
| Twitter | 280 |
| Bluesky | 300 |
| Threads | 500 |
| Instagram | 2 200 |
| Telegram | 4 096 |
| YouTube | 5 000 |
| Facebook | 63 206 |

### Messagerie (Inbox)

- **Activation par plateforme** : Activer/desactiver la collecte pour chaque reseau
- **Frequence de sync** : Configurable par plateforme (de 15 min a quotidien)
- **Modele IA** : Modele utilise pour les suggestions de reponse
- **Prompt de reponse** : Prompt systeme personnalisable pour les reponses IA

---

## Gestion des utilisateurs

Reservee aux **administrateurs**.

### Fonctionnalites

- **Creer** un utilisateur (nom, email, mot de passe, role)
- **Modifier** un utilisateur existant
- **Supprimer** un utilisateur
- **Changer le role** (utilisateur ↔ gestionnaire ↔ admin)

### Association aux comptes

Les utilisateurs sont associes aux comptes sociaux via une relation many-to-many. Un meme compte peut etre partage entre plusieurs utilisateurs. L'administrateur voit tous les comptes ; les autres utilisateurs ne voient que les comptes auxquels ils sont lies.

---

## Systeme de mise a jour

Accessible aux **administrateurs** uniquement.

### Configuration

Pour activer la detection des mises a jour, configurez le depot Git de reference :

```env
DEPLOY_GIT_REPO=https://github.com/lemonsieurxav80/rs-max.git
DEPLOY_GIT_BRANCH=main
```

Pour le deploiement automatique depuis l'interface (optionnel, necessite une plateforme compatible comme Coolify) :

```env
DEPLOY_API_URL=https://votre-plateforme.com
DEPLOY_API_TOKEN=votre-token-api
DEPLOY_APP_UUID=identifiant-de-lapp
```

### Fonctionnement

1. L'application verifie automatiquement (toutes les heures) s'il y a une nouvelle version sur le depot Git distant
2. La comparaison se fait entre le hash du commit local et le hash distant (`git ls-remote` sur le repo configure)
3. Si une mise a jour est disponible, un indicateur apparait dans la barre laterale (point ambre)
4. Le changelog entre les deux versions est affiche
5. Si le deploiement automatique est configure, l'administrateur peut le declencher depuis l'interface

### Sources du hash local

Le hash local est determine dans cet ordre :
1. Fichier `git-version.txt` (cree lors du build Docker)
2. Variable d'environnement `SOURCE_COMMIT` (fournie par Coolify)
3. Commande `git rev-parse HEAD` (en dernier recours)

---

## Endpoint de sante

L'endpoint `GET /health` est **public** (pas d'authentification requise) et retourne un JSON avec l'etat de sante de l'application :

```json
{
  "status": "healthy",
  "checks": {
    "database": true,
    "queue_worker": true,
    "scheduler": true,
    "disk_free_mb": 5120
  }
}
```

### Verifications

| Check | Methode |
|---|---|
| `database` | Tentative de connexion PDO |
| `queue_worker` | Heartbeat via cache (le worker ecrit un timestamp periodiquement) |
| `scheduler` | Heartbeat via cache (le scheduler ecrit un timestamp periodiquement) |
| `disk_free_mb` | Espace disque libre en MB |

### Statuts

- `healthy` : Tous les checks passent
- `degraded` : Au moins un check a echoue

Utilisez cet endpoint pour configurer un monitoring externe (Uptime Robot, Coolify health check, etc.).

---

## Commandes Artisan

### Commandes personnalisees

| Commande | Description |
|---|---|
| `php artisan make:admin` | Creer un compte administrateur interactivement |
| `php artisan bot:run` | Executer le bot d'automatisation (Bluesky + Facebook) |
| `php artisan bot:prospect` | Executer la prospection Bluesky |

### Options du bot

```
php artisan bot:run --platform=bluesky    # Bluesky uniquement
php artisan bot:run --platform=facebook   # Facebook uniquement
php artisan bot:run --account=5           # Pour un compte specifique
```

```
php artisan bot:prospect --target=3       # Pour un compte cible specifique
php artisan bot:prospect --account=5      # Pour un compte social specifique
```

### Taches planifiees

Le scheduler Laravel execute automatiquement :

- **Bot** : `bot:run` selon la frequence configuree par compte
- **Statistiques** : Synchronisation selon la frequence globale
- **Inbox** : Synchronisation par plateforme selon sa frequence
- **Sources** : Fetch automatique des sources RSS/WordPress/YouTube/Reddit
- **Publication** : Publication des posts planifies quand leur date est atteinte
- **Mise a jour** : Verification horaire des nouvelles versions

---

## Architecture technique

### Stack

- **Backend** : Laravel 11 (PHP 8.2+)
- **Frontend** : Blade + Alpine.js + Tailwind CSS
- **Base de donnees** : SQLite (defaut) ou MySQL/PostgreSQL
- **File d'attente** : Database queue (defaut)
- **Build CSS/JS** : Vite

### Chiffrement des credentials

Les credentials de chaque compte social sont stockes avec le cast `encrypted:array` de Laravel. Cela signifie qu'ils sont chiffres en base de donnees avec la cle `APP_KEY` et dechiffres a la volee lors de l'acces. **Ne changez jamais APP_KEY apres le deploiement initial.**

### Rate limiting

- Les routes authentifiees sont limitees a **60 requetes par minute**
- La connexion et l'inscription sont limitees a **5 tentatives par minute**
- Les bots incluent des delais entre les actions pour respecter les limites des APIs

### Structure des fichiers cles

```
app/
├── Console/Commands/         # Commandes Artisan (bot:run, bot:prospect, make:admin)
├── Http/Controllers/         # Controleurs web
├── Models/                   # Modeles Eloquent
├── Policies/                 # Politiques d'autorisation
├── Services/
│   ├── Bot/                  # BlueskyBotService, BlueskyProspectService, FacebookBotService
│   ├── Inbox/                # Services de sync inbox par plateforme
│   └── ...                   # ContentGenerationService, UpdateService, etc.
config/
├── app.php                   # registration_enabled
├── services.php              # Credentials plateformes + config deploiement
database/
├── seeders/                  # PlatformSeeder (plateformes), HookSeeder (hooks par defaut)
docker/
├── entrypoint.sh             # Script d'initialisation du conteneur
├── supervisord.conf          # Configuration des processus (nginx, php-fpm, queue, scheduler)
docs/
├── USER-GUIDE.md             # Ce guide
├── DEPLOYMENT-ROADMAP.md     # Checklist de deploiement
```
