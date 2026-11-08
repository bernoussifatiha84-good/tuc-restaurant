#!/usr/bin/env python3
"""Garde-fou anti-régression : la branche d'intégration ne doit pas perdre `develop`.

Contexte : la PR #2 avait été construite depuis un clone dont le refspec ne
contenait que `main` et la branche de travail. `develop` (38 commits) était donc
invisible, et l'intégration avait été déclarée « réunie » à tort. Ce test rend ce
trou détectable au lieu de dépendre de la vigilance d'une session.

Deux niveaux :
1. Contenu : les fichiers et correctifs propres à `develop` doivent être présents.
2. Ascendance : si l'historique Git complet est disponible, `origin/develop` doit
   être ancêtre de HEAD. En checkout shallow, ce niveau est sauté explicitement.
"""

from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Fichiers présents dans develop et absents du snapshot importé par la PR #1.
DEVELOP_FILES = [
    "ARCHITECTURE-MODULES.md",
    "AUDIT-TRANSACTION.md",
    "CAISSE-PWA.md",
    "COMPTES-WHATSAPP.md",
    "GIT-FLOW.md",
    "ROADMAP-PERF.md",
    "WHATSAPP-BAN-ANALYSE.md",
    "WHATSAPP-COUTS.md",
    "WHATSAPP-FAITS.md",
    "wordpress/tuc-market-core/assets/caisse/demo-caisse.html",
]

# Correctifs que develop a ajoutés après le snapshot 73e7044.
DEVELOP_FIXES = [
    (
        "wordpress/tuc-market-core/includes/class-tuc-health.php",
        "'woocommerce' => class_exists('WooCommerce')",
        "/sante annonce l'état WooCommerce (develop 1ca43e5)",
    ),
    (
        "wordpress/tuc-theme/functions.php",
        "function_exists($condition)",
        "conditionnels WooCommerce gardés, sinon écran blanc (develop 71bdfc9)",
    ),
]


def git(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(["git", *args], cwd=ROOT, capture_output=True, text=True)


def main() -> int:
    failures = 0

    print("=== Contenu propre à develop ===")
    for relative in DEVELOP_FILES:
        present = (ROOT / relative).is_file()
        print(f"{'PASS' if present else 'FAIL'} présent : {relative}")
        failures += 0 if present else 1

    print("\n=== Correctifs develop réappliqués ===")
    for relative, needle, label in DEVELOP_FIXES:
        path = ROOT / relative
        content = path.read_text(encoding="utf-8") if path.is_file() else ""
        ok = needle in content
        print(f"{'PASS' if ok else 'FAIL'} {label}")
        failures += 0 if ok else 1

    print("\n=== Ascendance Git ===")
    # En CI, un skip silencieux vaudrait faux vert : on exige le contrôle.
    required = os.environ.get("TUC_REQUIRE_ANCESTRY") == "1"
    shallow = git("rev-parse", "--is-shallow-repository").stdout.strip() == "true"
    develop = git("rev-parse", "--verify", "origin/develop")
    if shallow or develop.returncode != 0:
        reason = "historique shallow" if shallow else "origin/develop non fetché"
        if required:
            print(f"FAIL {reason} : ascendance non vérifiable alors que TUC_REQUIRE_ANCESTRY=1")
            print("      Utiliser fetch-depth: 0 et fetcher develop avant ce test.")
            failures += 1
        else:
            print(f"SKIP {reason} (local uniquement ; la CI exige ce contrôle)")
    else:
        result = git("merge-base", "--is-ancestor", "origin/develop", "HEAD")
        ok = result.returncode == 0
        head = git("rev-parse", "--short", "HEAD").stdout.strip()
        tip = git("rev-parse", "--short", "origin/develop").stdout.strip()
        print(f"{'PASS' if ok else 'FAIL'} origin/develop ({tip}) est ancêtre de HEAD ({head})")
        if not ok:
            missing = git("rev-list", "--count", "HEAD..origin/develop").stdout.strip()
            print(f"      {missing} commits de develop sont absents : merger develop avant de déclarer l'intégration")
        failures += 0 if ok else 1

    print(f"\n{failures} échec(s)")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
