#!/usr/bin/env python3
"""Prélecture compacte des inbox API et navigateur : aucune écriture ni génération IA."""
import datetime as dt
import json
import os
import re
from pathlib import Path
from urllib.parse import urlencode
from community_manager import Client, daytime, pending_inbox_order

LANES = {
    'x': {'twitter'},
    'tiktok': {'tiktok'},
    'threads': {'threads'},
    'api': {'facebook', 'instagram', 'youtube'},
}
DISCOVERY_MINUTES = 15
TIKTOK_MAX_REPLIES = 10


def action_available(actions, name):
    action = actions.get(name, {})
    # Le catalogue compact ne renvoie que les actions activées.
    return action.get('enabled', True) and action.get('left', 0) > 0


def x_search_queries(strategy):
    queries = [q.strip() for q in strategy.get('search_queries', [])
               if isinstance(q, str) and 0 < len(q.strip()) <= 150]
    for value in strategy.get('hashtags', []):
        tag = value.strip().removeprefix('#') if isinstance(value, str) else ''
        if re.fullmatch(r'\w{1,149}', tag) and not tag.isdecimal():
            queries.append('#' + tag)
    excluded = {entry.strip()[1:].casefold() for entry in strategy.get('exclusions', [])
                if isinstance(entry, str) and re.fullmatch(r'@[A-Za-z0-9_]{1,15}', entry.strip())}
    for value in strategy.get('target_accounts', []):
        match = re.fullmatch(r'(?:https://(?:www\.)?(?:x|twitter)\.com/)?@?([A-Za-z0-9_]{1,15})/?',
                             value.strip(), re.IGNORECASE) if isinstance(value, str) else None
        if match and match[1].casefold() not in excluded:
            queries.append('from:' + match[1].lower())
    distinct = {}
    for query in queries:
        distinct.setdefault(query.casefold(), query)
    return list(distinct.values())


def discovery_source(client, account, now):
    actions = account['actions']
    strategy = account.get('strategy') or {}
    is_x = account['platform'] == 'twitter'
    queries = (x_search_queries(strategy) if is_x else
               [q.strip() for q in strategy.get('search_queries', [])
                if isinstance(q, str) and 0 < len(q.strip()) <= 150])
    sources = {}
    if action_available(actions, 'feed.read'):
        sources['feed.read'] = {}
    if queries and action_available(actions, 'search.read'):
        sources['search.read'] = {}
    if not sources:
        return None

    history = {}
    for action in ('feed.read', 'search.read'):
        response = client.api('GET', 'logs?' + urlencode({
            'account_id': account['id'], 'action': 'agent.' + action, 'limit': 1
        }))
        entry = next(iter(response.get('logs', [])), {})
        if entry.get('at'):
            history[action] = entry
    if history:
        previous = max(history, key=lambda action: dt.datetime.fromisoformat(history[action]['at']))
        if now - dt.datetime.fromisoformat(history[previous]['at']) < dt.timedelta(minutes=DISCOVERY_MINUTES):
            return None
        choice = next((action for action in sources if action != previous), previous)
    else:
        choice = next(iter(sources))

    if 'search.read' in sources:
        if is_x:
            last_query = (history.get('search.read', {}).get('text') or '').strip().casefold()
            index = next((i + 1 for i, query in enumerate(queries) if query.casefold() == last_query), 0)
        else:
            index = int(now.timestamp()) // (DISCOVERY_MINUTES * 60)
        sources['search.read'] = {'query': queries[index % len(queries)]}
    plan = {'read_action': choice, **sources[choice]}
    alternative = next((action for action in sources if action != choice), None)
    if alternative:
        plan['fallback'] = {'read_action': alternative, **sources[alternative]}
    elif is_x and choice == 'search.read' and len(queries) > 1:
        plan['fallback'] = {'read_action': 'search.read', 'query': queries[(index + 1) % len(queries)]}
    return plan


def snapshot(client, now=None, lane=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    if not daytime(now):
        return {'state': 'sleep'}
    catalog = client.api('GET', 'accounts')
    if not catalog.get('daytime'):
        return {'state': 'sleep'}
    work = []
    explorations = []
    x_explorations = []
    tiktok_explorations = []
    for account in catalog.get('accounts', []):
        platform = account.get('platform')
        if lane and platform not in LANES[lane]:
            continue
        api = platform in ('facebook','instagram','threads','youtube')
        if api:
            if account.get('driver') != 'api' or not account.get('api_ready') or not account.get('persona'): continue
        elif platform not in ('twitter','tiktok') or account.get('driver') != 'browser' or account.get('session') != 'connected':
            continue
        tiktok = account['platform'] == 'tiktok'
        actions, cadence = account.get('actions', {}), account.get('cadence', {})
        facebook_like = platform == 'facebook' and 'comment.like' in actions and actions['comment.like'].get('enabled', True)
        auto_like = tiktok or platform == 'twitter' or facebook_like
        steps = 2 if auto_like else 1
        can_reply = actions.get('reply', {}).get('enabled', True) and actions.get('reply', {}).get('left', 0) > 0 and (api or tiktok or (actions.get('like', {}).get('enabled', True) and actions.get('like', {}).get('left', 0) > 0))
        if facebook_like and not action_available(actions, 'comment.like'):
            can_reply = False  # conserver une place au like demandé après la réponse
        can_discover = (platform in ('twitter', 'threads') and account.get('session') == 'connected'
                        and account.get('persona') and action_available(actions, 'thread.read'))
        threads_discovery = can_discover and platform == 'threads' and any(action_available(actions, k) for k in ('like', 'comment'))
        x_writes = [action for action in ('reply', 'like') if action_available(actions, action)]
        x_discovery = can_discover and platform == 'twitter' and bool(x_writes)
        tiktok_writes = [k for k in ('video.comment', 'video.like', 'comment.like') if action_available(actions, k)]
        if not action_available(actions, 'video.comments'):
            tiktok_writes = [k for k in tiktok_writes if k == 'video.like']
        tiktok_discovery = lane == 'tiktok' and account.get('persona') and action_available(actions, 'feed.read') and tiktok_writes
        can_feed = threads_discovery or x_discovery or tiktok_discovery
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
            writes = [k for k in ('like', 'comment') if action_available(actions, k)]
            reserve = (1 if can_reply else 0) + (1 if upcoming else 0)
            needed = len(writes) + reserve
            enough = bool(writes) and all(value is None or value >= needed for value in (cadence.get('remaining'), cadence.get('daily', {}).get('remaining')))
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
            source = discovery_source(client, account, now) if not discovery_plan and enough else None
            if source:
                discovery_plan = {'account_id': account['id'], 'mode': 'explore', **source,
                                  'recent_targets': recent_targets, 'write_actions': writes, 'persona': account['persona'], 'reserve_for_inbox': can_reply,
                                  'reason': 'Exploration horaire due, indépendante du stock inbox.'}
            threads_discovery = discovery_plan is not None
            can_feed = threads_discovery
            if not can_reply and not can_feed:
                continue
        if x_discovery:
            reserve = (steps if can_reply else 0) + int(bool(upcoming))
            enough = all(value is None or value >= 1 + reserve for value in (cadence.get('remaining'), cadence.get('daily', {}).get('remaining')))
            source = discovery_source(client, account, now) if enough else None
            can_feed = source is not None
            if source:
                x_explorations.append({'account_id': account['id'], **source, 'write_actions': x_writes,
                                       'reserve_for_inbox': can_reply})
        if tiktok_discovery:
            reserve = (steps if can_reply else 0) + int(bool(upcoming))
            enough = all(value is None or value >= 1 + reserve for value in (cadence.get('remaining'), cadence.get('daily', {}).get('remaining')))
            if enough:
                source = discovery_source(client, account, now)
                if source:
                    tiktok_explorations.append({'account_id': account['id'], **source, 'write_actions': tiktok_writes})
        inbox = client.api('GET', f"accounts/{account['id']}/inbox?" + urlencode({'order':pending_inbox_order(platform),'limit':20 if platform in ('twitter', 'tiktok') else 5,'status':'unanswered','agent_queue':1})) if can_reply else {}
        items = [{'id':i['id'], 'content':i['content'], 'posted_at':i.get('posted_at'), 'post_url':i.get('post_url'), 'post_content':i.get('post_content')} for i in inbox.get('items', []) if i.get('can_reply')]
        if api and lane:
            items = [{'id': item['id']} for item in items]
        if items or can_feed:
            reply_limit = min(actions['reply']['left'], actions['like']['left']) if platform == 'twitter' and items else (1 if api or tiktok else 0)
            if api and items and lane:
                reply_limit = min(5, len(items), actions['reply']['left'])
                if facebook_like:
                    reply_limit = min(reply_limit, actions['comment.like']['left'])
            if tiktok and lane:
                reply_limit = min(TIKTOK_MAX_REPLIES, len(items), actions.get('reply', {}).get('left', 0))
            work.append({'platform':account['platform'], 'account_id':account['id'], 'strategy':account.get('strategy', {}), 'items':items,
                         'persona':account.get('persona'),'auto_like':auto_like,'executor':'api' if api else 'browser', 'threads_discovery':threads_discovery, 'discovery_plan':discovery_plan, 'remaining':cadence.get('remaining'), 'daily_remaining':cadence.get('daily', {}).get('remaining'), 'batch_max':min(reply_limit, max(1, ((cadence.get('remaining') if cadence.get('remaining') is not None else 11) - (required - (steps if can_reply else 1)))//steps), max(1, ((cadence.get('daily', {}).get('remaining') if cadence.get('daily', {}).get('remaining') is not None else 11) - (1 if upcoming else 0))//steps)), 'next_publication_at':upcoming})
            if discovery_plan:
                explorations.append(discovery_plan)
    discovery_first=[a for a in work if a['threads_discovery']]
    browser_first=[a for a in work if a['platform']=='tiktok']
    other=[a for a in work if a['platform']!='tiktok' and not a['threads_discovery']]
    if any(a.get('executor')=='api' for a in other) and other:
        offset=(int(now.timestamp())//300)%len(other);other=other[offset:]+other[:offset]
    work=discovery_first+browser_first+other
    if lane:
        work = [a for a in work if a['items'] or a['discovery_plan'] or any(p['account_id'] == a['account_id'] for p in x_explorations + tiktok_explorations)]
    cycle_seconds = 120 if lane == 'x' else 300
    return {'state':'work','cycle_key':int(now.timestamp())//cycle_seconds,'accounts':work,'priority_threads':explorations,'priority_x':x_explorations,'priority_tiktok':tiktok_explorations} if work or x_explorations or tiktok_explorations else {'state':'idle'}


def main(lane=None):
    home = Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser()
    client = Client(json.loads((home/'community-manager.json').read_text()))
    try:
        print(json.dumps(snapshot(client, lane=lane), ensure_ascii=False, sort_keys=True, separators=(',', ':')))
    except (RuntimeError, ValueError, OSError):
        print('{"state":"unreachable","instruction":"Ne pas agir ni répéter une écriture ; vérifier RS-Max."}')
    finally:
        client.db.close()


if __name__ == '__main__':
    main('x')
