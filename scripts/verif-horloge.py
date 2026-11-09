#!/usr/bin/env python3
"""Preuve que l'horloge serveur est en vrai epoch UTC, et que la regression est vue.

Contexte : `'age'` marchait par accident. `current_time('timestamp')` et
`strtotime($post->post_date)` derivent tous deux de `gmt_offset`, donc les
decalages s'annulaient — mais seulement si `gmt_offset` disait la verite. Le
correctif impose UNE convention : `time()` + `get_post_time('U', true)`.

Ce script est la seule garde executable sur cette decision. Il refuse de
passer si l'ancienne paire revient, quelle que soit la valeur de gmt_offset.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
MODULE = RACINE / "wordpress" / "tuc-market-core" / "includes" / "modules" / "class-tuc-caisse.php"

echecs = 0


def cas(label: str, ok: bool, detail: str = "") -> None:
    global echecs
    print(("PASS " if ok else "FAIL ") + label + (f" : {detail}" if detail else ""))
    if not ok:
        echecs += 1


def sans_commentaires(php: str) -> str:
    """Le code seul. Une garde qui lit les commentaires est une garde aveugle :
    la premiere version de ce script refusait mon propre fichier parce qu'il
    EXPLIQUE l'ancienne convention dans un commentaire."""
    php = re.sub(r"/\*.*?\*/", "", php, flags=re.S)   # blocs
    php = re.sub(r"(?m)//.*$", "", php)                # ligne
    php = re.sub(r"(?m)#.*$", "", php)                 # ligne (php)
    return php


def main() -> int:
    if not MODULE.is_file():
        print(f"FAIL module introuvable : {MODULE}")
        return 1
    source = sans_commentaires(MODULE.read_text(encoding="utf-8"))

    # --- 1. une seule convention, et c'est la bonne ---
    cas(
        "l'instant serveur vient de time()",
        re.search(r"\$maintenant\s*=\s*time\(\)\s*;", source) is not None,
        "vrai epoch UTC",
    )
    cas(
        "l'instant de la commande vient de get_post_time('U', true)",
        "get_post_time('U', true" in source,
        "vrai epoch UTC, insensible a gmt_offset",
    )

    # --- 2. la convention qui a cause le defaut ne revient pas ---
    cas(
        "aucun current_time('timestamp') dans le CODE (commentaires exclus)",
        "current_time('timestamp')" not in source,
        "epoch decale de gmt_offset : interdit ici",
    )
    cas(
        "aucun strtotime($p->post_date) dans le CODE (commentaires exclus)",
        "strtotime($p->post_date)" not in source,
        "heure locale lue comme UTC : interdit ici",
    )

    # --- 3. les deux champs existent et sont bien nommes ---
    cas("la reponse expose 'cree'", "'cree'" in source)
    cas("la reponse expose 'maintenant'", "'maintenant' => $maintenant" in source)

    # --- 4. mutation : la regression doit etre refusee ---
    brut = sans_commentaires(
        MODULE.read_text(encoding="utf-8")
        .replace("$maintenant = time();", "$maintenant = (int) current_time('timestamp');")
        .replace("'cree'      => (int) get_post_time('U', true, $p),", "'cree'      => (int) strtotime($p->post_date),")
    )
    regresse = (
        brut.replace("$maintenant = time();", "$maintenant = (int) current_time('timestamp');")
              .replace("'cree'      => (int) get_post_time('U', true, $p),", "'cree'      => (int) strtotime($p->post_date),")
    )
    if regresse == source:
        cas("mutation d'horloge injectable", False, "motifs introuvables : le controle ne prouve rien")
    else:
        refusee = (
            "current_time('timestamp')" in regresse
            and "strtotime($p->post_date)" in regresse
        )
        # la mutation doit produire un fichier que CE script refuserait
        cas(
            "la regression d'origine serait refusee",
            refusee,
            "current_time + strtotime = paire accidentelle",
        )

    print(f"\n{echecs} echec(s)")
    return 1 if echecs else 0


if __name__ == "__main__":
    sys.exit(main())
