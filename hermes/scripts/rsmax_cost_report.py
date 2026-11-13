#!/usr/bin/env python3
"""Relevé du coût en jetons du Community Manager, en lecture seule. Voir docs/hermes-couts-agent.md.

Sources du profil Hermès : cron/usage_audit.jsonl (un réveil du modèle par ligne, jetons d'entrée
cache compris), state.db (appels et cache par session, ouvert en lecture seule) et le bilan RS-Max
en dry-run pour les gestes confirmés.

    python3 scripts/rsmax_cost_report.py              # 7 derniers jours, par jour et par file
    python3 scripts/rsmax_cost_report.py --days 14 --json
    python3 scripts/rsmax_cost_report.py --history    # journal quotidien conservé (gestes compris)
"""
import argparse
import collections
import datetime as dt
import json
import os
from pathlib import Path
import sqlite3
from zoneinfo import ZoneInfo

LISBON = ZoneInfo('Europe/Lisbon')
HISTORY = 'workspace/cost-history.jsonl'


def parse_ts(value):
    return dt.datetime.fromisoformat(value.replace('Z', '+00:00'))


def job_names(home):
    try:
        data = json.loads((home / 'cron/jobs.json').read_text())
    except (OSError, ValueError):
        return {}
    return {j['id']: j.get('name', j['id']) for j in (data.get('jobs', data) if isinstance(data, dict) else data)}


def wakes(home, since, until):
    out = []
    try:
        lines = (home / 'cron/usage_audit.jsonl').read_text().splitlines()
    except OSError:
        return out
    for line in lines:
        try:
            row = json.loads(line)
            at = parse_ts(row['ts'])
        except (ValueError, KeyError):
            continue
        if since <= at < until:
            out.append({**row, 'at': at})
    return out


def sessions(home, since, until):
    """Appels et cache par session cron ; la base vive est ouverte en lecture seule."""
    try:
        db = sqlite3.connect(f"file:{home / 'state.db'}?mode=ro", uri=True, timeout=10)
        rows = db.execute("""SELECT started_at, input_tokens, cache_read_tokens, api_call_count FROM sessions
                             WHERE source='cron' AND started_at >= ? AND started_at < ? AND api_call_count > 0""",
                          (since.timestamp(), until.timestamp())).fetchall()
        db.close()
    except sqlite3.Error:
        return {}
    return {(round(r[0]), (r[1] or 0) + (r[2] or 0)): {'cache': r[2] or 0, 'calls': r[3] or 0} for r in rows}


def fires(home, since, until):
    """Déclenchements du cron (réveils du modèle + réveils évités par la prélecture), si encore conservés."""
    try:
        db = sqlite3.connect(f"file:{home / 'cron/executions.db'}?mode=ro", uri=True, timeout=10)
        first = db.execute('SELECT MIN(claimed_at) FROM executions').fetchone()[0]
        rows = db.execute('SELECT job_id, COUNT(*) FROM executions WHERE claimed_at >= ? AND claimed_at < ? GROUP BY job_id',
                          (since.isoformat(), until.isoformat())).fetchall()
        db.close()
    except sqlite3.Error:
        return None
    if not first or parse_ts(first) > since:
        return None  # rétention trop courte pour la période : ne rien inventer
    return dict(rows)


def summarize(home, since, until):
    names = job_names(home)
    by_session = sessions(home, since, until)
    fired = fires(home, since, until)
    jobs = collections.defaultdict(lambda: collections.Counter())
    for w in wakes(home, since, until):
        j = jobs[names.get(w['job_id'], w['job_id'])]
        j['wakes'] += 1
        j['silent'] += bool(w.get('response_silent'))
        j['errors'] += bool(w.get('error'))
        j['prompt'] += w.get('prompt_tokens') or 0
        j['completion'] += w.get('completion_tokens') or 0
        start = w['at'].timestamp() - (w.get('duration_ms') or 0) / 1000
        match = next((by_session[(round(start) + d, w.get('prompt_tokens') or 0)] for d in range(-20, 21)
                      if (round(start) + d, w.get('prompt_tokens') or 0) in by_session), None)
        if match:
            j['cache'] += match['cache']
            j['calls'] += match['calls']
            j['matched'] += 1
    if fired is not None:
        for job_id, count in fired.items():
            name = names.get(job_id, job_id)
            if name in jobs or count:
                jobs[name]['fires'] = count
    return {name: dict(c) for name, c in jobs.items() if c.get('wakes') or c.get('fires')}


def confirmed_gestures(home, hours):
    """Gestes confirmés dans RS-Max sur la période, par le bilan en dry-run (aucun envoi)."""
    import sys
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from community_manager import Client
    client = Client(json.loads((home / 'community-manager.json').read_text()))
    try:
        report = client.api('POST', 'report', {'hours': max(1, min(48, hours)), 'dry_run': True})
    finally:
        client.db.close()
    return sum(a.get('confirmed', 0) for a in report.get('accounts', []))


def totals(jobs):
    t = collections.Counter()
    for j in jobs.values():
        t.update(j)
    return dict(t)


def fmt_tokens(n):
    return f'{n / 1e6:.1f} M' if n >= 1e6 else f'{n / 1e3:.0f} k'


def line(home, hours, now=None):
    """Résumé d'une ligne pour le bilan Telegram, sur la même période que lui."""
    now = now or dt.datetime.now(dt.timezone.utc)
    t = totals(summarize(home, now - dt.timedelta(hours=hours), now))
    if not t.get('wakes'):
        return f'{hours} h : aucun réveil du modèle.'
    tokens = t['prompt'] + t['completion']
    parts = [f"{hours} h : {t['wakes']} réveils"]
    if t.get('fires'):
        parts[0] += f" sur {t['fires']} créneaux"
    parts.append(f"{fmt_tokens(tokens)} jetons ({fmt_tokens(tokens // t['wakes'])}/réveil"
                 + (f", {100 * t['cache'] // max(1, t['prompt']):d} % en cache)" if t.get('matched') else ')'))
    try:
        gestures = confirmed_gestures(home, hours)
        if gestures:
            parts.append(f'{gestures} gestes, {fmt_tokens(tokens // gestures)} jetons/geste')
    except Exception:  # noqa: BLE001 — le bilan part même si RS-Max ne répond pas ici
        pass
    return ' · '.join(parts)


def day_bounds(day):
    start = dt.datetime.combine(day, dt.time(), LISBON)
    return start.astimezone(dt.timezone.utc), (start + dt.timedelta(days=1)).astimezone(dt.timezone.utc)


def record_day(home, day):
    """Ajoute la journée (Lisbonne) au journal durable, une seule fois ; les gestes viennent du bilan 48 h."""
    path = home / HISTORY
    path.parent.mkdir(parents=True, exist_ok=True)
    known = {json.loads(l).get('day') for l in path.read_text().splitlines() if l.strip()} if path.is_file() else set()
    if day.isoformat() in known:
        return False
    since, until = day_bounds(day)
    jobs = summarize(home, since, until)
    entry = {'day': day.isoformat(), 'jobs': jobs, 'total': totals(jobs)}
    now = dt.datetime.now(dt.timezone.utc)
    if now - until < dt.timedelta(hours=24):  # bilan RS-Max glissant sur 48 h au plus
        try:
            entry['confirmed_gestures'] = confirmed_gestures(home, round((now - since).total_seconds() / 3600)) - \
                                          confirmed_gestures(home, max(1, round((now - until).total_seconds() / 3600)))
        except Exception:  # noqa: BLE001
            pass
    with path.open('a') as f:
        f.write(json.dumps(entry, ensure_ascii=False, sort_keys=True) + '\n')
    return True


def table(rows):
    head = f"{'jour':10} {'file':24} {'créneaux':>8} {'réveils':>7} {'silenc.':>7} {'appels/r':>8} {'jetons':>9} {'k/réveil':>8} {'cache':>6}"
    print(head)
    for day, name, j in rows:
        tokens = j.get('prompt', 0) + j.get('completion', 0)
        wakes_ = j.get('wakes', 0)
        calls = f"{j['calls'] / j['matched']:.1f}" if j.get('matched') else '-'
        cache = f"{100 * j['cache'] // max(1, j['prompt'])} %" if j.get('matched') else '-'
        print(f"{day:10} {name[:24]:24} {j.get('fires', '-')!s:>8} {wakes_:>7} {j.get('silent', 0):>7} {calls:>8} "
              f"{fmt_tokens(tokens):>9} {(tokens // wakes_ // 1000) if wakes_ else 0:>8} {cache:>6}")


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument('--days', type=int, default=7)
    p.add_argument('--json', action='store_true')
    p.add_argument('--history', action='store_true', help='afficher le journal quotidien conservé')
    args = p.parse_args(argv)
    home = Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser()
    if args.history:
        path = home / HISTORY
        entries = [json.loads(l) for l in path.read_text().splitlines() if l.strip()] if path.is_file() else []
        if args.json:
            print(json.dumps(entries, ensure_ascii=False))
            return 0
        for e in entries:
            t = e['total']
            tokens = t.get('prompt', 0) + t.get('completion', 0)
            g = e.get('confirmed_gestures')
            print(f"{e['day']}  {t.get('wakes', 0):4d} réveils  {fmt_tokens(tokens):>8}  "
                  f"{fmt_tokens(tokens // max(1, t.get('wakes', 0))):>6}/réveil  "
                  + (f"{g} gestes  {fmt_tokens(tokens // g)}/geste" if g else 'gestes : -'))
        return 0
    today = dt.datetime.now(LISBON).date()
    rows, data = [], {}
    for offset in range(args.days - 1, -1, -1):
        day = today - dt.timedelta(days=offset)
        since, until = day_bounds(day)
        jobs = summarize(home, since, min(until, dt.datetime.now(dt.timezone.utc)))
        data[day.isoformat()] = jobs
        for name, j in sorted(jobs.items()):
            rows.append((day.isoformat(), name.replace('RS-Max — ', ''), j))
        if jobs:
            rows.append((day.isoformat(), 'TOTAL', totals(jobs)))
    if args.json:
        print(json.dumps(data, ensure_ascii=False))
    else:
        table(rows)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
