#!/usr/bin/env python3
"""Exécute le VRAI script de résolution de cible de deploy.yml, sans déployer.

Le but est de prouver le contrat « aucune écriture hors develop / pre-release-* /
release-* » après toute réécriture du workflow. On extrait le script `run` du job
`cible` depuis le YAML puis on l'exécute en bash avec des variables factices, en
vérifiant le code de sortie et l'environnement résolu.

Aucun accès réseau, aucun SSH, aucun secret réel : les URL/racines sont fictives.
"""

from __future__ import annotations

import os
import subprocess
import sys
import tempfile
from pathlib import Path

import yaml

WORKFLOW = Path(__file__).resolve().parent.parent / ".github" / "workflows" / "deploy.yml"

FAKE_VARS = {
    "URL_DEV": "https://dev.example.invalid",
    "ROOT_DEV": "sites/dev/public_html",
    "URL_UAT": "https://uat.example.invalid",
    "ROOT_UAT": "sites/uat/public_html",
    "URL_PRD": "https://prd.example.invalid",
    "ROOT_PRD": "sites/prd/public_html",
}

# (libellé, ref_type, ref_name, event, operation, vars, code_attendu, env_attendu)
CASES = [
    ("branche develop -> dev", "branch", "develop", "push", "", FAKE_VARS, 0, "dev"),
    ("tag pre-release -> uat", "tag", "pre-release-0.8.9", "push", "", FAKE_VARS, 0, "uat"),
    ("tag release -> prd", "tag", "release-0.9.0", "push", "", FAKE_VARS, 0, "prd"),
    ("branche de travail refusée", "branch", "arena/e8ae5431-tuc-restaurant", "push", "", FAKE_VARS, 1, None),
    ("branche main refusée", "branch", "main", "push", "", FAKE_VARS, 1, None),
    ("tag quelconque refusé", "tag", "v1.2.3", "push", "", FAKE_VARS, 1, None),
    ("dispatch deploy depuis branche refusée", "branch", "feature/x", "workflow_dispatch", "deploy", FAKE_VARS, 1, None),
    ("dispatch inventaire -> uat", "branch", "feature/x", "workflow_dispatch", "inventory-woocommerce", FAKE_VARS, 0, "uat"),
    ("prd non configurée refusée", "tag", "release-0.9.0", "push", "", {**FAKE_VARS, "URL_PRD": "", "ROOT_PRD": ""}, 1, None),
    ("uat non configurée refusée", "tag", "pre-release-0.8.9", "push", "", {**FAKE_VARS, "URL_UAT": "", "ROOT_UAT": ""}, 1, None),
]


def resolve_script() -> str:
    document = yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))
    steps = document["jobs"]["cible"]["steps"]
    for step in steps:
        if step.get("id") == "resolve":
            return step["run"]
    raise SystemExit("ÉCHEC : step 'resolve' introuvable dans deploy.yml")


def run_case(script: str, case) -> tuple[bool, str]:
    label, ref_type, ref_name, event, operation, variables, expected_code, expected_env = case
    with tempfile.TemporaryDirectory() as tmp:
        output = Path(tmp) / "github_output"
        output.touch()
        env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "GITHUB_OUTPUT": str(output),
            "REF_TYPE": ref_type,
            "REF_NAME": ref_name,
            "EVENT_NAME": event,
            "OPERATION": operation,
            **variables,
        }
        result = subprocess.run(
            ["bash", "-c", script], env=env, capture_output=True, text=True, timeout=30
        )
        produced = output.read_text(encoding="utf-8")
        if result.returncode != expected_code:
            return False, f"{label} : code {result.returncode}, attendu {expected_code}\n{result.stdout}{result.stderr}"
        if expected_env is None:
            if "env=" in produced:
                return False, f"{label} : une cible a été résolue alors que le refus était attendu"
            return True, f"{label} : refusé sans cible (code {result.returncode})"
        if f"env={expected_env}\n" not in produced:
            return False, f"{label} : environnement résolu inattendu\n{produced}"
        return True, f"{label} : {expected_env} (code {result.returncode})"


def check_guards() -> list[tuple[bool, str]]:
    """Garde-fous structurels du workflow, indépendants du script de résolution."""
    text = WORKFLOW.read_text(encoding="utf-8")
    document = yaml.safe_load(text)
    results = []

    dispatch = document[True]["workflow_dispatch"]  # YAML parse `on` en booléen True
    operation = dispatch["inputs"]["operation"]
    results.append((
        operation.get("type") == "choice",
        f"input operation est de type {operation.get('type')!r} (attendu 'choice', pas une saisie libre)",
    ))
    results.append((
        operation.get("options") == ["deploy", "inventory-woocommerce"],
        f"options operation = {operation.get('options')}",
    ))

    deploy_job = document["jobs"]["deploiement"]
    results.append((
        deploy_job.get("environment") == "${{ needs.cible.outputs.env }}",
        "le job de déploiement est lié à un environnement GitHub (approbations possibles)",
    ))
    results.append((
        "Sauvegarder les répertoires avant rsync --delete" in text,
        "une sauvegarde précède le rsync --delete",
    ))
    results.append((
        "ROOT_UAT pointe vers la même racine que ROOT_PRD" in text,
        "garde-fou UAT vs production présent",
    ))
    results.append((
        "La version du plugin déployée ne correspond pas" in text
        and "La version du thème déployée ne correspond pas" in text,
        "le contrôle de santé vérifie les versions déployées",
    ))
    return results


def main() -> int:
    script = resolve_script()
    failures = 0
    print("=== Résolution de cible : script réel extrait de deploy.yml ===")
    for case in CASES:
        ok, detail = run_case(script, case)
        print(f"{'PASS' if ok else 'FAIL'} {detail}")
        failures += 0 if ok else 1

    print("\n=== Garde-fous structurels ===")
    for ok, detail in check_guards():
        print(f"{'PASS' if ok else 'FAIL'} {detail}")
        failures += 0 if ok else 1

    print(f"\n{len(CASES)} cas de résolution + garde-fous : {failures} échec(s)")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
