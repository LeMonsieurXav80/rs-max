#!/usr/bin/env python3
"""Bilans RS-Max à 08 h et 20 h Lisbonne, sans modèle, une clé par créneau."""
import datetime as dt
import os
from pathlib import Path
import uuid
from zoneinfo import ZoneInfo
from community_manager import main


def report_arguments(now, note=None):
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
    return args


if __name__ == '__main__':
    home = Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser()
    arguments = report_arguments(dt.datetime.now(dt.timezone.utc), home/'workspace/last-x-cycle-note.txt')
    raise SystemExit(main(arguments) if arguments else 0)
