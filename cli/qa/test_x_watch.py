import datetime as dt
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
spec = importlib.util.spec_from_file_location('x_watch', Path(__file__).resolve().parents[2]/'hermes/scripts/rsmax_x_watch.py')
watch = importlib.util.module_from_spec(spec); spec.loader.exec_module(watch)

class XWatchTest(unittest.TestCase):
    def account(self, remaining=40, next_post=None):
        return {'id':11, 'platform':'twitter','driver':'browser','session':'connected','actions':{'reply':{'left':10},'like':{'left':40}},
                'cadence':{'remaining':remaining,'next_allowed_at':None},'next_publication_at':next_post}

    def test_la_nuit_ne_declenche_aucune_lecture_reseau(self):
        c=Mock(); self.assertEqual(watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T23:00:00+00:00'))['state'],'sleep'); c.api.assert_not_called()

    def test_la_publication_planifiee_conserve_une_place_au_dela_du_duo_reponse_like(self):
        c=Mock(); c.api.return_value={'daytime':True,'accounts':[self.account(2,'2026-11-07T14:00:00+01:00')]}
        self.assertEqual(watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))['state'],'idle')
        self.assertEqual(c.api.call_count,1)

    def test_le_catalogue_et_les_commentaires_sont_lus_sans_compte_en_dur(self):
        c=Mock();c.api.side_effect=[{'daytime':True,'accounts':[self.account()]},{'items':[{'id':99,'content':'Très jolie tenue','can_reply':True}]}]
        r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))
        self.assertEqual(r['accounts'][0]['account_id'],11);self.assertEqual(r['accounts'][0]['items'][0]['id'],99)
        self.assertIn('accounts/11/inbox?',c.api.call_args.args[1])
