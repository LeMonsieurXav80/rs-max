#!/usr/bin/env python3
"""Bilan matinal via le bot RS-Max configuré, sans appel de modèle."""
import datetime as dt
import os
from pathlib import Path
import uuid
from zoneinfo import ZoneInfo
from community_manager import main

home = Path(os.environ.get('HERMES_HOME', '~/.hermes')).expanduser()
date = dt.datetime.now(ZoneInfo('Europe/Lisbon')).date().isoformat()
args = ['report','--hours','24','--commit','--key',str(uuid.uuid5(uuid.NAMESPACE_URL, 'rsmax:morning:'+date))]
note = home/'workspace/last-x-cycle-note.txt'
if note.is_file() and note.stat().st_size <= 1500:
    args += ['--note-file',str(note)]
if __name__ == '__main__':
    raise SystemExit(main(args))
