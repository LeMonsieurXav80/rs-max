#!/usr/bin/env python3
"""Rechercher les références PubMed dans la révision Git courante du Vault."""
import argparse
import json
from http.server import BaseHTTPRequestHandler
from math import sqrt
import os
from pathlib import Path
import re
import subprocess
from socketserver import ThreadingUnixStreamServer
from threading import Lock
import unicodedata


def git(vault, *args):
    return subprocess.check_output(["git", "-C", str(vault), *args])


def frontmatter(text):
    if not text.startswith("---\n"):
        return {}
    header = text.split("\n---\n", 1)[0]
    result = {}
    for line in header.splitlines()[1:]:
        key, sep, value = line.partition(": ")
        if sep and re.fullmatch(r"[a-z_]+", key):
            value = value.strip()
            if value.startswith('"') and value.endswith('"'):
                value = value[1:-1].replace('\\"', '"')
            elif value.startswith("'") and value.endswith("'"):
                value = value[1:-1].replace("''", "'")
            result[key] = value
    return result


STOP = set("avec dans pour mais cela cette ceux leur leurs plus moins tres entre sans comme etre avoir fait elle peut peux prendre donne aussi quel quelle quelles quels sur une des les est qui que quoi son ses aux par pas tout tous sont deux ont ces vos nous vous how the and with what does from can are your when should will about study people".split())
ALIASES = {
    term: "cheveux" for term in "cheveu cheveux hair calvitie capillaire capillaires folliculaire baldness".split()
}
ALIASES.update(dict(creatina="creatine", creapure="creatine", training="entrainement", entrainements="entrainement"))


def terms(value):
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode().lower()
    words = re.findall(r"[a-z][a-z0-9]{2,}", normalized)
    return {ALIASES.get(word, word.rstrip("s") if len(word) > 4 else word) for word in words if word not in STOP}


class Corpus:
    def __init__(self, vault):
        self.vault = vault
        self.revision = None
        self.documents = []
        self.lock = Lock()

    def refresh(self):
        revision = git(self.vault, "rev-parse", "HEAD").decode().strip()
        if revision == self.revision:
            return
        tree = git(self.vault, "ls-tree", "-r", "-z", "--name-only", revision, "--", "Recherches")
        candidates = [p for p in tree.decode("utf-8").split("\0") if re.search(r"/\d{7,9}-[^/]+\.md$", p)]
        if not candidates:
            raise ValueError("Corpus vide")
        sources = {}
        for name in candidates:
            pmid = Path(name).name.split("-", 1)[0]
            note = frontmatter(git(self.vault, "show", f"{revision}:{name}").decode("utf-8-sig"))
            if note.get("type") != "recherche" or note.get("status") != "active" or note.get("pmid") != pmid:
                continue
            if note.get("title") and note.get("summary"):
                sources.setdefault(pmid, {"pmid": pmid, "title": note["title"][:450], "excerpt": note["summary"][:1200]})
        if not sources:
            raise ValueError("Aucune fiche PubMed admissible")
        self.documents = [(source, terms(source["title"]), terms(source["excerpt"])) for source in sources.values()]
        self.revision = revision

    def lookup(self, target, origin):
        with self.lock:
            self.refresh()
            revision, documents = self.revision, self.documents
        target_words = terms(target)
        query = target_words | terms(origin or "")
        frequencies = {}
        matches = []
        for source, title, excerpt in documents:
            hits = query & (title | excerpt)
            if len(hits) < 2 or not hits & target_words:
                continue
            for word in hits:
                frequencies[word] = frequencies.get(word, 0) + 1
            matches.append((source, title, hits))

        def score(match):
            _, title, hits = match
            return sum((1 + (word in title) + (word in target_words)) / sqrt(frequencies[word]) for word in sorted(hits))

        matches.sort(key=score, reverse=True)
        return {"revision": revision, "sources": [match[0] for match in matches[:3]]}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_POST(self):
        if self.path != "/lookup":
            self.send_error(404)
            return
        try:
            size = int(self.headers.get("Content-Length", "0"))
            if size < 1 or size > 80000:
                raise ValueError("Taille invalide")
            data = json.loads(self.rfile.read(size))
            target, origin = data.get("target"), data.get("origin")
            if not isinstance(target, str) or not target.strip() or len(target) > 6000:
                raise ValueError("Cible invalide")
            if origin is not None and (not isinstance(origin, str) or len(origin) > 6000):
                raise ValueError("Origine invalide")
        except (TypeError, ValueError, AttributeError):
            self.send_error(400)
            return
        try:
            response = json.dumps(self.server.corpus.lookup(target, origin), ensure_ascii=False).encode()
        except (OSError, ValueError, subprocess.CalledProcessError):
            self.send_error(503)
            return
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(response)))
        self.end_headers()
        self.wfile.write(response)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("vault", type=Path)
    parser.add_argument("--socket", type=Path, required=True)
    args = parser.parse_args()
    if args.socket.is_socket():
        args.socket.unlink()
    corpus = Corpus(args.vault.resolve())
    corpus.refresh()
    with ThreadingUnixStreamServer(str(args.socket), Handler) as server:
        server.corpus = corpus
        os.chown(args.socket, -1, int(os.environ.get("VAULT_SOCKET_GID", os.getgid())))
        args.socket.chmod(0o660)
        print(f"Vault {corpus.revision[:8]} : {len(corpus.documents)} références en mémoire.", flush=True)
        server.serve_forever()


if __name__ == "__main__":
    main()
