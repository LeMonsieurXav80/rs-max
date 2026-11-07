import datetime as dt
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('community_manager', Path(__file__).resolve().parents[1] / 'community_manager.py')
cm = importlib.util.module_from_spec(spec); spec.loader.exec_module(cm)

class CommunityManagerTest(unittest.TestCase):
    def test_night_and_summer_boundary(self):
        self.assertTrue(cm.daytime(dt.datetime.fromisoformat('2026-07-07T20:59:00+00:00')))
        self.assertFalse(cm.daytime(dt.datetime.fromisoformat('2026-07-07T21:00:00+00:00')))
        self.assertFalse(cm.daytime(dt.datetime.fromisoformat('2026-11-07T23:00:00+00:00')))
        self.assertFalse(cm.daytime(dt.datetime.fromisoformat('2026-11-07T05:59:00+00:00')))
        self.assertTrue(cm.daytime(dt.datetime.fromisoformat('2026-11-07T06:00:00+00:00')))
        self.assertTrue(cm.daytime(dt.datetime.fromisoformat('2026-11-07T21:59:00+00:00')))
        self.assertFalse(cm.daytime(dt.datetime.fromisoformat('2026-11-07T22:00:00+00:00')))

    def test_timeout_after_intent_never_sends_again(self):
        with tempfile.TemporaryDirectory() as tmp:
            client = cm.Client({'state_path': tmp+'/state.sqlite'})
            replies = [{'id': 42, 'status': 'prepared'}, RuntimeError('connexion coupée')]
            def call(*args):
                item=replies.pop(0)
                if isinstance(item,Exception): raise item
                return item
            with patch.object(cm,'daytime',return_value=True), patch.object(client,'api',side_effect=call):
                with self.assertRaises(RuntimeError): client.action(63,'video.like','read-id',True)
            with patch.object(cm,'daytime',return_value=True), patch.object(client,'api',return_value={'id':42,'status':'prepared'}) as api:
                result=client.action(63,'video.like','read-id',True)
                self.assertEqual(result['status'],'check_required')
                self.assertEqual(api.call_count,1)

    def test_no_social_request_at_night(self):
        with tempfile.TemporaryDirectory() as tmp:
            client=cm.Client({'state_path':tmp+'/state.sqlite'})
            with patch.object(cm,'daytime',return_value=False), patch.object(client,'api') as api:
                self.assertEqual(client.scan()['state'],'sleep')
                api.assert_not_called()

    def test_comment_without_hermes_text_never_falls_back_to_api(self):
        with tempfile.TemporaryDirectory() as tmp:
            client=cm.Client({'state_path':tmp+'/state.sqlite'})
            try:
                with patch.object(cm,'daytime',return_value=True),patch.object(client,'api') as api:
                    with self.assertRaises(RuntimeError):client.action(63,'video.comment','snapshot')
                    api.assert_not_called()
            finally:client.db.close()

    def test_x_timeout_keeps_its_own_intent_without_colliding_with_tiktok(self):
        with tempfile.TemporaryDirectory() as tmp:
            client=cm.Client({'state_path':tmp+'/state.sqlite'})
            client.remember(42, 'confirmed')
            responses=[{'id':42,'status':'prepared'}, RuntimeError('connexion perdue')]
            def call(*args):
                result=responses.pop(0)
                if isinstance(result,Exception): raise result
                return result
            with patch.object(cm,'daytime',return_value=True), patch.object(client,'api',side_effect=call):
                with self.assertRaises(RuntimeError):client.x_action(7,'like','snapshot',True,'987')
            with patch.object(cm,'daytime',return_value=True), patch.object(client,'api',return_value={'id':42,'status':'prepared'}) as api:
                self.assertEqual(client.x_action(7,'like','snapshot',True,'987')['status'],'check_required')
                self.assertEqual(api.call_count,1)
            client.db.close()

    def test_le_client_demarre_et_expose_ses_commandes_sans_configuration(self):
        import subprocess
        import sys
        result = subprocess.run([sys.executable, str(Path(cm.__file__)), '--help'], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('x-read', result.stdout)
        self.assertIn('report', result.stdout)

    def test_logs_peut_lire_un_diagnostic_de_nuit_sans_ecriture(self):
        import json,io,contextlib
        with tempfile.TemporaryDirectory() as tmp:
            config=Path(tmp)/'config.json';config.write_text(json.dumps({'state_path':tmp+'/state.sqlite'}))
            with patch.object(cm,'daytime',return_value=False),patch.object(cm.Client,'api',return_value={'logs':[]}) as api,contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(cm.main(['--config',str(config),'logs','--account','11','--status','uncertain','--before','99']),0)
                api.assert_called_once_with('GET','logs?account_id=11&status=uncertain&limit=20&before_id=99')

    def test_reponse_directe_sans_fichier_et_commit_atomique(self):
        import json,io,contextlib,sqlite3
        with tempfile.TemporaryDirectory() as tmp:
            config=Path(tmp)/'config.json';config.write_text(json.dumps({'state_path':tmp+'/state.sqlite'}))
            with patch.object(cm,'daytime',return_value=True),patch.object(cm.Client,'api',return_value={'queued':True}) as api,contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(cm.main(['--config',str(config),'reply','11','--context','ctx','--text','Merci 🖤','--commit']),0)
                api.assert_called_once_with('POST','inbox/11/reply',{'context_id':'ctx','reply_text':'Merci 🖤','dry_run':False})
            with sqlite3.connect(tmp+'/state.sqlite') as db:self.assertEqual(db.execute('SELECT state FROM attempts WHERE id=-11').fetchone()[0],'execution_requested')

    def test_fil_deja_traite_est_un_resultat_de_selection_et_non_un_arret(self):
        import json,io,contextlib
        with tempfile.TemporaryDirectory() as tmp:
            config=Path(tmp)/'config.json';config.write_text(json.dumps({'state_path':tmp+'/state.sqlite'}))
            output=io.StringIO()
            with patch.object(cm,'daytime',return_value=True),patch.object(cm.Client,'api',side_effect=cm.ApiError(409,{'skip_item':True,'review_reason':'already_handled'})),contextlib.redirect_stdout(output):
                self.assertEqual(cm.main(['--config',str(config),'reply-context','11']),0)
            self.assertTrue(json.loads(output.getvalue())['skip_item'])

    def test_bilan_direct_conserve_sa_note_sans_commande_de_creation_de_fichier(self):
        import json,io,contextlib
        with tempfile.TemporaryDirectory() as tmp:
            config=Path(tmp)/'config.json';config.write_text(json.dumps({'state_path':tmp+'/state.sqlite','base_url':'https://example.test'}))
            with patch.dict(cm.os.environ,{'HERMES_HOME':tmp}),patch.object(cm.Client,'api',return_value={'delivery':{'sent':True}}) as api,contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(cm.main(['--config',str(config),'report','--note','Deux gestes confirmés.','--commit']),0)
                self.assertEqual(api.call_args.args[2]['note'],'Deux gestes confirmés.')
            self.assertEqual((Path(tmp)/'workspace/last-x-cycle-note.txt').read_text(),'Deux gestes confirmés.')

if __name__=='__main__': unittest.main()
