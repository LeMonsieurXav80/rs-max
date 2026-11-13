#!/usr/bin/env python3
"""Bilans RS-Max à 08 h et 20 h Lisbonne, sans modèle, une clé par créneau."""
import datetime as dt
import os
from pathlib import Path
import uuid
from zoneinfo import ZoneInfo
from community_manager import main


def report_arguments(now, note=None, cost=None):
    local = now.astimezone(ZoneInfo('Europe/Lisbon'))
    if local.hour not in (8, 20):
        return None
    slot = f'{local.hour:02d}'
    hours = 12
    key = uuid.uuid5(uuid.NAMESPACE_URL, 'rsmax:scheduled-report:'+local.date().isoformat()+':'+slot)
    args = ['report','--hours',str(hours),'--scheduled','--commit','--key',str(key)]
    if note and note.is_file():
        info = note.stat()
        if info.st_size <= 1500 and now.timestamp() - info.st_mtime < hours * 3600:
            args += ['--note-file',str(note)]
    if cost:
        args += ['--cost',cost[:400]]
    return args


if __name__ == '__main__':
    home = Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser()
    now = dt.datetime.now(dt.timezone.utc)
    cost = None
    if now.astimezone(ZoneInfo('Europe/Lisbon')).hour in (8, 20):
        # Coût mesuré dans Hermès, joint au bilan pour suivre l'effet des réglages (docs/hermes-couts-agent.md).
        try:
            import rsmax_cost_report
            cost = rsmax_cost_report.line(home, 12, now)
            if now.astimezone(ZoneInfo('Europe/Lisbon')).hour == 8:
                rsmax_cost_report.record_day(home, now.astimezone(ZoneInfo('Europe/Lisbon')).date() - dt.timedelta(days=1))
        except Exception:  # noqa: BLE001 — le bilan part même sans relevé de coût
            cost = None
    arguments = report_arguments(now, home/'workspace/last-x-cycle-note.txt', cost)
    raise SystemExit(main(arguments) if arguments else 0)
