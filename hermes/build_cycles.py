#!/usr/bin/env python3
"""Assemble une skill par file Hermès (tronc commun + section de la file) et son prompt de cycle.

Avant le 13/11/2026, chaque file chargeait la skill complète (26 k caractères) et un prompt de cycle
couvrant tous les réseaux (12 k), renvoyés au modèle à chaque appel d'outil : 41 % des jetons.
Les sources sont dans hermes/cycles/ ; les fichiers générés sont versionnés pour être copiés tels quels.

    python3 hermes/build_cycles.py           # régénère
    python3 hermes/build_cycles.py --check   # échoue si un fichier généré est périmé
"""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
LANES = {
    'x': ('X', 'File X de RS-Max : mentions, découverte et suivi X par Companion.'),
    'api': ('API', 'File API de RS-Max : commentaires reçus Facebook, Instagram et YouTube.'),
    'threads': ('Threads', 'File Threads de RS-Max : commentaires reçus et exploration Companion.'),
    'tiktok': ('TikTok', 'File TikTok de RS-Max : commentaires reçus et découverte Companion.'),
}
# Au-delà, la skill d'une file redevient le premier poste de coût : la découper avant d'en ajouter.
MAX_SKILL_CHARS = 14000


def skill(lane):
    label, description = LANES[lane]
    common = (ROOT / 'cycles/commun.md').read_text(encoding='utf-8').strip()
    section = (ROOT / f'cycles/{lane}.md').read_text(encoding='utf-8').strip()
    return f'---\nname: rsmax-cycle-{lane}\ndescription: {description}\n---\n\n{section}\n\n{common}\n'


def prompt(lane):
    return (ROOT / 'cycles/prompt.txt').read_text(encoding='utf-8').strip().format(label=LANES[lane][0], lane=lane) + '\n'


def outputs():
    for lane in LANES:
        yield ROOT / f'skills/rsmax-cycle-{lane}/SKILL.md', skill(lane)
        yield ROOT / f'cycles/build/prompt-{lane}.txt', prompt(lane)


def main(argv):
    stale = []
    for path, content in outputs():
        if path.name == 'SKILL.md' and len(content) > MAX_SKILL_CHARS:
            stale.append(f'{path.relative_to(ROOT)} : {len(content)} caractères > {MAX_SKILL_CHARS}')
        if '--check' in argv:
            if not path.is_file() or path.read_text(encoding='utf-8') != content:
                stale.append(f'{path.relative_to(ROOT)} : périmé, lancer python3 hermes/build_cycles.py')
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding='utf-8')
            print(f'{path.relative_to(ROOT)} : {len(content)} caractères')
    if stale:
        print('\n'.join(stale), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
