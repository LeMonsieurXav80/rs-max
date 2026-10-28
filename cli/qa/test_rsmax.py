#!/usr/bin/env python3
"""
Tests du CLI. Aucun appel reseau : seule la logique locale est couverte, c'est
elle qui peut trahir en silence.

    python3 -m unittest discover -s cli/qa
"""
import importlib.util
import json
import contextlib
import io
from unittest.mock import patch
import os
import pathlib
import sys
import tempfile
import unittest

MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / 'rsmax.py'
spec = importlib.util.spec_from_file_location('rsmax', MODULE_PATH)
rsmax = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rsmax)


class TestMultipartBody(unittest.TestCase):
    """Le `Content-Length` est annonce avant d'avoir lu le fichier. S'il ne
    correspond pas a l'octet pres, le serveur tronque ou attend indefiniment —
    et une video de 500 Mo n'est pas assemblee en memoire pour verifier."""

    def setUp(self):
        self.path = pathlib.Path(tempfile.mkdtemp()) / 'video.mp4'
        self.payload = os.urandom(700_000)
        self.path.write_bytes(self.payload)

    def _drain(self, body):
        chunks = []
        while True:
            chunk = body.read()
            if not chunk:
                return b''.join(chunks)
            chunks.append(chunk)

    def test_longueur_annoncee_egale_aux_octets_lus(self):
        body = rsmax.MultipartBody(self.path, {'folder_path': 'Videos DIX'})
        self.assertEqual(body.length, len(self._drain(body)))

    def test_le_fichier_traverse_intact(self):
        body = rsmax.MultipartBody(self.path, {})
        self.assertIn(self.payload, self._drain(body))

    def test_les_champs_precedent_le_fichier(self):
        body = rsmax.MultipartBody(self.path, {'folder_path': 'Videos DIX'})
        sent = self._drain(body)
        self.assertLess(sent.index(b'Videos DIX'), sent.index(self.payload))


class TestParseWhen(unittest.TestCase):
    """Une date sans fuseau est interpretee par le serveur dans SON fuseau : la
    publication part alors a cote. Le fuseau du poste est donc toujours joint."""

    def test_format_court_recoit_un_fuseau(self):
        self.assertRegex(rsmax.parse_when('2026-10-08 18:00'), r'2026-10-08T18:00:00[+-]\d{2}:\d{2}$')

    def test_la_barre_oblique_est_acceptee(self):
        self.assertRegex(rsmax.parse_when('2026/10/08 18:00'), r'^2026-10-08T18:00:00')

    def test_un_fuseau_explicite_est_respecte(self):
        self.assertEqual(rsmax.parse_when('2026-10-08T18:00:00+02:00'), '2026-10-08T18:00:00+02:00')

    def test_une_date_incomprise_est_refusee(self):
        with self.assertRaises(SystemExit):
            rsmax.parse_when('jeudi prochain')


class TestUnCompteParReseau(unittest.TestCase):
    """L'API accepte deux comptes du meme reseau (201) mais ne publie rien.
    L'anomalie ne se voit qu'a l'heure prevue — trop tard. On la refuse ici."""

    CATALOG = {
        21: {'platform': 'bluesky', 'name': 'Cyril Bluesky'},
        22: {'platform': 'bluesky', 'name': 'DIX Bluesky'},
        23: {'platform': 'twitter', 'name': 'Cyril X'},
    }

    def test_deux_comptes_du_meme_reseau_sont_refuses(self):
        with self.assertRaises(SystemExit) as refus:
            rsmax.check_one_account_per_platform([21, 22, 23], self.CATALOG)
        self.assertIn('bluesky', str(refus.exception))

    def test_un_compte_par_reseau_est_accepte(self):
        rsmax.check_one_account_per_platform([21, 23], self.CATALOG)


class TestExpansionDesChemins(unittest.TestCase):
    def setUp(self):
        self.folder = pathlib.Path(tempfile.mkdtemp())
        for name in ('03.png', '01.png', '02.png', 'notes.txt', '.cache.png'):
            (self.folder / name).write_bytes(b'x')

    def test_un_dossier_est_trie_par_nom(self):
        # L'ordre des slides d'un carrousel doit etre previsible, pas celui que
        # le systeme de fichiers rend.
        noms = [p.name for p in rsmax.expand_media_paths([str(self.folder)])]
        self.assertEqual(noms, ['01.png', '02.png', '03.png'])

    def test_les_fichiers_non_media_sont_ecartes(self):
        noms = [p.name for p in rsmax.expand_media_paths([str(self.folder)])]
        self.assertNotIn('notes.txt', noms)

    def test_un_chemin_introuvable_est_signale(self):
        with self.assertRaises(SystemExit):
            rsmax.expand_media_paths([str(self.folder / 'absent.png')])


class TestConfigPath(unittest.TestCase):
    def test_sous_windows_le_jeton_va_dans_appdata(self):
        """Le code sur le disque d'outils, les secrets sur le disque systeme :
        un disque exFAT ne porte aucune permission de fichier."""
        if os.name == 'nt':
            self.assertIn('rs-max', str(rsmax.config_path()))
        else:
            self.assertTrue(str(rsmax.config_path()).endswith('rs-max/config.json'))


class TestInboxReplies(unittest.TestCase):
    def args(self, *extra):
        return rsmax.build_parser().parse_args(['repondre', *extra])

    def test_le_nombre_est_borne_et_le_reseau_ou_compte_est_obligatoire(self):
        for extra in ([], ['--reseaux', 'instagram', '--nombre', '0'],
                      ['--reseaux', 'instagram', '--nombre', '101']):
            with self.assertRaises(SystemExit):
                rsmax.inbox_scope(self.args(*extra))
        self.assertEqual(rsmax.inbox_scope(self.args('--reseaux', 'instagram,facebook',
            '--comptes', '12,18', '--nombre', '7'))['limit'], 7)

    @patch.object(rsmax.time, 'sleep')
    @patch.object(rsmax, 'resolve_profile', return_value=('https://test.invalid/api', 'token'))
    def test_apercu_selectionne_n_genere_avec_persona_et_ne_publie_rien(self, profile, sleep):
        selection = {'requested': 2, 'items': [
            {'id': 1, 'platform': 'instagram', 'account': {'id': 12}, 'content': 'Bonjour'},
            {'id': 2, 'platform': 'facebook', 'account': {'id': 18}, 'content': 'Bravo'}]}
        with patch.object(rsmax, 'call', side_effect=[selection,
                {'reply_text': 'Salut !', 'persona': {'name': 'Caroline'}},
                {'reply_text': 'Merci !', 'persona': {'name': 'Xavier'}}]) as call:
            with contextlib.redirect_stdout(io.StringIO()) as output:
                rsmax.command_repondre(self.args('--reseaux', 'instagram,facebook', '--nombre', '2'))
            self.assertEqual(call.call_count, 3)
            self.assertEqual(call.call_args_list[0].args[4]['limit'], 2)
            plan = json.loads(output.getvalue())
            self.assertEqual(plan['items'][1]['persona']['name'], 'Xavier')
            self.assertFalse(any(c.args[3].endswith('/reply') for c in call.call_args_list))

    @patch.object(rsmax.time, 'sleep')
    @patch.object(rsmax, 'resolve_profile', return_value=('https://test.invalid/api', 'token'))
    def test_envoi_du_plan_conserve_les_textes_et_sa_reprise_saute_les_succes(self, profile, sleep):
        with tempfile.TemporaryDirectory() as folder:
            path = pathlib.Path(folder) / 'plan.json'
            plan = {'instance': 'https://test.invalid/api', 'items': [
                {'id': 1, 'reply_text': 'Texte valide', 'result': {'success': True}},
                {'id': 2, 'reply_text': 'Réponse relue'}]}
            path.write_text(json.dumps(plan))
            with patch.object(rsmax, 'call', return_value={'success': True}) as call:
                with contextlib.redirect_stdout(io.StringIO()):
                    rsmax.command_repondre(self.args('--plan', str(path), '--envoyer', '--etaler-minutes', '60'))
                call.assert_called_once()
                self.assertEqual(call.call_args.args[3], '/inbox/2/reply')
                self.assertEqual(call.call_args.args[4]['reply_text'], 'Réponse relue')
                self.assertFalse(call.call_args.args[4]['dry_run'])
                self.assertIn('scheduled_at', call.call_args.args[4])
                self.assertTrue(json.loads(path.read_text())['items'][1]['result']['success'])

    @patch.object(rsmax, 'resolve_profile', return_value=('https://test.invalid/api', 'token'))
    def test_dry_run_ne_genere_pas_et_ne_publie_pas(self, profile):
        with patch.object(rsmax, 'call') as call, contextlib.redirect_stdout(io.StringIO()):
            args = self.args('--reseaux', 'instagram', '--envoyer')
            args.dry_run = True
            rsmax.command_repondre(args)
            call.assert_not_called()

    @patch.object(rsmax, 'resolve_profile', return_value=('https://test.invalid/api', 'token'))
    def test_un_plan_d_une_autre_instance_ou_avec_doublons_est_refuse(self, profile):
        with tempfile.TemporaryDirectory() as folder:
            path = pathlib.Path(folder) / 'plan.json'
            for plan in ({'instance': 'https://other.invalid/api', 'items': []},
                         {'instance': 'https://test.invalid/api', 'items': [
                             {'id': 1, 'reply_text': 'A'}, {'id': 1, 'reply_text': 'B'}]}):
                path.write_text(json.dumps(plan))
                with patch.object(rsmax, 'call') as call, self.assertRaises(SystemExit):
                    rsmax.command_repondre(self.args('--plan', str(path), '--envoyer'))
                call.assert_not_called()


if __name__ == '__main__':
    unittest.main(verbosity=2)
