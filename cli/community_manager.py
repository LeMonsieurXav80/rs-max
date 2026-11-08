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
import uuid
from urllib.parse import urlencode
from zoneinfo import ZoneInfo


def daytime(now=None):
    now = now or dt.datetime.now(ZoneInfo('Europe/Lisbon'))
    local = now.astimezone(ZoneInfo('Europe/Lisbon'))
    return 6 <= local.hour < 22


class ApiError(RuntimeError):
    def __init__(self, status, payload):
        self.status, self.payload = status, payload
        super().__init__('HTTP '+str(status)+': '+str(payload.get('message','requête refusée'))[:240])


class Client:
    def __init__(self, config):
        self.config = config
        db = Path(config.get('state_path', Path.home() / '.local/state/rsmax-community-manager.sqlite'))
        db.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(db)
        self.db.execute('CREATE TABLE IF NOT EXISTS attempts(id INTEGER PRIMARY KEY, state TEXT NOT NULL, updated_at TEXT NOT NULL)')
        self.db.execute('CREATE TABLE IF NOT EXISTS x_attempts(id INTEGER PRIMARY KEY, state TEXT NOT NULL, updated_at TEXT NOT NULL)')
        self.db.execute('CREATE TABLE IF NOT EXISTS reports(id TEXT PRIMARY KEY, state TEXT NOT NULL, updated_at TEXT NOT NULL)')
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
        try:
            result = json.loads(body)
        except ValueError:
            raise RuntimeError('HTTP ' + code + ': réponse non JSON. Consulter l’état enregistré, sans renvoyer une écriture.') from None
        if not 200 <= int(code) < 300:
            raise ApiError(int(code), result)
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


    def x_action(self, account, action, snapshot, commit=False, target=None, text=None, context=None):
        if not daytime():
            return {'state': 'sleep'}
        data = {'action': action, 'snapshot_id': snapshot}
        if target: data['target_id'] = target
        if action in ('reply', 'quote'):
            if not text or not context:
                raise RuntimeError('Lire x-context et fournir --context et --text-file. Aucun appel IA dans RS-Max.')
            data.update(text=text, context_id=context)
        prepared = self.api('POST', f'accounts/{account}/x/prepare', data)
        ident = prepared['id']
        if not commit or prepared['status'] != 'prepared':
            return prepared
        if self.db.execute('SELECT 1 FROM x_attempts WHERE id=?', (ident,)).fetchone():
            return {'id': ident, 'status': 'check_required', 'reason': 'Demande X déjà enregistrée : consulter x-status, sans renvoyer.'}
        self.db.execute('INSERT INTO x_attempts VALUES(?,?,?)', (ident, 'execution_requested', dt.datetime.now(dt.timezone.utc).isoformat()))
        self.db.commit()  # avant le HTTP, y compris en cas de coupure ou de délai dépassé
        self.api('POST', f'x/engagements/{ident}/execute', {'dry_run': False})
        deadline = time.monotonic() + 325
        while time.monotonic() < deadline:
            result = self.api('GET', f'x/engagements/{ident}')
            if result['status'] not in ('queued', 'running'):
                self.db.execute('UPDATE x_attempts SET state=?, updated_at=? WHERE id=?',
                    (result['status'], dt.datetime.now(dt.timezone.utc).isoformat(), ident))
                self.db.commit()
                return result
            time.sleep(5)
        return {'id': ident, 'status': 'check_required', 'reason': 'Délai local dépassé : consulter x-status, sans renvoyer.'}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=Path(os.environ.get('COMMUNITY_MANAGER_CONFIG', str(Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser() / 'community-manager.json'))))
    subs = parser.add_subparsers(dest='command', required=True)
    subs.add_parser('accounts')
    subs.add_parser('media-folders')
    p=subs.add_parser('media-search');p.add_argument('--folder',required=True);p.add_argument('--limit',type=int,default=10)
    p=subs.add_parser('photo-post');p.add_argument('--account',type=int,required=True);p.add_argument('--media',type=int,nargs='+',required=True);p.add_argument('--title',default='');p.add_argument('--caption',required=True);p.add_argument('--visibility',choices=['public','private'],required=True);p.add_argument('--request-key');p.add_argument('--rehearse',action='store_true');p.add_argument('--commit',action='store_true')
    p=subs.add_parser('photo-check');p.add_argument('id',type=int);p.add_argument('--commit',action='store_true')
    p=subs.add_parser('photo-status');p.add_argument('id',type=int)
    p = subs.add_parser('note'); p.add_argument('--text',required=True)
    p = subs.add_parser('logs'); p.add_argument('--account', type=int); p.add_argument('--platform'); p.add_argument('--status'); p.add_argument('--action'); p.add_argument('--limit',type=int,default=20); p.add_argument('--before',type=int)
    p = subs.add_parser('strategy'); p.add_argument('--account', type=int, required=True); p.add_argument('--update-file', type=Path)
    p = subs.add_parser('inbox'); p.add_argument('--account', type=int, required=True); p.add_argument('--pending',action='store_true'); p.add_argument('--limit',type=int,default=10); p.add_argument('--status', choices=['all','unanswered','replied','reply_failed'], default='unanswered')
    p = subs.add_parser('reply-status'); p.add_argument('id',type=int)
    p = subs.add_parser('verify-reply'); p.add_argument('id',type=int)
    p = subs.add_parser('reconcile-reply'); p.add_argument('id',type=int); proof=p.add_mutually_exclusive_group(required=True); proof.add_argument('--snapshot'); proof.add_argument('--verification-task',type=int); p.add_argument('--commit',action='store_true')
    p = subs.add_parser('review'); p.add_argument('id',type=int); p.add_argument('--reason',choices=['not_relevant','retry_later'],required=True)
    p = subs.add_parser('reply-context'); p.add_argument('id', type=int)
    p = subs.add_parser('reply'); p.add_argument('id', type=int); p.add_argument('--context', required=True); text=p.add_mutually_exclusive_group(required=True); text.add_argument('--text-file', type=Path); text.add_argument('--text'); p.add_argument('--commit', action='store_true')
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
    p = subs.add_parser('x-read'); p.add_argument('--account', type=int, required=True); p.add_argument('--action', choices=['feed.read','search.read','mentions.read','thread.read','profile.read'], required=True)
    p.add_argument('--query'); p.add_argument('--url'); p.add_argument('--handle'); p.add_argument('--feed', choices=['following','foryou']); p.add_argument('--limit', type=int, default=10); p.add_argument('--screens', type=int, default=2)
    p = subs.add_parser('x-context'); p.add_argument('--account', type=int, required=True); p.add_argument('--snapshot', required=True); p.add_argument('--purpose', choices=['reply','quote'], default='reply')
    p = subs.add_parser('x-action'); p.add_argument('--account', type=int, required=True); p.add_argument('--action', choices=['like','unlike','bookmark','unbookmark','repost','unrepost','reply','quote','follow','unfollow'], required=True)
    p.add_argument('--snapshot', required=True); p.add_argument('--target'); p.add_argument('--context'); p.add_argument('--text-file', type=Path); p.add_argument('--commit', action='store_true')
    p = subs.add_parser('x-status'); p.add_argument('id', type=int)
    p = subs.add_parser('x-contacts'); p.add_argument('--account', type=int, required=True); p.add_argument('--page', type=int, default=1)
    p = subs.add_parser('report'); p.add_argument('--hours', type=int, default=24); p.add_argument('--scheduled',action='store_true'); p.add_argument('--note-file', type=Path); p.add_argument('--note'); p.add_argument('--commit', action='store_true'); p.add_argument('--key')
    args = parser.parse_args(argv)
    if args.command not in ('accounts', 'note', 'logs', 'status', 'x-status', 'x-contacts', 'report', 'reply-status', 'photo-status') and not daytime():
        print(json.dumps({'state': 'sleep', 'window': '06:00-22:00 Europe/Lisbon'})); return 0
    client = Client(json.loads(args.config.read_text()))
    if args.command == 'accounts': result = client.api('GET', 'accounts')
    elif args.command == 'media-folders': result=client.api('GET','media/folders')
    elif args.command == 'media-search': result=client.api('GET','media/search?'+urlencode({'folder':args.folder,'limit':args.limit}))
    elif args.command == 'photo-check': result=client.api('POST',f'tiktok/photo-posts/{args.id}/verify',{'dry_run':not args.commit})
    elif args.command == 'photo-status': result=client.api('GET',f'tiktok/photo-posts/{args.id}')
    elif args.command == 'photo-post':
        if args.commit and not args.request_key: raise RuntimeError('Une request-key UUID stable est requise avant la demande ; la conserver après un timeout.')
        data={'media_ids':args.media,'title':args.title,'caption':args.caption,'visibility':args.visibility,'request_key':args.request_key,'dry_run':not args.commit,'rehearse':args.rehearse}
        result=client.api('POST',f'accounts/{args.account}/tiktok/photo-posts',data)
    elif args.command == 'note':
        home=Path(os.environ.get('HERMES_HOME','~/.hermes')).expanduser()
        (home/'workspace').mkdir(parents=True,exist_ok=True)
        (home/'workspace/last-x-cycle-note.txt').write_text(args.text[:600])
        result={'status':'saved','sent':False}
    elif args.command == 'logs':
        query={k:v for k,v in {'account_id':args.account,'platform':args.platform,'status':args.status,'action':args.action,'limit':args.limit,'before_id':args.before}.items() if v is not None}
        result=client.api('GET','logs?'+urlencode(query))
    elif args.command == 'report':
        note = args.note if args.note is not None else (args.note_file.read_text() if args.note_file else None)
        if args.commit and args.note is not None:
            home = Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser()
            (home/'workspace').mkdir(parents=True, exist_ok=True)
            (home/'workspace/last-x-cycle-note.txt').write_text(note[:600])
        if args.commit and client.config.get('report_schedule_only') and not args.scheduled:
            print(json.dumps({'status':'saved_for_scheduled_report','sent':False,'schedule':['08:00','20:00'],'timezone':'Europe/Lisbon'}))
            client.db.close()
            return 0
        key = args.key or str(uuid.uuid5(uuid.NAMESPACE_URL, client.config['base_url'] + '/report/' + dt.datetime.now(ZoneInfo('Europe/Lisbon')).date().isoformat() + '/' + str(args.hours) + '/' + (note or '')))
        if args.commit and client.db.execute('SELECT 1 FROM reports WHERE id=?', (key,)).fetchone():
            result = {'status':'check_required', 'report_key':key, 'reason':'Rapport déjà demandé : aucun renvoi.'}
        else:
            if args.commit:
                client.db.execute('INSERT INTO reports VALUES(?,?,?)', (key,'requested',dt.datetime.now(dt.timezone.utc).isoformat())); client.db.commit()
            result = client.api('POST', 'report', {'hours':args.hours, 'note':note, 'report_key':key, 'dry_run':not args.commit})
    elif args.command == 'x-read':
        data = {k: getattr(args, k) for k in ('action','query','url','handle','feed','limit','screens') if getattr(args,k) is not None}
        result = client.api('POST', f'accounts/{args.account}/x/read', data)
    elif args.command == 'x-context': result = client.api('GET', f'accounts/{args.account}/x/context?' + urlencode({'snapshot_id':args.snapshot, 'purpose':args.purpose}))
    elif args.command == 'x-action': result = client.x_action(args.account, args.action, args.snapshot, args.commit, args.target,
        args.text_file.read_text(encoding='utf-8').strip() if args.text_file else None, args.context)
    elif args.command == 'x-status': result = client.api('GET', f'x/engagements/{args.id}')
    elif args.command == 'x-contacts': result = client.api('GET', f'accounts/{args.account}/x/contacts?' + urlencode({'page':args.page}))
    elif args.command == 'strategy': result = client.api('PATCH' if args.update_file else 'GET', f'accounts/{args.account}/strategy', json.loads(args.update_file.read_text()) if args.update_file else None)
    elif args.command == 'inbox': result = client.api('GET', f'accounts/{args.account}/inbox?' + urlencode({'order':'newest','limit':args.limit,'status':args.status,**({'agent_queue':1} if args.pending else {})}))
    elif args.command == 'review': result = client.api('POST', f'inbox/{args.id}/review', {'reason':args.reason})
    elif args.command == 'reply-status': result = client.api('GET', f'inbox/{args.id}')
    elif args.command == 'verify-reply': result = client.api('POST', f'inbox/{args.id}/verify-reply', {})
    elif args.command == 'reconcile-reply': result = client.api('POST', f'inbox/{args.id}/reconcile', {**({'snapshot_id':args.snapshot} if args.snapshot else {'verification_task_id':args.verification_task}),'dry_run':not args.commit})
    elif args.command == 'reply-context':
        try: result = client.api('GET', f'inbox/{args.id}/context')
        except ApiError as exc:
            if exc.payload.get('skip_item'): result = exc.payload
            else: raise
    elif args.command == 'reply':
        reply_text = args.text_file.read_text().strip() if args.text_file else args.text.strip()
        if not reply_text: raise RuntimeError('Réponse vide : aucun envoi demandé.')
        key = -args.id  # espace distinct des engagements, intention avant HTTP
        if args.commit and client.db.execute('SELECT 1 FROM attempts WHERE id=?', (key,)).fetchone():
            result = {'status':'check_required','reason':'Réponse déjà demandée : consulter inbox, sans renvoi.'}
        else:
            if args.commit: client.remember(key, 'execution_requested')
            result = client.api('POST', f'inbox/{args.id}/reply', {'context_id':args.context, 'reply_text':reply_text, 'dry_run':not args.commit})
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
