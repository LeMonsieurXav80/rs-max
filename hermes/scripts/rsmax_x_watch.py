#!/usr/bin/env python3
"""Prélecture compacte des inbox API et navigateur : aucune écriture ni génération IA."""
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
    explorations = []
    for account in catalog.get('accounts', []):
        platform = account.get('platform')
        api = platform in ('facebook','instagram','threads','youtube')
        if api:
            if account.get('driver') != 'api' or not account.get('api_ready') or not account.get('persona'): continue
        elif platform not in ('twitter','tiktok') or account.get('driver') != 'browser' or account.get('session') != 'connected':
            continue
        tiktok = account['platform'] == 'tiktok'
        actions, cadence = account.get('actions', {}), account.get('cadence', {})
        auto_like = tiktok or platform == 'twitter' or (platform == 'facebook' and actions.get('comment.like', {}).get('left',0)>0)
        steps = 2 if auto_like else 1
        can_reply = actions.get('reply', {}).get('enabled', True) and actions.get('reply', {}).get('left', 0) > 0 and (api or tiktok or (actions.get('like', {}).get('enabled', True) and actions.get('like', {}).get('left', 0) > 0))
        if platform == 'facebook' and 'comment.like' in actions and actions['comment.like'].get('left', 0) < 1:
            can_reply = False  # conserver une place au like demandé après la réponse
        can_feed = platform == 'twitter' and actions.get('feed.read', {}).get('left', 0) > 0 and any(actions.get(k, {}).get('enabled', True) and actions.get(k, {}).get('left', 0) > 0 for k in ('like', 'bookmark'))
        threads_discovery = platform == 'threads' and account.get('session') == 'connected' and bool(account.get('strategy', {}).get('search_queries')) and all(actions.get(k, {}).get('left', 0) > 0 for k in ('search.read', 'thread.read')) and any(actions.get(k, {}).get('left', 0) > 0 for k in ('like', 'comment'))
        can_feed = can_feed or threads_discovery
        if (not can_reply and not can_feed) or cadence.get('blocked'):
            continue
        required = steps if can_reply else 1  # réponse et like, ou geste de flux
        upcoming = account.get('next_publication_at')
        if (not api or threads_discovery) and upcoming and now - dt.timedelta(minutes=5) <= dt.datetime.fromisoformat(upcoming).astimezone(dt.timezone.utc) <= now + dt.timedelta(minutes=5):
            continue  # laisser le navigateur libre autour de la publication
        if upcoming and dt.datetime.fromisoformat(upcoming).astimezone(dt.timezone.utc) <= now.astimezone(dt.timezone.utc) + dt.timedelta(hours=1):
            required += 1  # laisser une place à la publication planifiée
        daily_required = (steps if can_reply else 1) + (1 if upcoming else 0)
        if (cadence.get('remaining') is not None and cadence['remaining'] < required) or (cadence.get('daily', {}).get('remaining') is not None and cadence['daily']['remaining'] < daily_required):
            continue
        next_allowed = cadence.get('next_allowed_at')
        if next_allowed and dt.datetime.fromisoformat(next_allowed).astimezone(dt.timezone.utc) > now.astimezone(dt.timezone.utc):
            continue
        discovery_plan = None
        if threads_discovery:
            writes = [k for k in ('like', 'comment') if actions.get(k, {}).get('enabled', True) and actions.get(k, {}).get('left', 0) > 0]
            reserve = (1 if can_reply else 0) + (1 if upcoming else 0)
            needed = len(writes) + reserve
            enough = bool(writes) and all(value is None or value >= needed for value in (cadence.get('remaining'), cadence.get('daily', {}).get('remaining')))
            if enough:
                history = client.api('GET', 'logs?' + urlencode({'account_id': account['id'], 'action': 'agent.search.read', 'limit': 1}))
                last = next(iter(history.get('logs', [])), {}).get('at')
                due = not last or dt.datetime.fromisoformat(last).astimezone(dt.timezone.utc) <= now - dt.timedelta(hours=1)
                queries = [q.strip() for q in account['strategy']['search_queries'] if isinstance(q, str) and 0 < len(q.strip()) <= 150]
                if due and queries:
                    recent_targets = []
                    for action in ('threads.like', 'threads.comment'):
                        recent = client.api('GET', 'logs?' + urlencode({'account_id': account['id'], 'action': action, 'limit': 10}))
                        recent_targets.extend(log['target_url'] for log in recent.get('logs', []) if log.get('target_url'))
                    discovery_plan = {'account_id': account['id'], 'query': queries[(int(now.timestamp()) // 3600) % len(queries)],
                                      'recent_targets': sorted(set(recent_targets)), 'write_actions': writes, 'persona': account['persona'], 'reserve_for_inbox': can_reply,
                                      'reason': 'Exploration horaire due, indépendante du stock inbox.'}
            threads_discovery = discovery_plan is not None
            can_feed = threads_discovery
            if not can_reply and not can_feed:
                continue
        inbox = client.api('GET', f"accounts/{account['id']}/inbox?" + urlencode({'order':'newest','limit':5,'status':'unanswered','agent_queue':1})) if can_reply else {}
        items = [{'id':i['id'], 'content':i['content'], 'post_url':i.get('post_url'), 'post_content':i.get('post_content')} for i in inbox.get('items', []) if i.get('can_reply')]
        if items or can_feed:
            work.append({'platform':account['platform'], 'account_id':account['id'], 'strategy':account.get('strategy', {}), 'items':items,
                         'persona':account.get('persona'),'auto_like':auto_like,'executor':'api' if api else 'browser', 'threads_discovery':threads_discovery, 'discovery_plan':discovery_plan, 'remaining':cadence.get('remaining'), 'daily_remaining':cadence.get('daily', {}).get('remaining'), 'batch_max':min(1 if tiktok or api else 5, max(1, ((cadence.get('remaining') if cadence.get('remaining') is not None else 11) - (required - (steps if can_reply else 1)))//steps), max(1, ((cadence.get('daily', {}).get('remaining') if cadence.get('daily', {}).get('remaining') is not None else 11) - (1 if upcoming else 0))//steps)), 'next_publication_at':upcoming})
            if discovery_plan:
                explorations.append(discovery_plan)
    discovery_first=[a for a in work if a['threads_discovery']]
    browser_first=[a for a in work if a['platform']=='tiktok']
    other=[a for a in work if a['platform']!='tiktok' and not a['threads_discovery']]
    if any(a.get('executor')=='api' for a in other) and other:
        offset=(int(now.timestamp())//300)%len(other);other=other[offset:]+other[:offset]
    work=discovery_first+browser_first+other
    return {'state':'work','cycle_key':int(now.timestamp())//300,'accounts':work,'priority_threads':explorations} if work else {'state':'idle'}


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
