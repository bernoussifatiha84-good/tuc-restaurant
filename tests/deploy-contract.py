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
import textwrap
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

# Verbes de dispatch déclarés. Toute évolution de cette liste doit être voulue :
# `deploy` écrit le code, les autres sont des outils sans écriture de code.
KNOWN_OPERATIONS = ["deploy", "inventory-woocommerce", "seed-demo", "seed-demo-uat"]

# Cible que chaque outil a le DROIT de viser. Liste fermee : un verbe ajoute au
# YAML sans etre declare ici casse « la liste declaree passe la garde » ; un
# verbe declare qui vise une autre cible casse son cas de resolution. Aucun
# outil ne vise prd : la production n'a pas de secrets.
TOOL_TARGETS = {
    "inventory-woocommerce": "uat",
    "seed-demo": "dev",
    "seed-demo-uat": "uat",
}

# Topologie réelle : l'UAT est un sous-répertoire du dev. C'est le cas
# dangereux — une racine dev mal renseignée peut désigner l'UAT.
PERIMETER_VARS = {
    "SSH_HOST": "host.example.invalid",
    "SSH_USER": "deploy",
    "SSH_PRIVATE_KEY": "cle-factice",
    "SITE_URL": "https://dev.example.invalid",
    "URL_PRD": "https://prd.example.invalid",
    "ROOT_DEV": "sites/example/public_html",
    "ROOT_UAT": "sites/example/public_html/uat",
    "ROOT_PRD": "sites/prod/public_html",
}

# (libellé, ENV_NAME, SSH_ROOT, overrides, code_attendu)
PERIMETER_CASES = [
    ("dev + ROOT_DEV", "dev", "sites/example/public_html", {}, 0),
    ("uat + ROOT_UAT", "uat", "sites/example/public_html/uat", {}, 0),
    ("prd refusé", "prd", "sites/prod/public_html", {}, 1),
    ("environnement inconnu refusé", "banana", "sites/example/public_html", {}, 1),
    ("dev pointé sur l'UAT refusé", "dev", "sites/example/public_html/uat", {}, 1),
    ("uat pointé sur le dev refusé", "uat", "sites/example/public_html", {}, 1),
    ("ROOT_DEV finissant par /uat refusé", "dev", "sites/autre/uat",
     {"ROOT_DEV": "sites/autre/uat"}, 1),
    ("cible = ROOT_PRD refusée", "dev", "sites/prod/public_html", {}, 1),
    ("cible = URL_PRD refusée", "dev", "sites/example/public_html",
     {"SITE_URL": "https://prd.example.invalid"}, 1),
    ("secret SSH manquant refusé", "dev", "sites/example/public_html",
     {"SSH_HOST": ""}, 1),
]

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
    ("dispatch seed-demo -> dev", "branch", "feature/x", "workflow_dispatch", "seed-demo", FAKE_VARS, 0, "dev"),
    ("dispatch seed-demo-uat -> uat", "branch", "feature/x", "workflow_dispatch", "seed-demo-uat", FAKE_VARS, 0, "uat"),
    # Un outil manuel ne doit jamais devenir une ecriture production, meme si le
    # dispatch est lance depuis un tag de release.
    ("dispatch seed-demo sur tag release reste dev", "tag", "release-0.9.0", "workflow_dispatch", "seed-demo", FAKE_VARS, 0, "dev"),
    ("dispatch seed-demo-uat sur tag release reste uat", "tag", "release-0.9.0", "workflow_dispatch", "seed-demo-uat", FAKE_VARS, 0, "uat"),
    ("dispatch inventaire sur tag release reste uat", "tag", "release-0.9.0", "workflow_dispatch", "inventory-woocommerce", FAKE_VARS, 0, "uat"),
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


def check_guards(text: str | None = None) -> list[tuple[bool, str]]:
    """Garde-fous structurels du workflow, indépendants du script de résolution.

    `text` est injectable pour permettre à `check_option_list_is_closed()` de
    prouver que la garde refuse réellement une option non déclarée.
    """
    if text is None:
        text = WORKFLOW.read_text(encoding="utf-8")
    document = yaml.safe_load(text)
    results = []

    dispatch = document[True]["workflow_dispatch"]  # YAML parse `on` en booléen True
    operation = dispatch["inputs"]["operation"]
    results.append((
        operation.get("type") == "choice",
        f"input operation est de type {operation.get('type')!r} (attendu 'choice', pas une saisie libre)",
    ))
    # Liste exacte, volontairement. Une liste « au moins celles-ci » laisserait
    # ajouter un verbe sans le déclarer ici : le résolveur retomberait alors sur
    # le routage par référence et le job `deploiement`, qui n'exclut que les
    # verbes nommés, se mettrait à DÉPLOYER pour ce nouveau verbe. Déclarer une
    # nouvelle opération coûte une ligne : c'est le prix du fail-closed.
    results.append((
        operation.get("options") == KNOWN_OPERATIONS,
        f"options operation = {operation.get('options')} (attendu {KNOWN_OPERATIONS})",
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
    # Méta-garde : le garde-fou de version ne doit pas disparaître discrètement.
    results.append((
        "tests/version-contract.py" in text,
        "le job qualité appelle le garde-fou de version",
    ))
    results.append((
        "Incohérence de version::en-tête" in text
        and "Tag et version divergents::" in text,
        "verrou en-tête/constante et tag/version présent avant le rsync",
    ))
    return results


def perimeter_step(job) -> str | None:
    """Le step de validation de périmètre d'un job outil, s'il existe."""
    for step in job.get("steps", []):
        run = str(step.get("run", ""))
        if 'case "$ENV_NAME"' in run:
            return run
    return None


def run_perimeter(script: str, env_name: str, root: str, overrides: dict) -> tuple[int, str]:
    env = {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        **PERIMETER_VARS,
        "ENV_NAME": env_name,
        "SSH_ROOT": root,
        **overrides,
    }
    proc = subprocess.run(
        ["bash", "-c", script], env=env, capture_output=True, text=True, timeout=30
    )
    return proc.returncode, (proc.stdout + proc.stderr).strip()


def perimeter_refuses_unknown(job) -> bool:
    """Un refus n'est fermé que si un environnement imprévu arrête le job."""
    script = perimeter_step(job)
    if script is None:
        return False
    for env_name in ("prd", "banana", ""):
        code, _ = run_perimeter(script, env_name, PERIMETER_VARS["ROOT_DEV"], {})
        if code == 0:
            return False
    return True


def check_dev_guard_harness() -> list[tuple[bool, str]]:
    """Exécute le harnais du garde-fou dev, qui n'était appelé par personne.

    `scripts/preuve-garde-dev.py` extrait le vrai step, substitue les expressions
    GitHub, shimme `ssh` et joue 7 cas. Il était correct mais jamais invoqué : la
    CI n'appelle que `tests/…`. Un garde-fou dont la preuve ne tourne pas n'est pas
    prouvé. On l'appelle depuis ce contrat, déjà câblé dans la CI, et chaque cas
    devient une assertion. Sa disparition rendrait ce contrôle rouge.
    """
    harness = WORKFLOW.parent.parent.parent / "scripts" / "preuve-garde-dev.py"
    if not harness.is_file():
        return [(False, f"harnais du garde-fou dev absent : {harness.name}")]
    proc = subprocess.run(
        [sys.executable, str(harness)], capture_output=True, text=True, timeout=180
    )
    lines = [l for l in proc.stdout.splitlines() if l.startswith(("PASS ", "FAIL "))]
    results = [(l.startswith("PASS "), l[5:]) for l in lines]
    if not results:
        detail = (proc.stdout + proc.stderr).strip().splitlines()
        results.append((False, "harnais dev sans cas : " + (detail[-1][:150] if detail else "aucune sortie")))
    results.append((proc.returncode == 0, f"harnais du garde-fou dev : {len(lines)} cas, code {proc.returncode}"))
    return results


def check_seed_perimeter_behaviour() -> list[tuple[bool, str]]:
    """Exécute le VRAI step de périmètre des jobs outils, sans aucun accès distant.

    Le step ne fait que des comparaisons de chaînes : il est testable tel quel.
    On vérifie le comportement attendu, pas la présence d'un `case`.
    """
    document = yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))
    results = []
    found = False
    for name, job in document["jobs"].items():
        # Jobs outils seulement : le garde-fou du job de déploiement accepte
        # légitimement dev/uat/prd et se teste avec un autre jeu de cas. On
        # reconnaît un job outil à son verbe, pas à la présence de
        # `inputs.operation` — le job de déploiement le mentionne aussi.
        if not any(f"inputs.operation == '{verb}'" in str(job.get("if", "")) for verb in TOOL_TARGETS):
            continue
        script = perimeter_step(job)
        if script is None:
            continue
        found = True
        for label, env_name, root, overrides, expected in PERIMETER_CASES:
            code, output = run_perimeter(script, env_name, root, overrides)
            ok = (code != 0) if expected else (code == 0)
            detail = f"{name} / {label} : code {code}"
            if not ok and output:
                detail += f" — {output.splitlines()[-1][:150]}"
            results.append((ok, detail))
    if not found:
        results.append((False, "aucun step de périmètre trouvé dans les jobs outils"))
    return results


def check_operations_behaviour() -> list[tuple[bool, str]]:
    """Chaque verbe de dispatch doit être porté et cantonné, pas seulement listé.

    La liste fermée dit « rien de nouveau sans le déclarer ». Ce contrôle-ci dit
    ce qu'un verbe déclaré a le droit de faire : exclu du déploiement, porté par
    un job à environnement littéral non-production, et résolu hors production.
    """
    document = yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))
    jobs = document["jobs"]
    deploy_if = str(jobs["deploiement"].get("if", ""))
    script = resolve_script()
    results = []

    # Seule la forme POSITIVE est acceptée : `|| inputs.operation == 'deploy'`.
    # Une énumération des verbes à exclure est ouverte par défaut — un verbe ajouté
    # au YAML sans être nommé ici ferait déployer le job. Elle est donc refusée
    # même lorsqu'elle exclut correctement les verbes du jour. Ce contrôle porte
    # sur le job lui-même, pas sur un verbe : il est fait une seule fois.
    results.append((
        "inputs.operation == 'deploy'" in deploy_if,
        "le job de déploiement n'écrit que pour operation == 'deploy' (forme fail-closed)",
    ))

    for option in KNOWN_OPERATIONS:
        if option == "deploy":
            continue


        carriers = [
            name for name, job in jobs.items()
            if f"inputs.operation == '{option}'" in str(job.get("if", ""))
        ]
        results.append((
            len(carriers) == 1,
            f"{option} : porté par un job dédié et un seul {carriers}",
        ))
        if len(carriers) != 1:
            continue

        # L'environnement du job porte les secrets SSH. Il peut etre litteral
        # (non-production) ou delegue au resolveur — jamais la production. La
        # delegation n'est acceptee qu'avec un refus ferme : le job doit fermer
        # lui-meme la liste des environnements qu'il tolere, sinon le resolveur
        # deciderait seul de la cible d'un outil.
        job = jobs[carriers[0]]
        environment = job.get("environment")
        delegated = environment == "${{ needs.cible.outputs.env }}"
        # Refus fermé prouvé par EXÉCUTION, pas par présence de texte. Chercher
        # `case … in` et `*)` ne dit pas que la branche par défaut refuse : une
        # branche `*) echo … ;;` sans exit passe une garde de forme. On exécute
        # donc le step avec un environnement inconnu et on exige un refus.
        clos = perimeter_refuses_unknown(job)
        litteral_non_prod = (
            isinstance(environment, str)
            and environment not in ("prd", "production")
            and "${" not in environment
        )
        results.append((
            litteral_non_prod or (delegated and clos),
            f"{option} : environnement non-production, litteral ou delegue avec refus ferme ({environment!r})",
        ))
        results.append((
            "github.event_name == 'workflow_dispatch'" in str(jobs[carriers[0]].get("if", "")),
            f"{option} : déclenchement manuel uniquement, jamais sur push",
        ))

        # La cible attendue vient de TOOL_TARGETS : un outil vise la cible
        # declaree (seed-demo -> dev, banc d'essai MariaDB de l'hebergeur) et
        # jamais prd, quel que soit le type de reference du dispatch.
        expected_env = TOOL_TARGETS[option]
        for ref_type, ref_name in (("branch", "feature/x"), ("tag", "release-0.9.0")):
            ok, detail = run_case(script, (
                f"{option} depuis {ref_type} {ref_name}",
                ref_type, ref_name, "workflow_dispatch", option, FAKE_VARS, 0, expected_env,
            ))
            results.append((ok, detail))

    return results


def check_option_list_is_closed() -> list[tuple[bool, str]]:
    """Prouve que la garde refuse une 4e opération, au lieu de le supposer.

    Sans ce test, « liste exacte » pourrait redevenir « liste ouverte » sans que
    rien ne rougisse — exactement la régression que ce contrôle empêche.
    """
    original = WORKFLOW.read_text(encoding="utf-8")
    document = yaml.safe_load(original)
    options = document[True]["workflow_dispatch"]["inputs"]["operation"]["options"]
    results = []

    def guard_passes(text: str) -> bool:
        return all(ok for ok, detail in check_guards(text) if "options operation" in detail)

    results.append((
        guard_passes(original),
        "la liste déclarée passe la garde",
    ))

    # Ajout d'un verbe non déclaré, à l'identique de ce que ferait un commit.
    intrus = original.replace(
        "\n".join(f"          - {o}" for o in options),
        "\n".join(f"          - {o}" for o in [*options, "reset-db"]),
        1,
    )
    if intrus == original:
        results.append((False, "injection d'une 4e opération impossible : format YAML inattendu"))
        return results

    injected = yaml.safe_load(intrus)[True]["workflow_dispatch"]["inputs"]["operation"]["options"]
    results.append((
        injected == [*options, "reset-db"],
        f"4e opération injectée dans le YAML {injected}",
    ))
    results.append((
        not guard_passes(intrus),
        "une 4e opération non déclarée est REFUSÉE par la garde",
    ))
    return results


def backup_script() -> str:
    """Extrait le heredoc distant du step de sauvegarde, sans l'interpréter."""
    doc = yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))
    for step in doc["jobs"]["deploiement"]["steps"]:
        if "Sauvegarder" in step.get("name", ""):
            run = step.get("run", "")
            if "<<'REMOTE'" not in run:
                raise AssertionError("step de sauvegarde sans heredoc distant quoted")
            body = run.split("<<'REMOTE'", 1)[1].rsplit("REMOTE", 1)[0]
            return textwrap.dedent(body)
    raise AssertionError("step de sauvegarde introuvable dans deploy.yml")


def check_backup_behaviour() -> list[tuple[bool, str]]:
    """Teste le COMPORTEMENT du garde-fou de sauvegarde, pas la forme du code.

    Une assertion sur une chaîne (« test -s présent ? ») laisserait passer un
    refactor qui déplace la vérification hors du chemin utile. On exécute donc le
    script distant réel sur des arborescences fabriquées et on juge le code de
    sortie et les archives produites.
    """
    script = backup_script()
    results: list[tuple[bool, str]] = []

    def peupler(root: str, relatif: str, fichiers: list[str]) -> None:
        dossier = Path(root) / relatif
        dossier.mkdir(parents=True, exist_ok=True)
        for nom in fichiers:
            cible = dossier / nom
            cible.parent.mkdir(parents=True, exist_ok=True)
            cible.write_text("contenu", encoding="utf-8")

    def executer(root: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            ["bash", "-c", script, "_", root],
            capture_output=True, text=True,
        )

    def archives(root: str) -> list[Path]:
        return sorted((Path(root) / "wp-content" / "upgrade").rglob("*.tgz"))

    scenarios = [
        (
            "deux sources peuplees -> 2 archives non vides, code 0",
            lambda r: (
                peupler(r, "wp-content/themes/tuc-theme", ["style.css", "index.php"]),
                peupler(r, "wp-content/plugins/tuc-market-core", ["tuc-market-core.php"]),
            ),
            0, 2, False,
        ),
        (
            "source vide -> refus avant rsync (le cas d'origine du faux vert)",
            lambda r: peupler(r, "wp-content/themes/tuc-theme", []),
            1, None, False,
        ),
        (
            "theme seul peuple -> 1 archive, code 0",
            lambda r: peupler(r, "wp-content/themes/tuc-theme", ["style.css"]),
            0, 1, False,
        ),
        (
            "aucune source -> code 0 mais avertissement explicite, jamais silencieux",
            lambda r: None,
            0, 0, True,
        ),
    ]

    for label, setup, code_attendu, nb_attendu, warning_attendu in scenarios:
        with tempfile.TemporaryDirectory() as root:
            setup(root)
            proc = executer(root)
            produced = archives(root)
            ok = proc.returncode == code_attendu
            if ok and nb_attendu is not None:
                ok = len(produced) == nb_attendu
            if ok and nb_attendu:
                ok = all(a.stat().st_size > 0 for a in produced)
            if ok and warning_attendu:
                ok = "::warning::" in proc.stdout
            sortie = (proc.stdout + proc.stderr).strip().splitlines()
            detail = f"{label} (code {proc.returncode}, {len(produced)} archive(s))"
            if not ok and sortie:
                detail += f" — {sortie[-1][:160]}"
            results.append((ok, detail))

    # Cinquième scénario : injection de panne. Un `tar` qui réussit puis laisse
    # une archive tronquée (coupure disque, quota) doit être refusé. On ne dépend
    # ni des droits root ni d'un fichier illisible : on intercepte tar via PATH.
    with tempfile.TemporaryDirectory() as tmp:
        racine = Path(tmp) / "root"
        source = racine / "wp-content" / "themes" / "tuc-theme"
        source.mkdir(parents=True)
        for i in range(30):
            (source / f"f{i}.txt").write_text("x" * 2000, encoding="utf-8")

        reel = subprocess.run(
            ["bash", "-c", "command -v tar"], capture_output=True, text=True, check=True
        ).stdout.strip()
        bin_dir = Path(tmp) / "bin"
        bin_dir.mkdir()
        faux = bin_dir / "tar"
        faux.write_text(
            f"#!/bin/bash\n{reel} \"$@\"\nrc=$?\n"
            "for a in \"$@\"; do case \"$a\" in *.tgz)"
            " [ -f \"$a\" ] && truncate -s $(( $(stat -c%s \"$a\") / 2 )) \"$a\";;"
            " esac; done\nexit $rc\n",
            encoding="utf-8",
        )
        faux.chmod(0o755)
        env = {**os.environ, "PATH": f"{bin_dir}{os.pathsep}{os.environ['PATH']}"}
        proc = subprocess.run(
            ["bash", "-c", script, "_", str(racine)],
            capture_output=True, text=True, env=env,
        )
        ok = proc.returncode == 1
        detail = f"archive tronquee -> refus (code {proc.returncode})"
        if not ok:
            lignes = (proc.stdout + proc.stderr).strip().splitlines()
            if lignes:
                detail += f" — {lignes[-1][:160]}"
        results.append((ok, detail))

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

    print("\n=== Cantonnement des verbes de dispatch (résolveur réel exécuté) ===")
    for ok, detail in check_operations_behaviour():
        print(f"{'PASS' if ok else 'FAIL'} {detail}")
        failures += 0 if ok else 1

    print("\n=== Périmètre des jobs outils : step réel exécuté, sans accès distant ===")
    for ok, detail in check_seed_perimeter_behaviour():
        print(f"{'PASS' if ok else 'FAIL'} {detail}")
        failures += 0 if ok else 1

    print("\n=== Garde-fou dev : harnais réel exécuté (ssh shimmé, aucun accès) ===")
    for ok, detail in check_dev_guard_harness():
        print(f"{'PASS' if ok else 'FAIL'} {detail}")
        failures += 0 if ok else 1

    print("\n=== La liste des verbes est-elle réellement fermée ? (mutation testée) ===")
    for ok, detail in check_option_list_is_closed():
        print(f"{'PASS' if ok else 'FAIL'} {detail}")
        failures += 0 if ok else 1

    print("\n=== Comportement du garde-fou de sauvegarde (script réel exécuté) ===")
    for ok, detail in check_backup_behaviour():
        print(f"{'PASS' if ok else 'FAIL'} {detail}")
        failures += 0 if ok else 1

    print(f"\n{len(CASES)} cas de résolution + garde-fous : {failures} échec(s)")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
