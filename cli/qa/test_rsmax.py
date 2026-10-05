#!/usr/bin/env python3
"""
Tests du CLI. Aucun appel reseau : seule la logique locale est couverte, c'est
elle qui peut trahir en silence.

    python3 -m unittest discover -s cli/qa
"""
import importlib.util
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


if __name__ == '__main__':
    unittest.main(verbosity=2)
