#!/usr/bin/env python3
"""Prélecture compacte des inbox API et navigateur : aucune écriture ni génération IA."""
import datetime as dt
import json
import os
from pathlib import Path
from urllib.parse import urlencode
from community_manager import Client, daytime, pending_inbox_order

TIKTOK_MAX_REPLIES = 10
TIKTOK_MAX_CANDIDATES = 20


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
        threads_discovery = platform == 'threads' and account.get('session') == 'connected' and bool(account.get('strategy', {}).get('search_queries')) and actions.get('thread.read', {}).get('left', 0) > 0 and any(actions.get(k, {}).get('left', 0) > 0 for k in ('like', 'comment'))
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
            history = client.api('GET', 'logs?' + urlencode({'account_id': account['id'], 'action': 'agent.search.read', 'limit': 1}))
            last = next(iter(history.get('logs', [])), {}).get('at')
            due = not last or dt.datetime.fromisoformat(last).astimezone(dt.timezone.utc) <= now - dt.timedelta(hours=1)
            recent = {}
            for action in ('like', 'comment'):
                recent[action] = client.api('GET', 'logs?' + urlencode({'account_id': account['id'], 'action': 'threads.' + action, 'limit': 10})).get('logs', [])
            recent_targets = sorted({log['target_url'] for logs in recent.values() for log in logs if log.get('target_url')})
            liked_targets = {log['target_url'] for log in recent['like'] if log.get('target_url')}
            unfinished = [log for log in recent['comment'] if log.get('status') == 'confirmed' and isinstance(log.get('proof'), dict) and log['proof'].get('confirmed') is True
                          and log.get('target_url') and log['target_url'] not in liked_targets and log.get('at')
                          and dt.datetime.fromisoformat(log['at']).astimezone(dt.timezone.utc) >= now - dt.timedelta(hours=1)]
            can_finish = 'like' in writes and all(value is None or value >= 1 + reserve for value in (cadence.get('remaining'), cadence.get('daily', {}).get('remaining')))
            if unfinished and can_finish:
                discovery_plan = {'account_id': account['id'], 'mode': 'finish_like', 'target_url': unfinished[0]['target_url'],
                                  'write_actions': ['like'], 'persona': account['persona'], 'reserve_for_inbox': can_reply,
                                  'reason': 'Commentaire NAS confirmé sans tentative de like ; relire la cible, aucun nouveau commentaire.'}
            queries = [q.strip() for q in account['strategy']['search_queries'] if isinstance(q, str) and 0 < len(q.strip()) <= 150]
            if not discovery_plan and enough and due and queries and actions.get('search.read', {}).get('left', 0) > 0:
                discovery_plan = {'account_id': account['id'], 'mode': 'explore', 'query': queries[(int(now.timestamp()) // 3600) % len(queries)],
                                  'recent_targets': recent_targets, 'write_actions': writes, 'persona': account['persona'], 'reserve_for_inbox': can_reply,
                                  'reason': 'Exploration horaire due, indépendante du stock inbox.'}
            threads_discovery = discovery_plan is not None
            can_feed = threads_discovery
            if not can_reply and not can_feed:
                continue
        inbox = client.api('GET', f"accounts/{account['id']}/inbox?" + urlencode({'order':pending_inbox_order(platform),'limit':TIKTOK_MAX_CANDIDATES if tiktok else 5,'status':'unanswered','agent_queue':1})) if can_reply else {}
        items = [{'id':i['id'], 'content':i['content'], 'posted_at':i.get('posted_at'), 'post_url':i.get('post_url'), 'post_content':i.get('post_content')} for i in inbox.get('items', []) if i.get('can_reply')]
        if items or can_feed:
            hourly = cadence.get('remaining')
            daily = cadence.get('daily', {}).get('remaining')
            hourly_reserve = required - (steps if can_reply else 1)
            daily_reserve = int(bool(upcoming))
            if tiktok:
                # Le plafond de réponses et les deux gestes par réponse sont distincts.
                batch_max = min(TIKTOK_MAX_REPLIES, len(items), actions['reply']['left'])
                if hourly is not None:
                    batch_max = min(batch_max, (hourly - hourly_reserve) // steps)
                if daily is not None:
                    batch_max = min(batch_max, (daily - daily_reserve) // steps)
            else:
                batch_max = min(1 if api else 5,
                                max(1, ((hourly if hourly is not None else 11) - hourly_reserve) // steps),
                                max(1, ((daily if daily is not None else 11) - daily_reserve) // steps))
            work.append({'platform':account['platform'], 'account_id':account['id'], 'strategy':account.get('strategy', {}), 'items':items,
                         'persona':account.get('persona'),'auto_like':auto_like,'executor':'api' if api else 'browser', 'threads_discovery':threads_discovery, 'discovery_plan':discovery_plan, 'remaining':cadence.get('remaining'), 'daily_remaining':cadence.get('daily', {}).get('remaining'), 'batch_max':batch_max, 'next_publication_at':upcoming})
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
