#!/usr/bin/env python3
"""Preuve que le garde-fou dev de deploy.yml refuse vraiment les cas dangereux.

`scripts/` n'appartient a personne en particulier ; `tests/` est tenu par la
session concurrente, ce harnais ne l'occupe donc pas. Il extrait le VRAI script
du step depuis deploy.yml,
remplace les expressions GitHub par des valeurs de test, et l'execute.

Un garde-fou qui n'a jamais rejete n'est pas prouve.
"""
from __future__ import annotations

import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

import yaml

WORKFLOW = Path(__file__).resolve().parent.parent / ".github" / "workflows" / "deploy.yml"
STEP_NAME = "Vérifier que la cible DEV"

VARS = {
    "ROOT_UAT": "domains/site.hostingersite.com/public_html/uat",
    "URL_UAT": "https://site.hostingersite.com/uat",
    "ROOT_PRD": "domains/tuc.ma/public_html",
    "URL_PRD": "https://tuc.ma",
}


def extract() -> tuple[str, dict[str, str]]:
    """Retourne le script ET l'environnement declare par le step."""
    doc = yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))
    for step in doc["jobs"]["deploiement"]["steps"]:
        if step.get("name", "").startswith(STEP_NAME):
            return step["run"], dict(step.get("env") or {})
    raise SystemExit("step garde-fou dev introuvable")


def render(script: str, root: str, url: str) -> str:
    """Remplace les expressions GitHub par des valeurs concretes."""
    def sub(match: str) -> str:
        expr = match.group(1).strip()
        # `vars.X || 'defaut'` : on prend la valeur de test, sinon le defaut
        m = re.fullmatch(r"vars\.([A-Z_]+)\s*\|\|\s*'([^']*)'", expr)
        if m:
            return str(VARS.get(m.group(1), m.group(2)))
        m = re.fullmatch(r"vars\.([A-Z_]+)", expr)
        if m:
            return str(VARS.get(m.group(1), ""))
        if expr.startswith("secrets."):
            return "u000000000_factice"
        if expr.startswith("needs.cible.outputs"):
            return ""
        raise SystemExit(f"expression inattendue : {expr}")

    out = re.sub(r"\$\{\{\s*(.*?)\s*\}\}", sub, script)
    out = out.replace('"$SSH_ROOT"', f'"{root}"')
    out = out.replace('"$SITE_URL"', f'"{url}"')
    return out


def run_case(label: str, root: str, url: str, expected_ok: bool, remote_url: str | None = None) -> bool:
    raw_script, step_env = extract()
    remote_url = remote_url or url
    script = render(raw_script, root, url)
    # l'environnement est declare par le step : on le reproduit, expressions resolues
    env_vars = {k: render(str(v), root, url) for k, v in step_env.items()}
    env_vars["SSH_ROOT"] = root
    env_vars["SITE_URL"] = url
    with tempfile.TemporaryDirectory() as tmp:
        # shim `ssh` : renvoie l'URL que WordPress aurait sur la cible
        shim = Path(tmp) / "ssh"
        shim.write_text(f"#!/bin/sh\necho '{remote_url}'\n", encoding="utf-8")
        shim.chmod(0o755)
        env = dict(os.environ, PATH=f"{tmp}:{os.environ['PATH']}", HOME=tmp, **env_vars)
        proc = subprocess.run(
            ["bash", "-c", script], capture_output=True, text=True, env=env, timeout=60
        )
    ok = proc.returncode == 0
    verdict = "PASS" if ok is expected_ok else "FAIL"
    detail = (proc.stdout + proc.stderr).strip().splitlines()
    detail = next((l for l in detail if "::error::" in l or "vérifiée" in l), "?")
    print(f"{verdict} {label} : {detail}")
    return ok is expected_ok


def main() -> int:
    dev_root = "domains/site.hostingersite.com/public_html"
    dev_url = "https://site.hostingersite.com"
    cases = [
        ("cible dev legitime", dev_root, dev_url, True),
        ("racine = celle de l'UAT", VARS["ROOT_UAT"], VARS["URL_UAT"], False),
        ("racine = celle de la prod", VARS["ROOT_PRD"], VARS["URL_PRD"], False),
        ("URL = celle de l'UAT", dev_root, VARS["URL_UAT"], False),
        ("URL = celle de la prod", dev_root, VARS["URL_PRD"], False),
        # suffixe /uat sans egalite exacte : prouve la garde de suffixe elle-meme
        ("racine terminee par /uat (autre site)", "domains/autre.hostingersite.com/public_html/uat", dev_url, False),
        # siteurl distant different de URL_DEV : mauvaise cible
        ("siteurl distant incoherent", dev_root, dev_url, False, "https://ailleurs.example"),
    ]
    failures = sum(0 if run_case(*c) else 1 for c in cases)
    print(f"\n{len(cases)} cas, {failures} echec(s)")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
