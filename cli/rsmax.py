#!/usr/bin/env python3
"""
rsmax.py — publier sur les reseaux depuis son ordinateur, sans perte de qualite.

A QUOI CA SERT
==============
Dire « mes videos sont la, poste-les » depuis une conversation ou un terminal,
sur son propre poste, avec ses propres fichiers. Le serveur garde ce qu'il fait
bien : la programmation (le PC peut etre eteint a l'heure dite), le calendrier,
les statistiques.

LE PROBLEME QU'IL RESOUT, ET CELUI QU'IL NE RESOUT PAS
======================================================
RS-Max recompressait tout ce qui entrait : les images visaient 200-500 ko et
tout ce qui depassait 2048 px etait reduit, les videos de plus de 50 Mo etaient
transcodees et **l'original supprime**. Le reseau recompressant ensuite
par-dessus, on perdait deux fois.

`envoyer` passe par `POST /api/media/upload`, qui stocke l'octet exact. La
reduction qu'un reseau impose arrive a la publication, pour ce reseau seul :
Instagram recoit l'original, seul Bluesky recoit une version reduite.

La compression du reseau lui-meme, personne ne l'evite — pas plus en publiant
depuis le telephone. Ce qui est evite ici, c'est la PREMIERE, la notre.

CONSEQUENCE PRATIQUE : exporter aux dimensions finales. Instagram reduit tout
ce qui depasse, avec un redimensionnement mediocre. 1080x1350 pour une slide,
1080x1920 / 30 ips / H.264 pour une video verticale.

AUCUNE DEPENDANCE
=================
Bibliotheque standard uniquement : ni pip, ni venv, ni compilation. Python 3.8+
suffit, ce qui compte sur un poste Windows ou l'on ne veut pas installer une
chaine de build pour publier une photo.

LE JETON NE VA PAS A COTE DU CODE
=================================
Il est lu dans cet ordre : variables d'environnement `RSMAX_TOKEN` / `RSMAX_URL`,
puis le fichier de configuration (`%APPDATA%\\rs-max\\config.json` sous Windows,
`~/.config/rs-max/config.json` ailleurs). Jamais depuis le dossier du depot.

C'est deliberement le disque systeme, et pas le disque d'outils : un disque en
exFAT ne porte AUCUNE permission de fichier — le `chmod` est sans effet, et le
disque se rebranche ailleurs tel quel. Le jeton donne le droit de publier au nom
du compte ; il n'a rien a faire la. Meme raison que pour les jetons YouTube.

Chacun son jeton (`php artisan api:token --user=<email>`) : les journaux disent
alors qui a publie, et revoquer l'un ne coupe pas l'autre.

COMMANDES
=========
    comptes                      les comptes et leurs identifiants
    envoyer <fichiers...>        depose des fichiers, sans retouche
    publier                      cree une publication (programmee par defaut)
    etat                         les publications a venir, ou le detail de l'une
    config                       enregistre une instance et son jeton

Chaque commande accepte `--profil` (plusieurs instances RS-Max cohabitent) et
`--dry-run`, qui montre ce qui serait fait sans rien envoyer.
"""
import argparse
import json
import mimetypes
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from datetime import datetime
from pathlib import Path

MEDIA_EXTENSIONS = {'.jpg', '.jpeg', '.png', '.gif', '.webp', '.mp4', '.mov', '.webm'}

# Un envoi de 500 Mo ne tient pas en memoire : le corps multipart est lu par
# morceaux depuis le disque (cf MultipartBody).
CHUNK = 256 * 1024


# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------

def config_path():
    """Le fichier de configuration vit sur le disque systeme, sous le profil
    de l'utilisateur — jamais dans le depot ni sur un disque exFAT."""
    if os.name == 'nt':
        base = Path(os.environ.get('APPDATA', Path.home() / 'AppData/Roaming'))
        return base / 'rs-max' / 'config.json'
    return Path(os.environ.get('XDG_CONFIG_HOME', Path.home() / '.config')) / 'rs-max' / 'config.json'


def load_config():
    path = config_path()
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding='utf-8'))
    except json.JSONDecodeError as error:
        raise SystemExit(f'Configuration illisible ({path}) : {error}')


def resolve_profile(name=None):
    """Rend (url, token). L'environnement prime sur le fichier : il permet un
    appel ponctuel sur une autre instance sans toucher a la configuration."""
    config = load_config()
    profiles = config.get('profils', {})
    name = name or os.environ.get('RSMAX_PROFIL') or config.get('defaut')

    profile = profiles.get(name, {}) if name else {}
    url = os.environ.get('RSMAX_URL') or profile.get('url')
    token = os.environ.get('RSMAX_TOKEN') or profile.get('token')

    if not url or not token:
        known = ', '.join(sorted(profiles)) or 'aucun'
        raise SystemExit(
            'Instance ou jeton introuvable.\n'
            f'  profil demande : {name or "(aucun)"}\n'
            f'  profils connus : {known}\n'
            f'  fichier        : {config_path()}\n\n'
            'Enregistrer une instance :\n'
            '  rsmax.py config --profil dix --url https://rs.dix-10.com/api --token <jeton>\n\n'
            'Le jeton se genere sur le serveur :\n'
            '  php artisan api:token --user=<email> --name=<libelle>'
        )

    return url.rstrip('/'), token


def command_config(args):
    """Enregistre une instance. Le jeton n'est jamais affiche en retour."""
    path = config_path()
    config = load_config()
    config.setdefault('profils', {})

    profile = config['profils'].setdefault(args.profil, {})
    if args.url:
        profile['url'] = args.url.rstrip('/')
    if args.token:
        profile['token'] = args.token
    if args.defaut or len(config['profils']) == 1:
        config['defaut'] = args.profil

    if not profile.get('url') or not profile.get('token'):
        raise SystemExit('Il faut --url et --token au premier enregistrement du profil.')

    if args.dry_run:
        print(f'[essai] profil « {args.profil} » vers {profile["url"]} dans {path}')
        return

    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(config, indent=2, ensure_ascii=False), encoding='utf-8')

    # Sans effet sur un systeme de fichiers sans permissions (exFAT) : c'est
    # precisement pourquoi ce fichier doit rester sur le disque systeme.
    if os.name != 'nt':
        os.chmod(path, 0o600)

    print(f'Profil « {args.profil} » enregistre dans {path}')
    print(f'  instance : {profile["url"]}')
    print(f'  defaut   : {config.get("defaut")}')


# --------------------------------------------------------------------------
# Appels HTTP
# --------------------------------------------------------------------------

class MultipartBody:
    """Corps multipart lu par morceaux depuis le disque.

    Une video de 500 Mo assemblee en memoire ferait tomber le poste pour rien.
    L'objet expose `read()`, ce qui suffit a urllib pour l'envoyer en flux, et
    annonce sa taille exacte via `Content-Length`.
    """

    def __init__(self, file_path, fields):
        self.boundary = uuid.uuid4().hex
        self.file_path = Path(file_path)
        mime = mimetypes.guess_type(self.file_path.name)[0] or 'application/octet-stream'

        preamble = []
        for key, value in fields.items():
            for item in (value if isinstance(value, list) else [value]):
                preamble.append(
                    f'--{self.boundary}\r\n'
                    f'Content-Disposition: form-data; name="{key}"\r\n\r\n{item}\r\n'
                )
        preamble.append(
            f'--{self.boundary}\r\n'
            f'Content-Disposition: form-data; name="file"; filename="{self.file_path.name}"\r\n'
            f'Content-Type: {mime}\r\n\r\n'
        )

        self.preamble = ''.join(preamble).encode('utf-8')
        self.epilogue = f'\r\n--{self.boundary}--\r\n'.encode('utf-8')
        self.length = len(self.preamble) + self.file_path.stat().st_size + len(self.epilogue)
        self._stream = None
        self._position = 0

    @property
    def content_type(self):
        return f'multipart/form-data; boundary={self.boundary}'

    def read(self, size=-1):
        # Trois sources a la suite : entete, fichier, pied.
        if self._position == 0:
            self._position = 1
            self._stream = self.file_path.open('rb')
            return self.preamble
        if self._position == 1:
            chunk = self._stream.read(CHUNK if size in (-1, None) else size)
            if chunk:
                return chunk
            self._stream.close()
            self._position = 2
            return self.epilogue
        return b''


def call(url, token, method, path, payload=None, upload=None, timeout=1800):
    """Un appel a l'API. `upload` est un MultipartBody, `payload` du JSON."""
    request = urllib.request.Request(f'{url}{path}', method=method)
    request.add_header('Authorization', f'Bearer {token}')
    request.add_header('Accept', 'application/json')

    if upload is not None:
        request.add_header('Content-Type', upload.content_type)
        request.add_header('Content-Length', str(upload.length))
        request.data = upload
    elif payload is not None:
        body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        request.add_header('Content-Type', 'application/json')
        request.data = body

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return json.loads(response.read().decode('utf-8') or '{}')
    except urllib.error.HTTPError as error:
        detail = error.read().decode('utf-8', 'replace')
        try:
            parsed = json.loads(detail)
            # Laravel repond `error` (message unique) ou `errors` (validation).
            message = parsed.get('error') or parsed.get('message') or detail
            if parsed.get('errors'):
                lines = [f'    {field} : {"; ".join(msgs)}' for field, msgs in parsed['errors'].items()]
                message += '\n' + '\n'.join(lines)
        except json.JSONDecodeError:
            message = detail[:500]
        raise SystemExit(f'HTTP {error.code} sur {method} {path}\n  {message}')
    except urllib.error.URLError as error:
        raise SystemExit(f'Instance injoignable ({url}) : {error.reason}')


# --------------------------------------------------------------------------
# comptes
# --------------------------------------------------------------------------

def command_comptes(args):
    url, token = resolve_profile(args.profil)
    accounts = call(url, token, 'GET', '/accounts').get('accounts', [])

    if not accounts:
        print('Aucun compte. Un utilisateur « user » ne voit que les comptes qui lui sont rattaches.')
        return

    if args.json:
        print(json.dumps(accounts, indent=2, ensure_ascii=False))
        return

    print(f'{"id":>4}  {"plateforme":<12} {"nom":<28} {"langues":<10} abonnes')
    print(f'{"-" * 4}  {"-" * 12} {"-" * 28} {"-" * 10} -------')
    for account in sorted(accounts, key=lambda a: (a.get('platform', ''), a.get('id', 0))):
        langues = ','.join(account.get('languages') or [])
        followers = account.get('followers_count')
        print(
            f'{account.get("id", ""):>4}  '
            f'{account.get("platform", ""):<12} '
            f'{(account.get("name") or "")[:28]:<28} '
            f'{langues:<10} '
            f'{followers if followers is not None else "-"}'
        )


def accounts_by_id(url, token):
    accounts = call(url, token, 'GET', '/accounts').get('accounts', [])
    return {int(a['id']): a for a in accounts if a.get('id') is not None}


# --------------------------------------------------------------------------
# envoyer
# --------------------------------------------------------------------------

def expand_media_paths(entries):
    """Accepte des fichiers et des dossiers. Un dossier est parcouru a plat et
    trie par nom : c'est l'ordre des slides d'un carrousel, il doit etre
    previsible, pas celui du systeme de fichiers."""
    paths = []
    for entry in entries:
        path = Path(entry).expanduser()
        if path.is_dir():
            # Les fichiers caches sont ecartes : copier vers un NAS ou un disque
            # exFAT seme des `._fichier.jpg` (AppleDouble) qui portent une
            # extension d'image et n'en sont pas.
            paths.extend(sorted(
                child for child in path.iterdir()
                if child.is_file()
                and not child.name.startswith('.')
                and child.suffix.lower() in MEDIA_EXTENSIONS
            ))
        elif path.is_file():
            paths.append(path)
        else:
            raise SystemExit(f'Introuvable : {path}')

    if not paths:
        raise SystemExit('Aucun fichier exploitable. Extensions reconnues : '
                         + ', '.join(sorted(MEDIA_EXTENSIONS)))
    return paths


def upload_one(url, token, path, folder=None, dry_run=False):
    size_mb = path.stat().st_size / 1048576

    if dry_run:
        print(f'[essai] {path.name} ({size_mb:.1f} Mo) tel quel')
        return None

    fields = {}
    if folder:
        fields['folder_path'] = folder

    print(f'  {path.name} ({size_mb:.1f} Mo)… ', end='', flush=True)
    result = call(url, token, 'POST', '/media/upload', upload=MultipartBody(path, fields))

    if result.get('status') == 'exists':
        print(f'deja presente → {result["url"]}')
    else:
        stored = result.get('size', 0) / 1048576
        print(f'{result["url"]} ({stored:.1f} Mo stockes)')
    return result


def command_envoyer(args):
    url, token = resolve_profile(args.profil)
    paths = expand_media_paths(args.fichiers)

    total = sum(p.stat().st_size for p in paths) / 1048576
    print(f'{len(paths)} fichier(s), {total:.1f} Mo au total, sans retouche :')

    results = [upload_one(url, token, path, args.dossier, args.dry_run) for path in paths]
    results = [r for r in results if r]

    if results and args.json:
        print(json.dumps(results, indent=2, ensure_ascii=False))


# --------------------------------------------------------------------------
# publier
# --------------------------------------------------------------------------

def parse_when(value):
    """`2026-10-08 18:00` dans le fuseau du poste, ou une date ISO 8601
    complete. Le fuseau local est joint explicitement : sans lui le serveur
    interprete l'heure dans SON fuseau, et la publication part a cote."""
    text = value.strip().replace('/', '-')
    for pattern in ('%Y-%m-%d %H:%M', '%Y-%m-%dT%H:%M', '%Y-%m-%d %H:%M:%S'):
        try:
            naive = datetime.strptime(text, pattern)
            return naive.astimezone().isoformat()
        except ValueError:
            continue
    try:
        parsed = datetime.fromisoformat(text)
    except ValueError:
        raise SystemExit(f'Date incomprise : « {value} ». Attendu « 2026-10-08 18:00 » ou une date ISO 8601.')
    return (parsed if parsed.tzinfo else parsed.astimezone()).isoformat()


def read_text(args):
    if args.texte_fichier:
        path = Path(args.texte_fichier).expanduser()
        if not path.is_file():
            raise SystemExit(f'Fichier de texte introuvable : {path}')
        return path.read_text(encoding='utf-8').strip()
    return (args.texte or '').strip()


def check_one_account_per_platform(ids, catalog):
    """Refuse deux comptes du MEME reseau dans une publication.

    L'API l'accepte (201) mais la publication ne se fait pas : l'anomalie est
    silencieuse et ne se voit qu'a l'heure prevue, trop tard. Pour publier a la
    fois cote Cyril et cote DIX, il faut deux publications distinctes.
    """
    seen = {}
    for account_id in ids:
        platform = (catalog.get(account_id) or {}).get('platform', '?')
        seen.setdefault(platform, []).append(account_id)

    clashes = {p: v for p, v in seen.items() if len(v) > 1}
    if clashes:
        lines = [f'    {p} : comptes {", ".join(str(i) for i in v)}' for p, v in clashes.items()]
        raise SystemExit(
            'Deux comptes du meme reseau dans une seule publication :\n'
            + '\n'.join(lines)
            + '\n  L\'API accepterait, mais rien ne serait publie. Faire une publication par compte.'
        )


def command_publier(args):
    url, token = resolve_profile(args.profil)

    text = read_text(args)
    if not text:
        raise SystemExit('Il faut un texte : --texte "..." ou --texte-fichier chemin.txt')

    account_ids = [int(value) for value in re.split(r'[,\s]+', args.comptes.strip()) if value]
    if not account_ids:
        raise SystemExit('Il faut au moins un compte : --comptes 21,23')

    catalog = accounts_by_id(url, token)
    unknown = [i for i in account_ids if i not in catalog]
    if unknown:
        raise SystemExit(
            f'Comptes inconnus ou non rattaches : {", ".join(str(i) for i in unknown)}\n'
            '  « rsmax.py comptes » donne la liste a jour.'
        )
    check_one_account_per_platform(account_ids, catalog)

    # Les medias : des chemins locaux a envoyer, ou des URLs /media/... deja en
    # place. Les deux cohabitent, dans l'ordre donne.
    media = []
    if args.media:
        print('Envoi des medias, sans retouche :')
        for path in expand_media_paths(args.media):
            result = upload_one(url, token, path, args.dossier, args.dry_run)
            if result:
                kind = 'video' if result['mimetype'].startswith('video/') else 'image'
                media.append({'type': kind, 'url': result['url']})
            elif args.dry_run:
                kind = 'video' if path.suffix.lower() in {'.mp4', '.mov', '.webm'} else 'image'
                media.append({'type': kind, 'url': f'/media/(a envoyer)/{path.name}'})

    for url_media in (args.url_media or []):
        kind = 'video' if Path(url_media).suffix.lower() in {'.mp4', '.mov', '.webm'} else 'image'
        media.append({'type': kind, 'url': url_media})

    if args.maintenant:
        status = 'draft'          # cree puis publie tout de suite via /publish
    elif args.brouillon:
        status = 'draft'
    else:
        status = 'scheduled'

    payload = {'content_fr': text, 'status': status, 'accounts': account_ids}
    if status == 'scheduled':
        if not args.le:
            raise SystemExit(
                'Il faut une date : --le "2026-10-08 18:00"\n'
                '  (ou --brouillon pour ne pas programmer, --maintenant pour publier tout de suite)'
            )
        payload['scheduled_at'] = parse_when(args.le)
    if media:
        payload['media'] = media
    if args.hashtags:
        payload['hashtags'] = args.hashtags
    if args.lien:
        payload['link_url'] = args.lien

    cibles = ', '.join(
        f'{catalog[i].get("platform")}:{catalog[i].get("name")}' for i in account_ids
    )
    print()
    print(f'  texte    : {text[:90]}{"…" if len(text) > 90 else ""}')
    print(f'  comptes  : {cibles}')
    print(f'  medias   : {len(media) or "aucun"}')
    print(f'  quand    : {payload.get("scheduled_at", "publication immediate" if args.maintenant else "brouillon")}')

    if args.dry_run:
        print('\n[essai] rien n\'a ete cree. Retirer --dry-run pour agir.')
        return

    post = call(url, token, 'POST', '/posts', payload=payload)
    post_id = post.get('id') or (post.get('post') or {}).get('id')
    print(f'\nPublication {post_id} creee ({status}).')

    if args.maintenant:
        call(url, token, 'POST', f'/posts/{post_id}/publish')
        print('Publication lancee. Elle est asynchrone : « rsmax.py etat --id '
              f'{post_id} » dit ou elle en est, reseau par reseau.')


# --------------------------------------------------------------------------
# etat
# --------------------------------------------------------------------------

def command_etat(args):
    url, token = resolve_profile(args.profil)

    if args.id:
        post = call(url, token, 'GET', f'/posts/{args.id}')
        if args.json:
            print(json.dumps(post, indent=2, ensure_ascii=False))
            return

        detail = post.get('post', post)
        print(f'Publication {detail.get("id")} — {detail.get("status")}')
        print(f'  prevue le : {detail.get("scheduled_at") or "-"}')
        print(f'  texte     : {(detail.get("content_fr") or "")[:120]}')
        print(f'  medias    : {len(detail.get("media") or [])}')
        # `accounts[]` porte un statut PAR RESEAU : une publication « partial »
        # n'est lisible qu'ici, le statut global ne dit pas lequel a echoue.
        for target in (detail.get('accounts') or []):
            print(f'    {target.get("platform") or "?":<11} '
                  f'{(target.get("name") or "?")[:26]:<26} '
                  f'{target.get("status") or "?":<11} '
                  f'{target.get("url") or ""}')
        return

    query = urllib.parse.urlencode({'status': args.statut, 'per_page': args.limite})
    result = call(url, token, 'GET', f'/posts?{query}')
    posts = result.get('posts', [])

    if args.json:
        print(json.dumps(posts, indent=2, ensure_ascii=False))
        return

    if not posts:
        print(f'Aucune publication « {args.statut} ».')
        return

    print(f'{"id":>5}  {"quand":<17} {"statut":<11} {"med":>3}  texte')
    print(f'{"-" * 5}  {"-" * 17} {"-" * 11} {"-" * 3}  {"-" * 40}')
    for post in posts:
        when = (post.get('scheduled_at') or post.get('published_at') or '')[:16].replace('T', ' ')
        text = re.sub(r'\s+', ' ', post.get('content_fr') or '')[:52]
        print(f'{post.get("id", ""):>5}  {when:<17} {post.get("status", ""):<11} '
              f'{len(post.get("media") or []):>3}  {text}')


# --------------------------------------------------------------------------

def build_parser():
    parser = argparse.ArgumentParser(
        prog='rsmax.py',
        description='Publier sur les reseaux depuis son ordinateur, sans la compression de RS-Max.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            'Exemples :\n'
            '  rsmax.py config --profil dix --url https://rs.dix-10.com/api --token <jeton>\n'
            '  rsmax.py comptes\n'
            '  rsmax.py envoyer ~/Videos/septembre/ --dossier "Videos DIX"\n'
            '  rsmax.py publier --texte "8 g de citrulline 60 min avant la seance." \\\n'
            '      --comptes 23,21,29 --le "2026-10-08 18:00" --media ~/Videos/citrulline.mp4\n'
            '  rsmax.py etat --statut scheduled\n'
        ),
    )
    parser.add_argument('--profil', help='instance RS-Max (defaut : celle du fichier de configuration)')
    parser.add_argument('--dry-run', action='store_true', help='montre ce qui serait fait, sans rien envoyer')
    parser.add_argument('--json', action='store_true', help='sortie brute, pour enchainer un traitement')

    subparsers = parser.add_subparsers(dest='commande', required=True)

    subparsers.add_parser('comptes', help='les comptes et leurs identifiants').set_defaults(func=command_comptes)

    envoyer = subparsers.add_parser('envoyer', help='depose des fichiers sans retouche')
    envoyer.add_argument('fichiers', nargs='+', help='fichiers ou dossiers (un dossier est trie par nom)')
    envoyer.add_argument('--dossier', help='dossier de la mediatheque (cree au besoin)')
    envoyer.set_defaults(func=command_envoyer)

    publier = subparsers.add_parser('publier', help='cree une publication (programmee par defaut)')
    publier.add_argument('--texte', help='texte publie tel quel, sans passage par l\'IA')
    publier.add_argument('--texte-fichier', help='fichier contenant le texte')
    publier.add_argument('--comptes', required=True, help='identifiants cibles, ex : 23,21,29')
    publier.add_argument('--le', help='date de publication, ex : "2026-10-08 18:00" (fuseau du poste)')
    publier.add_argument('--media', nargs='+', help='fichiers ou dossiers a envoyer et attacher')
    publier.add_argument('--url-media', nargs='+', help='medias deja en place, ex : /media/2026xxxx.jpg')
    publier.add_argument('--dossier', help='dossier de la mediatheque pour les medias envoyes')
    publier.add_argument('--hashtags', help='hashtags, ex : "#dixsupps #citrulline"')
    publier.add_argument('--lien', help='URL associee a la publication')
    publier.add_argument('--brouillon', action='store_true', help='cree sans programmer')
    publier.add_argument('--maintenant', action='store_true', help='publie immediatement, sans attendre')
    publier.set_defaults(func=command_publier)

    etat = subparsers.add_parser('etat', help='les publications a venir, ou le detail de l\'une')
    etat.add_argument('--id', type=int, help='detail d\'une publication, reseau par reseau')
    etat.add_argument('--statut', default='scheduled',
                      choices=['draft', 'scheduled', 'publishing', 'published', 'failed', 'partial'])
    etat.add_argument('--limite', type=int, default=25, help='nombre de lignes (defaut 25)')
    etat.set_defaults(func=command_etat)

    config = subparsers.add_parser('config', help='enregistre une instance et son jeton')
    config.add_argument('--profil', required=True, dest='profil', help='nom court, ex : dix')
    config.add_argument('--url', help='base de l\'API, ex : https://rs.dix-10.com/api')
    config.add_argument('--token', help='jeton Sanctum (php artisan api:token)')
    config.add_argument('--defaut', action='store_true', help='en faire le profil par defaut')
    config.set_defaults(func=command_config)

    return parser


def main():
    parser = build_parser()
    args = parser.parse_args()

    # `config` declare son propre --profil : on ne le laisse pas ecraser celui
    # du parseur principal.
    try:
        args.func(args)
    except KeyboardInterrupt:
        print('\nInterrompu.', file=sys.stderr)
        return 130
    return 0


if __name__ == '__main__':
    sys.exit(main())
