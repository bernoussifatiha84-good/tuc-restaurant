#!/usr/bin/env python3
"""Garde-fou de cohérence des versions, avant tout rsync.

Origine : le tag `pre-release-0.8.9` a été publié et déployé avec
`* Version: 0.8.8` dans l'en-tête et `TUC_CORE_VERSION = '0.8.9'` dans la
constante. Le contrôle de santé du déploiement lit la constante ; `wp plugin get`
lit l'en-tête. L'UAT a donc affiché deux versions différentes et le job est resté
vert. Ce script ferme ce trou.

Deux règles :
1. En-tête du plugin == constante TUC_CORE_VERSION (toujours).
2. Un tag de déploiement `pre-release-X.Y.Z[-suffixe]` ou `release-X.Y.Z[-suffixe]`
   porte la version plugin X.Y.Z. Le suffixe est permis : `pre-release-0.8.5-uat.1`
   déployait légitimement le plugin 0.8.5.

Périmètre : la règle 2 s'applique à la référence en cours de déploiement, jamais
rétroactivement. Les tags antérieurs à 0.8.0 suivaient une autre convention et ne
sont pas re-validés ; l'historique n'est pas réécrit.

`--self-test` rejoue le contenu exact du tag fautif pour prouver que le garde-fou
le refuse. Un garde-fou qui n'a jamais rejeté son propre cas d'origine n'est pas
prouvé.
"""

from __future__ import annotations

import argparse
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PLUGIN = ROOT / "wordpress" / "tuc-market-core" / "tuc-market-core.php"
THEME = ROOT / "wordpress" / "tuc-theme" / "style.css"

HEADER_RE = re.compile(r"^[ \t]*\*[ \t]*Version:[ \t]*(\S+)", re.M)
CONST_RE = re.compile(r"const\s+TUC_CORE_VERSION\s*=\s*'([^']*)'")
THEME_RE = re.compile(r"^Version:[ \t]*(\S+)", re.M)
TAG_RE = re.compile(r"^(?:pre-release|release)-(\d+\.\d+\.\d+)(?:[-.][0-9A-Za-z.\-]+)?$")


def read_versions(php: str, css: str) -> tuple[str | None, str | None, str | None]:
    header = HEADER_RE.search(php)
    constant = CONST_RE.search(php)
    theme = THEME_RE.search(css)
    return (
        header.group(1) if header else None,
        constant.group(1) if constant else None,
        theme.group(1) if theme else None,
    )


def check(php: str, css: str, ref_name: str, ref_type: str) -> list[str]:
    """Retourne la liste des problèmes. Vide == cohérent."""
    header, constant, theme = read_versions(php, css)
    problems: list[str] = []

    if header is None:
        problems.append("en-tête `* Version:` introuvable dans tuc-market-core.php")
    if constant is None:
        problems.append("constante TUC_CORE_VERSION introuvable dans tuc-market-core.php")
    if theme is None:
        problems.append("en-tête `Version:` introuvable dans wordpress/tuc-theme/style.css")
    if problems:
        return problems

    if header != constant:
        problems.append(
            f"en-tête plugin={header} != constante TUC_CORE_VERSION={constant} ; "
            "le health check lit la constante, `wp plugin get` lit l'en-tête"
        )

    if ref_type == "tag":
        match = TAG_RE.match(ref_name)
        if match:
            expected = match.group(1)
            if constant != expected:
                problems.append(
                    f"tag {ref_name} attend la version plugin {expected}, "
                    f"mais la constante vaut {constant}"
                )
        elif ref_name.startswith(("pre-release-", "release-")):
            problems.append(
                f"tag de déploiement {ref_name} sans version X.Y.Z exploitable"
            )

    return problems


def self_test() -> int:
    """Rejoue les cas réels, y compris le tag fautif, sans toucher au dépôt."""
    php_ok = (
        "<?php\n/**\n * Plugin Name: TUC Market Core\n * Version: 0.8.9\n */\n"
        "const TUC_CORE_VERSION = '0.8.9';\n"
    )
    css_ok = "/*\nTheme Name: TUC Market\nVersion: 0.2.0\n*/\n"
    # Contenu exact du tag pre-release-0.8.9 : en-tête 0.8.8, constante 0.8.9.
    php_bug = php_ok.replace("* Version: 0.8.9", "* Version: 0.8.8")

    cases = [
        ("en-tête == constante, branche", php_ok, css_ok, "develop", "branch", True),
        ("tag aligné", php_ok, css_ok, "pre-release-0.8.9", "tag", True),
        ("tag avec suffixe uat", php_ok, css_ok, "pre-release-0.8.9-uat.1", "tag", True),
        ("tag release aligné", php_ok, css_ok, "release-0.8.9", "tag", True),
        ("BUG RÉEL 0.8.9 : en-tête 0.8.8 / constante 0.8.9", php_bug, css_ok, "pre-release-0.8.9", "tag", False),
        ("BUG RÉEL 0.8.10 : tag 0.8.10 / contenu 0.8.9", php_ok, css_ok, "pre-release-0.8.10", "tag", False),
        ("en-tête absent", php_ok.replace(" * Version: 0.8.9\n", ""), css_ok, "develop", "branch", False),
        ("constante absente", php_ok.replace("const TUC_CORE_VERSION = '0.8.9';\n", ""), css_ok, "develop", "branch", False),
        ("tag sans version exploitable", php_ok, css_ok, "pre-release-next", "tag", False),
        ("tag hors convention ignoré", php_ok, css_ok, "v1.2.3", "tag", True),
    ]

    failures = 0
    print("=== Auto-test : le garde-fou rejette-t-il son cas d'origine ? ===")
    for label, php, css, ref, kind, should_pass in cases:
        problems = check(php, css, ref, kind)
        passed = not problems
        ok = passed is should_pass
        verdict = "PASS" if ok else "FAIL"
        detail = "accepté" if passed else f"refusé ({problems[0]})"
        print(f"{verdict} {label} : {detail}")
        failures += 0 if ok else 1
    return failures


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--self-test", action="store_true", help="rejouer les cas connus donc le tag fautif")
    parser.add_argument("--ref-name", default=os.environ.get("GITHUB_REF_NAME", ""))
    parser.add_argument("--ref-type", default=os.environ.get("GITHUB_REF_TYPE", ""))
    args = parser.parse_args()

    if args.self_test:
        failures = self_test()
        print(f"\n{failures} échec(s) d'auto-test")
        return 1 if failures else 0

    php = PLUGIN.read_text(encoding="utf-8")
    css = THEME.read_text(encoding="utf-8")
    header, constant, theme = read_versions(php, css)
    print(f"En-tête plugin : {header}")
    print(f"Constante      : {constant}")
    print(f"Thème          : {theme}")
    print(f"Référence      : {args.ref_type or '(non fournie)'} {args.ref_name or '(aucune)'}")

    problems = check(php, css, args.ref_name, args.ref_type)
    if problems:
        print("\nINCOHÉRENCE DE VERSION :")
        for problem in problems:
            print(f"  - {problem}")
        return 1
    print("\nPASS versions cohérentes")
    return 0


if __name__ == "__main__":
    sys.exit(main())
