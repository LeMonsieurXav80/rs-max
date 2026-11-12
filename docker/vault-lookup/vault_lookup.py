#!/usr/bin/env python3
"""Chercher dans la révision Git courante d'un Vault les notes que RS-Max autorise pour un compte.

Un service par serveur et par Vault, sans réseau, en lecture seule. RS-Max envoie le format et les
dossiers de la source ; les zones sensibles restent refusées ici même si elles étaient demandées.
"""
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

FORBIDDEN = {"07-Raw", "Compta", "Mails", "Contacts", "Contacts-Pro", "Partenaires", "Credentials", "CREDENTIALS", ".git", ".obsidian", ".trash"}
FORBIDDEN_WORDS = re.compile(r"credential|password|secret|token", re.I)


def git(vault, *args):
    return subprocess.check_output(["git", "-C", str(vault), *args])


def frontmatter(text):
    if not text.startswith("---\n"):
        return {}, text
    header, sep, body = text[4:].partition("\n---\n")
    if not sep:
        return {}, text
    result = {}
    for line in header.splitlines():
        key, sep, value = line.partition(": ")
        if sep and re.fullmatch(r"[a-z_]+", key):
            value = value.strip()
            if value.startswith('"') and value.endswith('"'):
                value = value[1:-1].replace('\\"', '"')
            elif value.startswith("'") and value.endswith("'"):
                value = value[1:-1].replace("''", "'")
            result[key] = value
    return result, body


STOP = set("avec dans pour mais cela cette ceux leur leurs plus moins tres entre sans comme etre avoir fait elle peut peux prendre donne aussi quel quelle quelles quels sur une des les est qui que quoi son ses aux par pas tout tous sont deux ont ces vos nous vous how the and with what does from can are your when should will about study people".split())
ALIASES = {term: "cheveux" for term in "cheveu cheveux hair calvitie capillaire capillaires folliculaire baldness".split()}
ALIASES.update(dict(creatina="creatine", creapure="creatine", training="entrainement", entrainements="entrainement"))


def terms(value):
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode().lower()
    words = re.findall(r"[a-z][a-z0-9]{2,}", normalized)
    return {ALIASES.get(word, word.rstrip("s") if len(word) > 4 else word) for word in words if word not in STOP}


def plain(markdown):
    text = re.sub(r"```.*?```", " ", markdown, flags=re.S)
    text = re.sub(r"!\[\[[^\]]*\]\]|!\[[^\]]*\]\([^)]*\)", " ", text)
    text = re.sub(r"\[\[([^\]|]*\|)?([^\]]*)\]\]", r"\2", text)
    text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", text)
    text = re.sub(r"https?://\S+", " ", text)
    text = re.sub(r"^#+\s*", "", text, flags=re.M)
    text = re.sub(r"[*_`>|]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def allowed(path):
    parts = path.split("/")
    return not any(part in FORBIDDEN or part in ("", "..") for part in parts) and not FORBIDDEN_WORDS.search(path)


def folder_list(value):
    if not isinstance(value, list) or len(value) > 30:
        raise ValueError("Dossiers invalides")
    folders = []
    for folder in value:
        if not isinstance(folder, str) or len(folder) > 200:
            raise ValueError("Dossier invalide")
        folder = folder.strip().strip("/")
        if folder and allowed(folder):
            folders.append(folder)
    return folders


class Corpus:
    def __init__(self, vault):
        self.vault = vault
        self.revision = None
        self.cache = {}
        self.lock = Lock()

    def documents(self, fmt, folders, excluded):
        revision = git(self.vault, "rev-parse", "HEAD").decode().strip()
        if revision != self.revision:
            self.revision, self.cache = revision, {}
        key = (fmt, tuple(folders), tuple(excluded))
        if key not in self.cache:
            self.cache[key] = self.load(revision, fmt, folders, excluded)
        return revision, self.cache[key]

    def load(self, revision, fmt, folders, excluded):
        tree = git(self.vault, "ls-tree", "-r", "-z", "--name-only", revision, "--", *folders).decode("utf-8")
        names = [p for p in tree.split("\0") if p.endswith(".md") and allowed(p)
                 and not any(p == e or p.startswith(e + "/") for e in excluded)]
        documents = []
        for name in names[:3000]:
            note, body = frontmatter(git(self.vault, "show", f"{revision}:{name}").decode("utf-8-sig", "replace"))
            if fmt == "pubmed":
                pmid = Path(name).name.split("-", 1)[0]
                if not re.fullmatch(r"\d{7,9}", pmid) or note.get("type") != "recherche" or note.get("status") != "active" or note.get("pmid") != pmid:
                    continue
                if note.get("title") and note.get("summary"):
                    source = {"pmid": pmid, "title": note["title"][:450], "excerpt": note["summary"][:1200]}
                    documents.append((source, terms(source["title"]), terms(source["excerpt"])))
                continue
            if note.get("status") in ("draft", "archived", "private"):
                continue
            title = note.get("title") or Path(name).stem
            text = plain(body)
            excerpt = (note.get("summary") or text)[:1200]
            if len(excerpt) < 80:
                continue
            documents.append(({"path": name, "title": title[:200], "excerpt": excerpt}, terms(title), terms(excerpt + " " + text[:4000])))
        return documents

    def lookup(self, target, origin, fmt, folders, excluded):
        with self.lock:
            revision, documents = self.documents(fmt, folders, excluded)
        target_words = terms(target)
        query = target_words | terms(origin or "")
        frequencies = {}
        matches = []
        for source, title, content in documents:
            hits = query & (title | content)
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
            fmt = data.get("format", "pubmed")
            if fmt not in ("pubmed", "notes"):
                raise ValueError("Format invalide")
            # Les anciens appels de DIX n'envoient ni format ni dossiers : fiches PubMed de Recherches.
            folders = folder_list(data.get("folders", ["Recherches"]))
            excluded = folder_list(data.get("excluded") or [])
            if not folders:
                raise ValueError("Aucun dossier autorisé")
        except (TypeError, ValueError, AttributeError):
            self.send_error(400)
            return
        try:
            response = json.dumps(self.server.corpus.lookup(target, origin, fmt, folders, excluded), ensure_ascii=False).encode()
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
    revision = git(corpus.vault, "rev-parse", "HEAD").decode().strip()
    with ThreadingUnixStreamServer(str(args.socket), Handler) as server:
        server.corpus = corpus
        os.chown(args.socket, -1, int(os.environ.get("VAULT_SOCKET_GID", os.getgid())))
        args.socket.chmod(0o660)
        print(f"Vault {revision[:8]} prêt sur {args.socket}.", flush=True)
        server.serve_forever()


if __name__ == "__main__":
    main()
