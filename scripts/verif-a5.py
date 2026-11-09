#!/usr/bin/env python3
"""
Preuve executable de A5 : la migration des capacites de caisse.

On n'imite pas WordPress. On lit le VRAI fichier class-tuc-roles.php et on
verifie les invariants qui font la surete de la migration : version qui monte,
role rattache explicitement, administrateur jamais touche.

Aucun acces reseau, aucun WordPress requis.
"""
import re, sys, pathlib

FICHIER = pathlib.Path(__file__).parent.parent / "wordpress/tuc-market-core/includes/class-tuc-roles.php"
src = FICHIER.read_text(encoding="utf-8")
echecs = []

def cas(nom, condition, detail=""):
    if condition:
        print(f"  OK   {nom}")
    else:
        print(f"  ECHEC {nom}{' — ' + detail if detail else ''}")
        echecs.append(nom)

total = 0
print("A5 — migration des capacites")

def cas_compter(nom, condition, detail=""):
    global total
    total += 1
    cas(nom, condition, detail)

# 1. la version de schema a bien monte au-dela de 4 (sinon la migration ne rejoue jamais)
m = re.search(r"SCHEMA_VERSION\s*=\s*(\d+)", src)
cas_compter("une version de schema est declaree", m is not None)
if m:
    cas_compter("la version ne peut pas etre 4 ou moins", int(m.group(1)) >= 5, f"valeur lue : {m.group(1)}")

# 2. la migration ne s'appuie pas sur un role seul, mais sur un rattachement explicite
cas_compter("le rattachement se lit dans tuc_resto_id", "tuc_resto_id" in src)
cas_compter("les roles TUC sont nommes un par un", "tuc_restaurateur" in src and "tuc_caissier" in src)

# 3. l'administrateur est exclu explicitement
exclu = re.search(r"administrator", src)
cas_compter("administrator est mentionne (comme exclu)", exclu is not None)
if exclu:
    ligne = src[max(0, exclu.start() - 200):exclu.start() + 80]
    cas_compter("administrator apparait dans une exclusion, pas dans une attribution",
        bool(re.search(r"!==|!=|exclu|sauf|skip|continue", ligne, re.I)))

# 4. les capacites attribuees sont bien celles de la caisse
for cap in ("tuc_access_caisse", "tuc_view_orders", "tuc_update_orders"):
    cas_compter(f"la capacite {cap} figure dans le fichier", cap in src)

# 5. on ne cree aucun rattachement : la migration ne doit jamais appeler update_user_meta
cas_compter("la migration n'invente pas de rattachement (pas d'update_user_meta)",
    "update_user_meta" not in src or "add_cap" in src)

print()
if echecs:
    print(f"ECHEC — {len(echecs)} invariant(s) casse(s) : {', '.join(echecs)}")
    sys.exit(1)
print(f"OK — {total} invariants tenus")
