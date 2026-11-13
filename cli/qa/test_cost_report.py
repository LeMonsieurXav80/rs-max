import datetime as dt
import importlib.util
import json
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'cli'))  # community_manager, importé par le relevé
spec = importlib.util.spec_from_file_location('rsmax_cost_report', ROOT / 'hermes/scripts/rsmax_cost_report.py')
cost = importlib.util.module_from_spec(spec); spec.loader.exec_module(cost)


def profile(tmp, wakes):
    home = Path(tmp)
    (home / 'cron').mkdir()
    (home / 'cron/jobs.json').write_text(json.dumps({'jobs': [{'id': 'aaa', 'name': 'RS-Max — File X'}, {'id': 'bbb', 'name': 'RS-Max — File API'}]}))
    db = sqlite3.connect(home / 'state.db')
    db.execute('CREATE TABLE sessions(id TEXT, source TEXT, started_at REAL, input_tokens INT, cache_read_tokens INT, api_call_count INT)')
    lines = []
    for i, (job, at, prompt, silent) in enumerate(wakes):
        end = dt.datetime.fromisoformat(at)
        lines.append(json.dumps({'ts': end.isoformat().replace('+00:00', 'Z'), 'job_id': job, 'prompt_tokens': prompt,
                                 'completion_tokens': 100, 'response_silent': silent, 'duration_ms': 10000, 'error': None}))
        db.execute('INSERT INTO sessions VALUES(?,?,?,?,?,?)', (f's{i}', 'cron', end.timestamp() - 10, prompt // 4, prompt - prompt // 4, 3))
    db.commit(); db.close()
    (home / 'cron/usage_audit.jsonl').write_text('\n'.join(lines) + '\n')
    return home


class CostReportTest(unittest.TestCase):
    def test_agrege_les_reveils_par_file_avec_cache_et_appels(self):
        with tempfile.TemporaryDirectory() as tmp:
            home = profile(tmp, [('aaa', '2026-11-13T09:00:00+00:00', 40000, False), ('aaa', '2026-11-13T09:02:00+00:00', 20000, True),
                                 ('bbb', '2026-11-13T09:05:00+00:00', 10000, False), ('aaa', '2026-11-12T09:00:00+00:00', 99999, False)])
            jobs = cost.summarize(home, dt.datetime.fromisoformat('2026-11-13T00:00:00+00:00'), dt.datetime.fromisoformat('2026-11-14T00:00:00+00:00'))
            x = jobs['RS-Max — File X']
            self.assertEqual((x['wakes'], x['silent'], x['prompt'], x['completion']), (2, 1, 60000, 200))
            self.assertEqual((x['matched'], x['calls'], x['cache']), (2, 6, 45000))
            self.assertEqual(jobs['RS-Max — File API']['wakes'], 1)
            self.assertNotIn('fires', x)  # aucune trace d'exécution : ne pas inventer les réveils évités

    def test_ligne_du_bilan_et_journal_quotidien_unique(self):
        with tempfile.TemporaryDirectory() as tmp:
            home = profile(tmp, [('aaa', '2026-11-13T09:00:00+00:00', 2000000, False)])
            now = dt.datetime.fromisoformat('2026-11-13T12:00:00+00:00')
            with patch.object(cost, 'confirmed_gestures', return_value=4):
                text = cost.line(home, 12, now)
            self.assertIn('1 réveils', text); self.assertIn('2.0 M jetons', text); self.assertIn('75 % en cache', text)
            self.assertIn('4 gestes, 500 k jetons/geste', text)
            with patch.object(cost, 'confirmed_gestures', side_effect=RuntimeError('RS-Max injoignable')):
                self.assertNotIn('gestes', cost.line(home, 12, now))
            day = dt.date(2026, 11, 13)
            with patch.object(cost, 'confirmed_gestures', return_value=0):
                self.assertTrue(cost.record_day(home, day))
                self.assertFalse(cost.record_day(home, day))
            entries = (home / cost.HISTORY).read_text().splitlines()
            self.assertEqual(len(entries), 1)
            self.assertEqual(json.loads(entries[0])['total']['wakes'], 1)

    def test_le_bilan_joint_le_cout_mesure(self):
        spec = importlib.util.spec_from_file_location('scheduled_report', ROOT / 'hermes/scripts/rsmax_morning_report.py')
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        args = module.report_arguments(dt.datetime.fromisoformat('2026-11-13T08:00:00+00:00'), cost='12 h : 3 réveils')
        self.assertEqual(args[args.index('--cost') + 1], '12 h : 3 réveils')
        self.assertNotIn('--cost', module.report_arguments(dt.datetime.fromisoformat('2026-11-13T08:00:00+00:00')))

    def test_les_skills_de_file_generees_sont_a_jour_et_compactes(self):
        done = subprocess.run([sys.executable, str(ROOT / 'hermes/build_cycles.py'), '--check'], capture_output=True, text=True)
        self.assertEqual(done.returncode, 0, done.stderr)


if __name__ == '__main__':
    unittest.main()
