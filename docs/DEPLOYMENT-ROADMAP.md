# RS-Max — Roadmap Deploiement Client

Ce document liste tout ce qui doit etre corrige/ajoute avant de deployer RS-Max sur un VPS client distinct.

---

## 1. Seeders & Donnees initiales

### 1.1 PlatformSeeder incomplet
**Fichier** : `database/seeders/PlatformSeeder.php`
- Seules 5 plateformes sont definies : Facebook, Twitter, Instagram, Telegram, Threads
- **Manquant** : Bluesky, YouTube, Reddit
- **Action** : Ajouter les 3 plateformes manquantes avec leurs `credential_fields`

### 1.2 HookSeeder en francais uniquement
**Fichier** : `database/seeders/HookSeeder.php`
- Tous les hooks (prompts, templates) sont en francais
- **Action** : Acceptable pour un client francophone. Documenter que les hooks peuvent etre modifies via l'interface admin apres installation.

### 1.3 DatabaseSeeder minimal
**Fichier** : `database/seeders/DatabaseSeeder.php`
- Cree un "Test User" avec `test@example.com` — inutile en production
- **Action** : Creer une commande artisan `make:admin` qui demande nom/email/mot de passe et cree un admin. Retirer le user de test du seeder de production.

---

## 2. Configuration & Variables d'environnement

### 2.1 `.env.example` incomplet
**Fichier** : `.env.example`
- **Manquant** :
  - `THREADS_APP_ID`, `THREADS_APP_SECRET`
  - `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`
  - `APP_TIMEZONE` (defaut `UTC` au lieu de ce que le client attend)
  - `APP_LOCALE` (defaut `en` au lieu de `fr`)
  - `BLUESKY_*` (si applicable)
- **Action** : Completer `.env.example` avec toutes les variables utilisees dans `config/services.php` et les valeurs par defaut commentees.

### 2.2 `docker-compose.yml` hardcode locale/timezone
**Fichier** : `docker-compose.yml`
- `APP_LOCALE: fr` et `APP_TIMEZONE: Europe/Paris` en dur
- **Action** : Utiliser `${APP_LOCALE:-fr}` et `${APP_TIMEZONE:-Europe/Paris}` pour rendre configurable via `.env`

### 2.3 Entrypoint et variables
**Fichier** : `docker/entrypoint.sh`
- Les variables Docker sont injectees a l'execution mais certaines commandes artisan s'executent avant que le `.env` soit charge
- **Action** : Verifier l'ordre d'execution dans l'entrypoint. S'assurer que `php artisan config:cache` est appele apres l'injection des variables.

---

## 3. Securite

### 3.1 `/register` ouvert publiquement
**Fichier** : `routes/auth.php`
- N'importe qui peut creer un compte sur l'instance
- **Action** : Desactiver `/register` par defaut. Ajouter une variable `REGISTRATION_ENABLED=false` dans `.env`. La creation de comptes se fait via la commande `make:admin` ou par un admin existant.

### 3.2 Rate limiting insuffisant
**Fichiers** : `routes/web.php`, `routes/api.php`
- Seules les routes d'email verification ont un `throttle`
- Les routes API (bot, inbox, publication) n'ont pas de rate limiting
- **Action** : Ajouter `throttle:60,1` (60 req/min) sur les groupes de routes API. Ajouter `throttle:5,1` sur `/login` et `/register`.

### 3.3 Pas de Policies/Gates
- Les autorisations sont verifiees manuellement dans les controllers (role check)
- **Action** : A minima, documenter le systeme d'autorisations actuel. Idealement, creer des Policies Laravel pour `Post`, `SocialAccount`, `BotTargetAccount` pour centraliser les regles d'acces.

### 3.4 Token Telegram expose dans les URLs media
- Quand le bot Telegram recoit des fichiers, l'URL contient le bot token : `https://api.telegram.org/file/bot<TOKEN>/...`
- **Action** : Proxifier le telechargement des fichiers Telegram cote serveur (telecharger le fichier, le stocker localement, servir l'URL locale). Ne jamais exposer le token dans le frontend.

### 3.5 `APP_KEY` et credentials chiffrees
- `social_accounts.credentials` utilise le cast `encrypted:array` (lie a `APP_KEY`)
- `Setting::getEncrypted()` utilise `Crypt` (lie aussi a `APP_KEY`)
- **Si l'APP_KEY change**, tous les credentials sont perdus irreversiblement
- **Action** : Documenter clairement que `APP_KEY` ne doit JAMAIS etre modifie apres l'installation. Ajouter un warning dans le README. Envisager un systeme d'export/import des credentials.

---

## 4. Systeme de mise a jour

### 4.1 Probleme actuel
- Le deploiement est declenche automatiquement par un push sur `main` (via Coolify)
- C'est a l'envers : le developpeur pousse, le client subit la mise a jour sans etre prevenu
- Les taches en cours (bot prospect, publications programmees) sont interrompues

### 4.2 Solution proposee
1. **Notification** : Un cron (schedule) verifie periodiquement `git ls-remote` pour detecter de nouveaux commits sur `main`
2. **Affichage** : Si une MAJ est disponible, afficher un badge/notification dans l'interface admin
3. **Declenchement manuel** : L'admin clique sur "Mettre a jour" qui appelle l'API Coolify pour declencher le deploiement
4. **Changelog** : Afficher les commits entre la version actuelle et la nouvelle version disponible

### 4.3 Implementation
- **Verification** : `Schedule::call()` toutes les heures, compare le hash local (`git rev-parse HEAD`) avec le remote
- **Stockage** : Table `settings` ou cache pour stocker `update_available`, `remote_hash`, `checked_at`
- **API Coolify** : `POST /api/v1/applications/{uuid}/deploy` avec le token API Coolify
- **Variables .env** : `COOLIFY_API_URL`, `COOLIFY_API_TOKEN`, `COOLIFY_APP_UUID`
- **Desactiver auto-deploy** : Dans Coolify, desactiver le webhook de deploiement automatique

---

## 5. Documentation & Onboarding

### 5.1 README absent
- Le README actuel est le README par defaut de Laravel
- **Action** : Ecrire un README specifique a RS-Max avec :
  - Description du projet
  - Pre-requis (Docker, Coolify ou serveur classique)
  - Guide d'installation rapide
  - Variables d'environnement requises
  - Commandes artisan utiles

### 5.2 Page d'aide in-app
- Aucune documentation accessible depuis l'interface
- **Action** : Creer une page `/help` qui lit et affiche un fichier Markdown (ex: `docs/USER-GUIDE.md`)
- La page peut utiliser une librairie comme `league/commonmark` pour le rendu HTML

### 5.3 Guide utilisateur
- **Action** : Rediger un guide couvrant :
  - Connexion des comptes sociaux
  - Creation et planification de publications
  - Configuration du bot de prospection
  - Gestion de la boite de reception (inbox)
  - Gestion des sources de contenu (RSS, WordPress, YouTube, Reddit)

---

## 6. Infrastructure & Deploiement

### 6.1 Graceful shutdown des workers
**Fichier** : `docker/supervisord.conf` (ou equivalent)
- Les queue workers doivent avoir un `stopwaitsecs` suffisant pour terminer les jobs en cours
- **Action** : Verifier que `stopwaitsecs=30` (ou plus) est configure. Utiliser `--timeout=25` sur les workers pour que le job se termine avant le kill.

### 6.2 Recovery des taches orphelines
**Deja implemente** dans cette branche :
- `docker/entrypoint.sh` : Reset des targets `running` → `pending` au demarrage
- `routes/console.php` : Schedule toutes les 5 min pour detecter et relancer les targets orphelines

### 6.3 Healthcheck
- **Action** : Ajouter un endpoint `/health` qui verifie :
  - Connexion base de donnees
  - Queue worker actif (via cache heartbeat)
  - Scheduler actif (via cache heartbeat)
  - Espace disque suffisant

---

## Priorites

| Priorite | Tache | Effort |
|----------|-------|--------|
| CRITIQUE | Desactiver `/register` | 15 min |
| CRITIQUE | Commande `make:admin` | 30 min |
| CRITIQUE | Completer `.env.example` | 15 min |
| CRITIQUE | Documenter `APP_KEY` | 5 min |
| HAUTE | PlatformSeeder complet | 20 min |
| HAUTE | Rate limiting routes | 20 min |
| HAUTE | README projet | 45 min |
| HAUTE | Systeme de MAJ (notification) | 2-3h |
| MOYENNE | Page d'aide in-app | 1-2h |
| MOYENNE | Policies Laravel | 2-3h |
| MOYENNE | Proxy media Telegram | 1h |
| MOYENNE | Docker-compose variables | 10 min |
| BASSE | Healthcheck endpoint | 30 min |
| BASSE | Guide utilisateur complet | 2-3h |
