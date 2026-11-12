#!/bin/sh
# Installe ou met à jour le service de recherche d'un Vault sur ce serveur.
# usage : install.sh CORPUS CHEMIN_DU_VAULT DOSSIER_DES_SOCKETS [GID_DU_SOCKET]
# Le dossier des sockets est monté dans RS-Max sur /run/rsmax-vault (volume Coolify).
set -eu
corpus="$1"; vault="$2"; sockets="$3"; gid="${4:-82}"
case "$corpus" in [a-z]*) ;; *) echo "corpus invalide" >&2; exit 1;; esac
here=$(cd "$(dirname "$0")" && pwd)
git -c safe.directory='*' -C "$vault" rev-parse --short HEAD >/dev/null
install -d -m 750 -o 1000 -g "$gid" "$sockets"
docker build -q -t "rsmax-vault-lookup:local" "$here" >/dev/null
docker rm -f "rsmax-vault-$corpus" >/dev/null 2>&1 || true
docker run -d --name "rsmax-vault-$corpus" --restart unless-stopped --network none --read-only \
    --user 1000:1000 --group-add "$gid" -e VAULT_SOCKET_GID="$gid" \
    -v "$vault:/vault:ro" -v "$sockets:/socket" \
    rsmax-vault-lookup:local /vault --socket "/socket/$corpus.sock" >/dev/null
sleep 2
docker logs "rsmax-vault-$corpus" 2>&1 | tail -1
