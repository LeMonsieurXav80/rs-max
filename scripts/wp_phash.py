#!/usr/bin/env python3
"""
Calcule le phash perceptuel d'images pour la commande `media:reconcile-wp`.

IMPORTANT : utilise EXACTEMENT le même algo que le pipeline d'ingest Mac
(`analyse-images.py` → `imagehash.phash(img)`, défauts hash_size=8), sinon les
distances de Hamming face aux phash stockés en base n'auraient aucun sens.
Doit être exécuté avec le python du MÊME venv que l'ingest (mêmes versions
imagehash/PIL) — voir config services.media_reconcile.python.

Usage :
    python wp_phash.py <manifest.json>
    # manifest.json : ["/tmp/a.jpg", "/tmp/b.jpg", ...]
    # stdout        : {"/tmp/a.jpg": "a1b2c3d4e5f60718", "/tmp/b.jpg": null}

Un phash vaut `null` si l'image est illisible (le PHP l'ignore sans planter).
Le mode `--selfcheck` sert au smoke test PHP : vérifie que imagehash importe.
"""
import json
import sys


def main() -> int:
    if len(sys.argv) >= 2 and sys.argv[1] == "--selfcheck":
        import imagehash  # noqa: F401
        from PIL import Image  # noqa: F401
        print("ok")
        return 0

    if len(sys.argv) < 2:
        print("usage: wp_phash.py <manifest.json>", file=sys.stderr)
        return 2

    import imagehash
    from PIL import Image

    with open(sys.argv[1], "r", encoding="utf-8") as fh:
        paths = json.load(fh)

    out = {}
    for path in paths:
        try:
            with Image.open(path) as img:
                out[path] = str(imagehash.phash(img))
        except Exception as exc:  # image corrompue / format non supporté
            print(f"phash failed for {path}: {exc}", file=sys.stderr)
            out[path] = None

    json.dump(out, sys.stdout)
    return 0


if __name__ == "__main__":
    sys.exit(main())
