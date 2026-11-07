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
        can_reply = actions.get('reply', {}).get('enabled', True) and actions.get('reply', {}).get('left', 0) > 0 and actions.get('like', {}).get('enabled', True) and actions.get('like', {}).get('left', 0) > 0
        can_feed = actions.get('feed.read', {}).get('left', 0) > 0 and any(actions.get(k, {}).get('enabled', True) and actions.get(k, {}).get('left', 0) > 0 for k in ('like', 'bookmark'))
        if (not can_reply and not can_feed) or cadence.get('blocked'):
            continue
        required = 2 if can_reply else 1  # réponse et like, ou geste de flux
        upcoming = account.get('next_publication_at')
        if upcoming and now - dt.timedelta(minutes=5) <= dt.datetime.fromisoformat(upcoming).astimezone(dt.timezone.utc) <= now + dt.timedelta(minutes=5):
            continue  # laisser le navigateur libre autour de la publication
        if upcoming and dt.datetime.fromisoformat(upcoming).astimezone(dt.timezone.utc) <= now.astimezone(dt.timezone.utc) + dt.timedelta(hours=1):
            required += 1  # laisser une place à la publication planifiée
        daily_required = (2 if can_reply else 1) + (1 if upcoming else 0)
        if (cadence.get('remaining') is not None and cadence['remaining'] < required) or (cadence.get('daily', {}).get('remaining') is not None and cadence['daily']['remaining'] < daily_required):
            continue
        next_allowed = cadence.get('next_allowed_at')
        if next_allowed and dt.datetime.fromisoformat(next_allowed).astimezone(dt.timezone.utc) > now.astimezone(dt.timezone.utc):
            continue
        inbox = client.api('GET', f"accounts/{account['id']}/inbox?" + urlencode({'order':'newest','limit':5,'status':'unanswered','agent_queue':1})) if can_reply else {}
        items = [{'id':i['id'], 'content':i['content'], 'post_url':i.get('post_url')} for i in inbox.get('items', []) if i.get('can_reply')]
        if items or can_feed:
            work.append({'account_id':account['id'], 'strategy':account.get('strategy', {}), 'items':items,
                         'persona':account.get('persona'), 'remaining':cadence.get('remaining'), 'daily_remaining':cadence.get('daily', {}).get('remaining'), 'batch_max':min(5, max(1, ((cadence.get('remaining') if cadence.get('remaining') is not None else 11) - (required - (2 if can_reply else 1)))//2), max(1, ((cadence.get('daily', {}).get('remaining') if cadence.get('daily', {}).get('remaining') is not None else 11) - (1 if upcoming else 0))//2)), 'next_publication_at':upcoming})
    return {'state':'work','cycle_key':int(now.timestamp())//300,'accounts':work} if work else {'state':'idle'}


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
