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

    def test_x_prelecture_elargie_et_plafond_du_lot_selon_les_gestes_disponibles(self):
        client = Mock()
        items = [{'id': i, 'content': 'Merci', 'can_reply': True} for i in range(20)]
        account = self.account()
        account['cadence']['daily'] = {'remaining': 100}
        account['actions']['reply']['left'] = 50
        account['actions']['like']['left'] = 17
        client.api.side_effect = [{'daytime': True, 'accounts': [account]}, {'items': items}]
        result = watch.snapshot(client, dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))
        self.assertIn('limit=20', client.api.call_args.args[1])
        self.assertEqual(result['accounts'][0]['batch_max'], 17)

    def test_tiktok_reserve_le_duo_sans_action_like_de_flux_et_garde_la_legende(self):
        c=Mock();a=self.account(2);a['platform']='tiktok';a['actions']={'reply':{'enabled':True,'left':10}}
        c.api.side_effect=[{'daytime':True,'accounts':[a]},{'items':[{'id':99,'content':'Merci','post_content':'La tenue du jour','can_reply':True}]}]
        r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'))
        self.assertEqual(r['state'],'work');self.assertEqual(r['accounts'][0]['platform'],'tiktok')
        self.assertEqual(r['accounts'][0]['batch_max'],1);self.assertEqual(r['accounts'][0]['items'][0]['post_content'],'La tenue du jour')
        self.assertIn('order=oldest', c.api.call_args.args[1])

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

    def test_la_recherche_reste_possible_si_seul_le_like_est_autorise(self):
        client = Mock()
        account = self.account()
        account.update(persona=5, strategy={'hashtags': ['#tenue']}, discovery_queries=['#tenue'])
        account['actions'] = {'like': {'left': 2}, 'search.read': {'left': 4}, 'thread.read': {'left': 4}}
        client.api.side_effect = [{'daytime': True, 'accounts': [account]}, {'logs': []}, {'logs': []}]

        result = watch.snapshot(client, dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'), lane='x')

        self.assertEqual(result['state'], 'work')
        self.assertEqual(result['accounts'][0]['items'], [])
        self.assertEqual(result['priority_x'][0]['query'], '#tenue')
        self.assertEqual(result['priority_x'][0]['write_actions'], ['like'])
        self.assertEqual(client.api.call_count, 3)

    def test_x_reprend_apres_la_derniere_recherche_effectuee(self):
        client = Mock()
        account = self.account()
        account['discovery_queries'] = ['mode', 'tenue', '#style', 'from:atelier']
        account['actions'].update({'feed.read': {'left': 4}, 'search.read': {'left': 4}})
        now = dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00')
        for last_query, wanted in [('mode', 'tenue'), ('tenue', '#style'), ('#style', 'from:atelier'), ('from:atelier', 'mode')]:
            client.api.side_effect = [{'logs': [{'at': '2026-11-07T12:10:00+00:00'}]},
                                      {'logs': [{'at': '2026-11-07T11:50:00+00:00', 'text': last_query}]}]
            plan = watch.discovery_source(client, account, now)
            self.assertEqual(plan['read_action'], 'search.read')
            self.assertEqual(plan['query'], wanted)
            self.assertEqual(plan['fallback']['read_action'], 'feed.read')

    def test_x_peut_essayer_une_autre_recherche_si_le_flux_est_desactive(self):
        client = Mock()
        client.api.return_value = {'logs': []}
        account = self.account()
        account['discovery_queries'] = ['from:atelier', 'from:membre']
        account['actions']['search.read'] = {'left': 4}
        now = dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00')
        plan = watch.discovery_source(client, account, now)

        self.assertEqual(plan['query'], 'from:atelier')
        self.assertEqual(plan['fallback'], {'read_action': 'search.read', 'query': 'from:membre'})

    def test_x_garde_les_recherches_et_actions_propres_a_chaque_compte(self):
        first = self.account()
        second = self.account()
        for account, identifier, tag, action in [(first, 11, '#photo', 'reply'), (second, 12, '#sport', 'like')]:
            account.update(id=identifier, persona=identifier, discovery_queries=[tag])
            account['actions'] = {action: {'left': 4}, 'search.read': {'left': 4}, 'thread.read': {'left': 4}}
        client = Mock()
        client.api.side_effect = lambda method, path: (
            {'daytime': True, 'accounts': [first, second]} if path == 'accounts' else {'logs': []})
        result = watch.snapshot(client, dt.datetime.fromisoformat('2026-11-07T12:30:00+00:00'), lane='x')
        self.assertEqual([(plan['account_id'], plan['query'], plan['write_actions']) for plan in result['priority_x']],
                         [(11, '#photo', ['reply']), (12, '#sport', ['like'])])

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

    def test_api_instagram_un_geste_et_facebook_deux_si_like_active(self):
        now=dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00')
        for platform,remaining,wanted in [('instagram',1,'work'),('facebook',1,'idle'),('facebook',2,'work')]:
            a=self.account(remaining);a.update(platform=platform,driver='api',api_ready=True,persona=5)
            a['actions']={'reply':{'left':5},'comment.like':{'left':5}} if platform=='facebook' else {'reply':{'left':5}}
            c=Mock();c.api.side_effect=[{'daytime':True,'accounts':[a]},{'items':[{'id':99,'content':'Merci','can_reply':True}]}]
            r=watch.snapshot(c,now);self.assertEqual(r['state'],wanted)
            if wanted=='work':self.assertEqual(r['accounts'][0]['auto_like'],platform=='facebook');self.assertEqual(r['accounts'][0]['executor'],'api')

    def test_facebook_garde_le_like_obligatoire_quand_son_quota_est_epuise(self):
        a=self.account();a.update(platform='facebook',driver='api',api_ready=True,persona=5)
        a['actions']={'reply':{'left':5},'comment.like':{'left':0}}
        c=Mock();c.api.return_value={'daytime':True,'accounts':[a]}
        self.assertEqual(watch.snapshot(c,dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00'))['state'],'idle');self.assertEqual(c.api.call_count,1)

    def test_threads_recherche_sans_inbox_exige_session_et_strategie(self):
        for session,queries,wanted in [('connected',['style'],'work'),(None,['style'],'idle'),('connected',[],'idle')]:
            a=self.account();a.update(platform='threads',driver='api',api_ready=True,persona=5,session=session,strategy={'search_queries':queries})
            a['actions']={'search.read':{'enabled':True,'left':2},'thread.read':{'enabled':True,'left':2},'comment':{'enabled':True,'left':2}}
            c=Mock();c.api.return_value={'daytime':True,'accounts':[a]}
            r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00'))
            self.assertEqual(r['state'],wanted);self.assertEqual(c.api.call_count,5 if wanted=='work' else (3 if session=='connected' else 1))
            if wanted=='work':self.assertTrue(r['accounts'][0]['threads_discovery'])

    def test_threads_explore_avant_inbox_meme_si_son_stock_est_non_vide(self):
        a=self.account();a.update(platform='threads',driver='api',api_ready=True,persona=5,strategy={'search_queries':['style','robe']})
        a['actions']['like']['enabled']=True
        a['actions'].update({'search.read':{'enabled':True,'left':2},'thread.read':{'enabled':True,'left':2},'comment':{'enabled':True,'left':2}})
        t=self.account();t.update(id=12,platform='tiktok')
        c=Mock()
        c.api.side_effect=lambda method,path: {'daytime':True,'accounts':[t,a]} if path=='accounts' else {'logs':[]} if path.startswith('logs?') else {'items':[{'id':99,'content':'Merci','can_reply':True}]}
        r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00'))
        self.assertEqual(r['accounts'][0]['platform'],'threads')
        self.assertEqual(r['priority_threads'][0]['write_actions'],['like','comment'])
        self.assertEqual(r['priority_threads'][0]['persona'],5)
        self.assertTrue(r['priority_threads'][0]['reserve_for_inbox'])
        self.assertTrue(r['accounts'][0]['items'])

    def test_threads_ne_recherche_pas_a_nouveau_avant_quinze_minutes_et_garde_inbox(self):
        a=self.account();a.update(platform='threads',driver='api',api_ready=True,persona=5,strategy={'search_queries':['style']})
        a['actions']['like']['enabled']=True
        a['actions'].update({'search.read':{'enabled':True,'left':2},'thread.read':{'enabled':True,'left':2},'comment':{'enabled':True,'left':2}})
        c=Mock();c.api.side_effect=[{'daytime':True,'accounts':[a]},{'logs':[]},{'logs':[]},{'logs':[]},{'logs':[{'at':'2026-11-08T11:50:00+00:00'}]},{'items':[{'id':99,'content':'Merci','can_reply':True}]}]
        r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00'))
        self.assertEqual(r['priority_threads'],[]);self.assertFalse(r['accounts'][0]['threads_discovery']);self.assertTrue(r['accounts'][0]['items'])

    def test_threads_garde_une_place_a_la_reponse_api(self):
        a=self.account(2);a.update(platform='threads',driver='api',api_ready=True,persona=5,strategy={'search_queries':['style']})
        a['actions']['like']['enabled']=True
        a['actions'].update({'search.read':{'enabled':True,'left':2},'thread.read':{'enabled':True,'left':2},'comment':{'enabled':True,'left':2}})
        c=Mock();c.api.side_effect=[{'daytime':True,'accounts':[a]},{'logs':[]},{'logs':[]},{'items':[{'id':99,'content':'Merci','can_reply':True}]}]
        r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00'))
        self.assertEqual(r['priority_threads'],[]);self.assertEqual(c.api.call_count,4)

    def test_threads_termine_le_like_sans_refaire_recherche_ni_commentaire(self):
        for previous_like,wanted in [(False,True),(True,False)]:
            a=self.account();a.update(platform='threads',driver='api',api_ready=True,persona=5,strategy={'search_queries':['style']})
            a['actions']['like']['enabled']=True
            a['actions'].update({'search.read':{'enabled':True,'left':2},'thread.read':{'enabled':True,'left':2},'comment':{'enabled':True,'left':2}})
            url='https://www.threads.com/@visitor/post/ABC123'
            c=Mock()
            def api(method,path):
                if path=='accounts': return {'daytime':True,'accounts':[a]}
                if 'action=threads.like' in path: return {'logs':[{'target_url':url,'status':'uncertain'}] if previous_like else []}
                if 'action=threads.comment' in path: return {'logs':[{'target_url':url,'status':'confirmed','proof':{'confirmed':True},'at':'2026-11-08T11:45:00+00:00'}]}
                if 'action=agent.search.read' in path: return {'logs':[{'at':'2026-11-08T11:50:00+00:00'}]}
                if path.startswith('logs?'): return {'logs':[]}
                return {'items':[{'id':99,'content':'Merci','can_reply':True}]}
            c.api.side_effect=api
            r=watch.snapshot(c,dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00'))
            self.assertEqual(bool(r['priority_threads']),wanted)
            if wanted:
                self.assertEqual(r['priority_threads'][0]['mode'],'finish_like');self.assertEqual(r['priority_threads'][0]['write_actions'],['like']);self.assertNotIn('query',r['priority_threads'][0])

    def test_api_sans_jeton_ou_sans_persona_n_est_pas_active(self):
        for ready,persona in [(False,5),(True,None)]:
            a=self.account();a.update(platform='youtube',driver='api',api_ready=ready,persona=persona)
            c=Mock();c.api.return_value={'daytime':True,'accounts':[a]}
            self.assertEqual(watch.snapshot(c,dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00'))['state'],'idle');self.assertEqual(c.api.call_count,1)

    def test_catalogue_compact_et_decouverte_x_apres_quinze_minutes(self):
        now = dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00')
        account = self.account()
        account.update(persona=5, strategy={'search_queries': ['mode']})
        account['actions'].update({action: {'kind': 'read', 'left': 10} for action in ('feed.read', 'search.read', 'thread.read')})
        client = Mock()
        client.api.side_effect = [
            {'daytime': True, 'accounts': [account]},
            {'logs': [{'at': '2026-11-08T11:44:00+00:00'}]},
            {'logs': []},
            {'items': []},
        ]
        plan = watch.snapshot(client, now, lane='x')['priority_x'][0]
        self.assertEqual(plan['read_action'], 'search.read')
        self.assertEqual(plan['fallback']['read_action'], 'feed.read')

    def test_la_file_api_ne_precharge_ni_x_ni_tiktok(self):
        now = dt.datetime.fromisoformat('2026-11-08T12:00:00+00:00')
        x = self.account()
        tiktok = self.account()
        tiktok.update(id=12, platform='tiktok')
        instagram = self.account()
        instagram.update(id=13, platform='instagram', driver='api', api_ready=True, persona=5)
        items = [{'id': i, 'content': 'Merci', 'can_reply': True} for i in range(5)]
        client = Mock()
        client.api.side_effect = [{'daytime': True, 'accounts': [x, tiktok, instagram]}, {'items': items}]
        result = watch.snapshot(client, now, lane='api')
        self.assertEqual(client.api.call_count, 2)
        self.assertEqual([a['platform'] for a in result['accounts']], ['instagram'])
        self.assertEqual(result['accounts'][0]['batch_max'], 5)
        self.assertEqual(result['accounts'][0]['items'], [{'id': i} for i in range(5)])


class ReportScheduleTest(unittest.TestCase):
    def test_les_creneaux_du_matin_et_du_soir_ont_des_cles_distinctes(self):
        spec=importlib.util.spec_from_file_location('scheduled_report',Path(__file__).resolve().parents[2]/'hermes/scripts/rsmax_morning_report.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        morning=module.report_arguments(dt.datetime.fromisoformat('2026-11-07T08:00:00+00:00'))
        morning_retry=module.report_arguments(dt.datetime.fromisoformat('2026-11-07T08:05:00+00:00'))
        evening=module.report_arguments(dt.datetime.fromisoformat('2026-11-07T20:00:00+00:00'))
        self.assertEqual(morning,morning_retry);self.assertNotEqual(morning[-1],evening[-1])
        self.assertIn('--scheduled',morning);self.assertEqual(morning[morning.index('--hours')+1],'12')
        self.assertIsNone(module.report_arguments(dt.datetime.fromisoformat('2026-11-07T07:00:00+00:00')))
        self.assertIn('--scheduled',module.report_arguments(dt.datetime.fromisoformat('2026-07-07T07:00:00+00:00')))
        self.assertIsNone(module.report_arguments(dt.datetime.fromisoformat('2026-07-07T08:00:00+00:00')))
