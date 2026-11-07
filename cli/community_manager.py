#!/usr/bin/env python3
"""Client compact de Community Manager. Identifiants hors code, bibliothèque standard."""
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import time
from urllib.parse import urlencode
from zoneinfo import ZoneInfo


def daytime(now=None):
    now = now or dt.datetime.now(ZoneInfo('Europe/Lisbon'))
    local = now.astimezone(ZoneInfo('Europe/Lisbon'))
    return 6 <= local.hour < 22


class Client:
    def __init__(self, config):
        self.config = config
        db = Path(config.get('state_path', Path.home() / '.local/state/rsmax-community-manager.sqlite'))
        db.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(db)
        self.db.execute('CREATE TABLE IF NOT EXISTS attempts(id INTEGER PRIMARY KEY, state TEXT NOT NULL, updated_at TEXT NOT NULL)')
        self.db.commit()

    def api(self, method, path, data=None):
        cmd = ['curl', '--silent', '--show-error', '--max-time', '125', '--config', '-', '-H', 'Accept: application/json', '-H', 'Content-Type: application/json', '-X', method]
        if data is not None:
            cmd += ['--data-binary', json.dumps(data)]
        cmd += ['--write-out', '\n%{http_code}', self.config['base_url'].rstrip('/') + '/api/community-manager/' + path]
        token = self.config['token']
        if any(c in token for c in '\r\n"\\'):
            raise ValueError('Configuration de jeton invalide')
        p = subprocess.run(cmd, input='header = "Authorization: Bearer ' + token + '"\n', capture_output=True, text=True)
        if p.returncode:
            raise RuntimeError('RS-Max injoignable : aucun renvoi automatique.')
        body, code = p.stdout.rsplit('\n', 1)
        result = json.loads(body)
        if not 200 <= int(code) < 300:
            raise RuntimeError('HTTP ' + code + ': ' + str(result.get('message', 'requête refusée'))[:240])
        return result

    def remember(self, ident, state):
        self.db.execute('INSERT INTO attempts VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET state=excluded.state,updated_at=excluded.updated_at',
                        (ident, state, dt.datetime.now(dt.timezone.utc).isoformat()))
        self.db.commit()

    def scan(self, account=None, limit=3):
        if not daytime():
            return {'state': 'sleep', 'window': '06:00-22:00 Europe/Lisbon'}
        catalog = self.api('GET', 'accounts')
        if not catalog.get('daytime'):
            return {'state': 'sleep'}
        candidates = [a for a in catalog['accounts'] if a['platform'] == 'tiktok' and (account is None or a['id'] == account)
                      and a['session'] == 'connected' and isinstance(a.get('actions'), dict)
                      and a['actions'].get('feed.read', {}).get('left', 0) > 0]
        if not candidates:
            return {'state': 'idle', 'reason': 'Aucun compte TikTok attribué avec session et quota de lecture.'}
        a = candidates[0]
        result = self.api('POST', 'read', {'account_id': a['id'], 'action': 'feed.read', 'limit': limit})
        return {'account_id': a['id'], 'persona': a['persona'], 'actions': a['actions'], **result}

    def action(self, account, action, snapshot, commit=False, comment_key=None, text=None, context=None):
        if not daytime():
            return {'state': 'sleep'}
        data = {'account_id': account, 'action': action, 'snapshot_id': snapshot}
        if comment_key:
            data['comment_key'] = comment_key
        if action == 'video.comment':
            if not text or not context:
                raise RuntimeError('Rédiger dans Hermès après comment-context, puis fournir --context et --text-file. Aucun appel IA dans RS-Max.')
            data.update(text=text, context_id=context)
        prepared = self.api('POST', 'prepare', data)
        ident = prepared['id']
        if not commit or prepared['status'] != 'prepared':
            return prepared
        if self.db.execute('SELECT 1 FROM attempts WHERE id=?', (ident,)).fetchone():
            return {'id': ident, 'status': 'check_required', 'reason': 'Exécution déjà demandée : consulter status, sans renvoyer.'}
        # Cette intention durable précède le HTTP : même une coupure réseau ne permet pas de doubler le geste.
        self.remember(ident, 'execution_requested')
        self.api('POST', f'engagements/{ident}/execute', {'dry_run': False})
        deadline = time.monotonic() + 325
        while time.monotonic() < deadline:
            result = self.api('GET', f'engagements/{ident}')
            if result['status'] not in ('queued', 'running'):
                self.remember(ident, result['status'])
                return result
            time.sleep(3)
        return {'id': ident, 'status': 'check_required', 'reason': 'Attente terminée : relire status, sans nouveau geste.'}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=Path(os.environ.get('COMMUNITY_MANAGER_CONFIG', str(Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser() / 'community-manager.json'))))
    subs = parser.add_subparsers(dest='command', required=True)
    subs.add_parser('accounts')
    scan = subs.add_parser('scan'); scan.add_argument('--account', type=int); scan.add_argument('--limit', type=int, choices=range(1, 6), default=3)
    for name in ['like', 'like-comment', 'comment']:
        p = subs.add_parser(name); p.add_argument('--account', type=int, required=True); p.add_argument('--snapshot', required=True); p.add_argument('--commit', action='store_true')
        if name == 'like-comment': p.add_argument('--comment-key', required=True)
        if name == 'comment':
            p.add_argument('--context', required=True); p.add_argument('--text-file', type=Path, required=True)
    p = subs.add_parser('comment-context'); p.add_argument('--account', type=int, required=True); p.add_argument('--snapshot', required=True)
    p = subs.add_parser('comments'); p.add_argument('--account', type=int, required=True); p.add_argument('--url', required=True)
    p = subs.add_parser('status'); p.add_argument('id', type=int)
    p = subs.add_parser('inspect-upload'); p.add_argument('--account', type=int, required=True)
    args = parser.parse_args(argv)
    if args.command not in ('accounts', 'status') and not daytime():
        print(json.dumps({'state': 'sleep', 'window': '06:00-22:00 Europe/Lisbon'})); return 0
    client = Client(json.loads(args.config.read_text()))
    if args.command == 'accounts': result = client.api('GET', 'accounts')
    elif args.command == 'scan': result = client.scan(args.account, args.limit)
    elif args.command in ('like', 'like-comment', 'comment'):
        action = {'like': 'video.like', 'like-comment': 'comment.like', 'comment': 'video.comment'}[args.command]
        text = args.text_file.read_text(encoding='utf-8').strip() if args.command == 'comment' else None
        result = client.action(args.account, action, args.snapshot, args.commit, getattr(args, 'comment_key', None), text, getattr(args, 'context', None))
    elif args.command == 'comment-context': result = client.api('GET', f'accounts/{args.account}/comment-context?' + urlencode({'snapshot_id': args.snapshot}))
    elif args.command == 'comments': result = client.api('POST', 'read', {'account_id': args.account, 'action': 'video.comments', 'url': args.url})
    elif args.command == 'status': result = client.api('GET', 'engagements/' + str(args.id))
    else: result = client.api('POST', 'upload/inspect', {'account_id': args.account})
    print(json.dumps(result, ensure_ascii=False, separators=(',', ':')))
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (RuntimeError, ValueError, OSError) as e:
        print(json.dumps({'error': str(e)}, ensure_ascii=False)); raise SystemExit(1)
