#!/usr/bin/env python3
"""Prélecture compacte pour le cycle X : aucune écriture ni génération IA."""
import datetime as dt
import json
import os
from pathlib import Path
from urllib.parse import urlencode
from community_manager import Client, daytime


def snapshot(client, now=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    if not daytime(now):
        return {'state': 'sleep'}
    catalog = client.api('GET', 'accounts')
    if not catalog.get('daytime'):
        return {'state': 'sleep'}
    work = []
    for account in catalog.get('accounts', []):
        if account.get('platform') != 'twitter' or account.get('driver') != 'browser' or account.get('session') != 'connected':
            continue
        actions, cadence = account.get('actions', {}), account.get('cadence', {})
        if actions.get('reply', {}).get('left', 0) < 1 or actions.get('like', {}).get('left', 0) < 1 or cadence.get('blocked'):
            continue
        required = 2  # réponse + like automatique
        upcoming = account.get('next_publication_at')
        if upcoming and dt.datetime.fromisoformat(upcoming).astimezone(dt.timezone.utc) <= now.astimezone(dt.timezone.utc) + dt.timedelta(hours=1):
            required += 1  # laisser une place à la publication planifiée
        if cadence.get('remaining') is not None and cadence['remaining'] < required:
            continue
        next_allowed = cadence.get('next_allowed_at')
        if next_allowed and dt.datetime.fromisoformat(next_allowed).astimezone(dt.timezone.utc) > now.astimezone(dt.timezone.utc):
            continue
        inbox = client.api('GET', f"accounts/{account['id']}/inbox?" + urlencode({'order':'newest','limit':3,'status':'unanswered'}))
        items = [{'id':i['id'], 'content':i['content'], 'post_url':i.get('post_url')} for i in inbox.get('items', []) if i.get('can_reply')]
        if items or actions.get('feed.read', {}).get('left', 0) > 0:
            work.append({'account_id':account['id'], 'strategy':account.get('strategy', {}), 'items':items,
                         'persona':account.get('persona'), 'remaining':cadence.get('remaining'), 'next_publication_at':upcoming})
    return {'state':'work','accounts':work} if work else {'state':'idle'}


def main():
    home = Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser()
    client = Client(json.loads((home/'community-manager.json').read_text()))
    try:
        print(json.dumps(snapshot(client), ensure_ascii=False, sort_keys=True, separators=(',', ':')))
    except (RuntimeError, ValueError, OSError):
        print('{"state":"unreachable","instruction":"Ne pas agir ni répéter une écriture ; vérifier RS-Max."}')
    finally:
        client.db.close()


if __name__ == '__main__':
    main()
