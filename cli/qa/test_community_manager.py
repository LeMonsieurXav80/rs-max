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

if __name__=='__main__': unittest.main()
