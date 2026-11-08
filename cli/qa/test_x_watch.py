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

    def test_tiktok_reserve_le_duo_sans_action_like_de_flux_et_garde_la_legende(self):
        c=Mock();a=self.account(2);a['platform']='tiktok';a['actions']={'reply':{'enabled':True,'left':10}}
        c.api.side_effect=[{'daytime':True,'accounts':[a]},{'items':[{'id':99,'content':'Merci','post_content':'La tenue du jour','can_reply':True}]}]
        r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))
        self.assertEqual(r['state'],'work');self.assertEqual(r['accounts'][0]['platform'],'tiktok')
        self.assertEqual(r['accounts'][0]['batch_max'],1);self.assertEqual(r['accounts'][0]['items'][0]['post_content'],'La tenue du jour')

    def test_tiktok_ne_demarre_pas_avec_un_seul_geste_ni_un_quota_de_flux_seul(self):
        for remaining,reply_left in [(1,10),(2,0)]:
            c=Mock();a=self.account(remaining);a['platform']='tiktok';a['actions']['reply']['left']=reply_left;a['actions']['feed.read']={'left':5}
            c.api.return_value={'daytime':True,'accounts':[a]}
            self.assertEqual(watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))['state'],'idle');self.assertEqual(c.api.call_count,1)

    def test_tiktok_passe_avant_x_sans_ecarter_x(self):
        c=Mock();x=self.account();t=self.account(2);t.update(id=12,platform='tiktok')
        items={'items':[{'id':99,'content':'Merci','can_reply':True}]}
        c.api.side_effect=[{'daytime':True,'accounts':[x,t]},items,items]
        r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))
        self.assertEqual([a['platform'] for a in r['accounts']],['tiktok','twitter'])

    def test_le_plafond_quotidien_et_la_pause_bloquent_avant_lecture(self):
        for extra in [{'daily':{'remaining':1}}, {'blocked':True}]:
            c=Mock();a=self.account();a['cadence'].update(extra);c.api.return_value={'daytime':True,'accounts':[a]}
            self.assertEqual(watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))['state'],'idle');self.assertEqual(c.api.call_count,1)

    def test_le_navigateur_reste_libre_autour_d_une_publication(self):
        c=Mock();c.api.return_value={'daytime':True,'accounts':[self.account(120,'2026-11-07T12:33:00+00:00')]}
        self.assertEqual(watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))['state'],'idle');self.assertEqual(c.api.call_count,1)

    def test_le_flux_reste_possible_sans_quota_de_reponse(self):
        c=Mock();a=self.account();a['actions']['reply']['left']=0;a['actions']['feed.read']={'left':2}
        c.api.return_value={'daytime':True,'accounts':[a]}
        r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))
        self.assertEqual(r['state'],'work');self.assertEqual(r['accounts'][0]['items'],[]);self.assertEqual(c.api.call_count,1)

    def test_reserve_quotidienne_pour_publication_lointaine_et_lot_borne(self):
        c=Mock();a=self.account(120,'2026-11-07T17:00:00+00:00');a['cadence']['daily']={'remaining':2};c.api.return_value={'daytime':True,'accounts':[a]}
        now=dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00')
        self.assertEqual(watch.snapshot(c,now)['state'],'idle')
        a['cadence']['daily']['remaining']=5
        c.api.side_effect=[{'daytime':True,'accounts':[a]},{'items':[{'id':99,'content':'Merci','can_reply':True}]}]
        self.assertEqual(watch.snapshot(c,now)['accounts'][0]['batch_max'],2)

    def test_un_stock_identique_reveille_le_cycle_suivant_et_la_file_exclut_les_ecartes(self):
        c=Mock();c.api.side_effect=[{'daytime':True,'accounts':[self.account()]},{'items':[{'id':99,'content':'Merci','can_reply':True}]}, {'daytime':True,'accounts':[self.account()]},{'items':[{'id':99,'content':'Merci','can_reply':True}]}]
        first=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))
        second=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:35:00+00:00'))
        self.assertNotEqual(first['cycle_key'],second['cycle_key']);self.assertEqual(first['accounts'],second['accounts'])
        self.assertIn('agent_queue=1',c.api.call_args.args[1])


class ReportScheduleTest(unittest.TestCase):
    def test_les_creneaux_du_matin_et_du_soir_ont_des_cles_distinctes(self):
        spec=importlib.util.spec_from_file_location('scheduled_report',Path(__file__).resolve().parents[2]/'hermes/scripts/rsmax_morning_report.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        morning=module.report_arguments(dt.datetime.fromisoformat('2026-11-07T08:00:00+00:00'))
        morning_retry=module.report_arguments(dt.datetime.fromisoformat('2026-11-07T08:05:00+00:00'))
        evening=module.report_arguments(dt.datetime.fromisoformat('2026-11-07T20:00:00+00:00'))
        self.assertEqual(morning,morning_retry);self.assertNotEqual(morning[-1],evening[-1])
        self.assertIn('--scheduled',morning);self.assertEqual(morning[morning.index('--hours')+1],'12')
