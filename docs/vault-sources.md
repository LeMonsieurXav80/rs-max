# Sources du Vault pour les réponses

Un compte peut s’appuyer sur des dossiers du Vault de son serveur pour répondre : Hermès (`x-context`, `reply-context`) et Companion (Mac, NAS, VPS) passent par le même `XReplyVault`, donc par les mêmes sources.

## Modèle

- `/vault-sources` (rôle manager) : une source = un Vault du serveur (`corpus`), un format (`notes` ou `pubmed`), des dossiers autorisés, des exclusions, des marques à ne jamais placer d’elles-mêmes, et les comptes qui l’utilisent. La fiche du compte affiche ses sources actives.
- Tables `vault_sources` et `social_account_vault_source`, dans la base de chaque instance. Aucune configuration n’est partagée entre lemonsieurxav et DIX : chaque écosystème garde son Vault.
- Les zones sensibles (`07-Raw`, `Compta`, `Mails`, `Contacts`, `Contacts-Pro`, `Partenaires`, identifiants, `.git`, `.obsidian`) sont refusées par le formulaire, par `XReplyVault` et par le service lui-même.
- La réponse reçoit au plus 4 extraits et `vault.guard` : ne citer que la source, aucune information interne, ne pas prétendre avoir lu ou vécu ce qu’elle contient ni parler au nom de son auteur.
- Avant migration, l’ancien réglage `x_reply_vault_account_{id}` reste lu (fiches PubMed de `Recherches`). `php artisan vault:migrate-legacy --commit` le remplace par une source.

## Service par serveur

`docker/vault-lookup/` : un service Python sans réseau, en lecture seule, qui lit la révision Git courante de la copie du Vault d’Hermès et répond sur `/run/rsmax-vault/{corpus}.sock`. RS-Max envoie le format et les dossiers de la source.

```sh
docker/vault-lookup/install.sh CORPUS CHEMIN_DU_VAULT DOSSIER_DES_SOCKETS [GID]
```

Le dossier des sockets est monté dans le conteneur RS-Max sur `/run/rsmax-vault` (volume de fichier Coolify, dossier). GID 82 : `www-data` du conteneur RS-Max.

| Instance | Corpus | Vault | Sockets |
| --- | --- | --- | --- |
| lemonsieurxav | `cerveau-xav` | copie Hermès `vaults/vault-cerveau-xav` (pull Gitea toutes les 5 min) | `/opt/rsmax-vault/sockets` |
| DIX | `dix` | copie Hermès `vaults/vault-dix` | `/opt/rsmax-dix-vault/sockets` |

Ajouter des dossiers à un autre compte ne demande aucune intervention technique : créer ou modifier la source dans `/vault-sources`. Un nouveau serveur demande un Vault, `install.sh` et le volume Coolify.

Tests : `tests/Feature/VaultSourceTest.php` et `python3 -m unittest discover -s docker/vault-lookup`.
