"""python3 -m unittest discover -s docker/vault-lookup"""
import subprocess
import tempfile
import unittest
from pathlib import Path

import vault_lookup


class LookupTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        root = Path(self.dir.name)
        files = {
            "03-Resources/Livres/Habitudes.md": "---\ntitle: Le Pouvoir des Habitudes\nsummary: Une habitude suit la boucle signal routine récompense, utile pour garder sa motivation.\n---\nTexte.",
            "03-Resources/Livres/Brouillon.md": "---\nstatus: draft\n---\n" + "habitude motivation " * 20,
            "07-Raw/Livres/Secret.md": "habitude motivation " * 20,
            "Compta/Factures.md": "habitude motivation facture " * 20,
            "Recherches/Creatine/40265319-cheveux.md": "---\ntype: recherche\nstatus: active\npmid: 40265319\ntitle: Does creatine cause hair loss?\nsummary: Essai contrôlé sur la créatine et la chute des cheveux.\n---\n",
        }
        for name, text in files.items():
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text)
        for args in (["init", "-q"], ["add", "."], ["-c", "user.email=t@t", "-c", "user.name=t", "commit", "-qm", "init"]):
            subprocess.check_call(["git", "-C", str(root), *args])
        self.corpus = vault_lookup.Corpus(root)

    def tearDown(self):
        self.dir.cleanup()

    def test_les_notes_autorisees_seulement(self):
        result = self.corpus.lookup("Comment garder ses habitudes et sa motivation ?", None, "notes",
                                    vault_lookup.folder_list(["03-Resources/Livres", "07-Raw", "Compta", "../x"]), [])
        self.assertEqual(["03-Resources/Livres/Habitudes.md"], [s["path"] for s in result["sources"]])

    def test_les_fiches_pubmed_restent_compatibles(self):
        result = self.corpus.lookup("La créatine donne-t-elle une chute de cheveux ?", None, "pubmed", ["Recherches"], [])
        self.assertEqual("40265319", result["sources"][0]["pmid"])

    def test_aucun_dossier_sensible_meme_demande(self):
        self.assertEqual([], vault_lookup.folder_list(["07-Raw", "02-Areas/Mails", "Outils/credentials", ""]))


if __name__ == "__main__":
    unittest.main()
