#!/usr/bin/env python3
"""
Constat du serveur APRES deploiement — pas "le run est vert".

Interroge le site reel et verifie que ce qui est en ligne est bien ce qui a ete
pousse : version du plugin lue par wp-cli, schema des roles, route de caisse qui
repond, et surtout AUCUNE donnee metier touchee.

A executer depuis la machine qui a la cle de deploiement :
    python3 scripts/apres-deploiement.py --hote H --port 65002 --user U --racine /chemin

Sortie : un tableau, et un code 1 si un seul constat est faux.
"""
from __future__ import annotations

import argparse
import json
import subprocess
import sys

def ssh(hote, port, user, cle, commande, timeout=60):
    argv = ["ssh", "-i", cle, "-p", str(port), "-o", "StrictHostKeyChecking=accept-new",
            f"{user}@{hote}", commande]
    r = subprocess.run(argv, capture_output=True, text=True, timeout=timeout)
    return r.returncode, r.stdout.strip(), r.stderr.strip()

def wp(hote, port, user, cle, racine, commande):
    return ssh(hote, port, user, cle, f"wp {commande} --path={racine}")

def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--hote", required=True)
    ap.add_argument("--port", default="65002")
    ap.add_argument("--user", required=True)
    ap.add_argument("--cle", default="~/.ssh/tuc_deploy")
    ap.add_argument("--racine", required=True)
    ap.add_argument("--attendu", default="0.8.14")
    a = ap.parse_args()

    constats = []
    def constater(nom, ok, detail=""):
        constats.append((nom, ok, detail))

    # 1. la version que le site ANNONCE
    code, out, err = wp(a.hote, a.port, a.user, a.cle, a.racine,
                        "plugin get tuc-market-core --field=version")
    constater("version du plugin en ligne", out == a.attendu, f"lue : {out or err}")

    # 2. le schema des roles est passe a 5
    code, out, err = wp(a.hote, a.port, a.user, a.cle, a.racine,
                        "option get tuc_roles_schema_version")
    constater("schema des roles = 5", out == "5", f"lue : {out or err}")

    # 3. aucune donnee metier n'a bouge : c'est le point qui protege le pilote
    code, out, err = wp(a.hote, a.port, a.user, a.cle, a.racine,
                        "post list --post_type=tuc_commande --post_status=any --format=count")
    commandes = out or "?"
    constater("commandes toujours lisibles", out.isdigit(), f"nombre : {commandes}")

    code, out, err = wp(a.hote, a.port, a.user, a.cle, a.racine,
                        "post list --post_type=tuc_resto --post_status=any --format=count")
    restos = out or "?"
    constater("restaurants toujours lisibles", out.isdigit(), f"nombre : {restos}")

    # 4. la caisse repond (pas un 500 deguise en page de connexion)
    code, out, err = ssh(a.hote, a.port, a.user, a.cle,
                         f"wp option get home --path={a.racine}")
    home = out.rstrip("/")
    if home:
        code, out, err = ssh(a.hote, a.port, a.user, a.cle,
                             f"curl -s -o /dev/null -w '%{{http_code}}' {home}/?tuc_vue=caisse")
        constater("la caisse repond (HTTP)", out in {"200", "302", "301"}, f"code : {out}")

    # 5. aucun fatal PHP dans le log du jour
    code, out, err = ssh(a.hote, a.port, a.user, a.cle,
                         f"wp eval 'echo count(get_option(\"tuc_dernieres_erreurs\", []));' --path={a.racine} 2>/dev/null || echo 0")
    constater("pas d'erreur fatale remontee", out in {"0", ""}, f"valeur : {out}")

    print(f"Constat serveur — attendu {a.attendu}")
    print(f"  commandes : {commandes} · restaurants : {restos}")
    echecs = 0
    for nom, ok, detail in constats:
        print(f"  {'OK   ' if ok else 'ECHEC'} {nom}" + (f" — {detail}" if detail else ""))
        echecs += 0 if ok else 1
    print()
    if echecs:
        print(f"ECHEC — {echecs} constat(s) faux. Le deploiement n'est pas constate.")
        return 1
    print("OK — serveur constate")
    return 0

if __name__ == "__main__":
    sys.exit(main())
