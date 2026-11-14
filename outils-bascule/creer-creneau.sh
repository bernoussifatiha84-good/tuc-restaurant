#!/usr/bin/env bash
# TUC — creer le creneau MySQL de /uat dans l'hebergement, par l'API Hostinger.
#
# Pourquoi l'API et pas moi en ligne de commande : le jeton vit en secret du depot, l'appel part
# du runner GitHub, et le mot de passe de la base est GENRERE ICI. Il ne transite donc par aucune
# conversation, aucun journal de commit, aucun fichier suivi. Il est masque au log (`::add-mask::`)
# puis ecrit dans un fichier temporaire que l'etape suivante emporte sur l'hote et detruit.
#
# Contrat API mesure le 14/11 dans docs.hostinger.com :
#   POST /api/hosting/v1/accounts/{username}/databases
#        {name, user, password, website_domain} -> 200 {"message":"Request accepted"}
#   GET  /api/hosting/v1/accounts/{username}/databases?domain=…   (verification)
#   PATCH /api/hosting/v1/accounts/{username}/databases/password   (reprise si la base existe deja)
# Le nom reel est retourne prefixe par le compte (u987613820_uat_mysql) : c'est pour ca que la
# verification relit la liste au lieu de faire confiance au nom demande.
#
# Usage : TUC_PASSFILE=/chemin/fichier SECRET_API=… bash creer-creneau.sh <domaine> <compte> [nom] [user]
set -uo pipefail

DOM="${1:-}"; ACC="${2:-}"; NOM="${3:-uat_mysql}"; USR="${4:-uat_pilote}"
PASSFILE="${TUC_PASSFILE:-}"
TOK="${HOSTINGER_API_TOKEN:-}"
if [ -z "$DOM" ] || [ -z "$ACC" ] || [ -z "$PASSFILE" ]; then
  echo "usage : TUC_PASSFILE=<fichier> creer-creneau.sh <domaine> <compte> [nom] [user]" >&2
  exit 2
fi
if [ -z "$TOK" ]; then
  echo "ARRET : aucun jeton dans HOSTINGER_API_TOKEN."
  echo "  Ou le mettre : depot -> Settings -> Secrets and variables -> Actions ->"
  echo "  New repository secret, nomme HOSTINGER_API_TOKEN. Le coller dans une conversation est"
  echo "  ce qu'il ne faut pas faire : un jeton Hostinger lit les fichiers des 34 installations"
  echo "  du compte et peut vider des caches. S'il a ete visible quelque part, le revoquer et en"
  echo "  creer un autre, c'est deux clics dans hPanel."
  exit 3
fi

# Deux bases possibles selon les pages de doc ; on garde celle qui repond en JSON.
BASES="${HOSTINGER_API_BASES:-https://api.hostinger.com https://developers.hostinger.com}"
BASE=""
for b in $BASES; do
  code=$(curl -s -m 20 -o "$PASSFILE.probe" -w '%{http_code}' -H "Authorization: Bearer $TOK" \
         "$b/api/hosting/v1/accounts/$ACC/databases?limit=1" 2>/dev/null || echo 000)
  echo "  sondage $b -> HTTP $code"
  case "$code" in
    200|401|403|422) BASE="$b"; break;;
  esac
done
if [ -z "$BASE" ]; then
  echo "ARRET : aucune base de l'API n'a repondu en JSON (attendu 200/401/403/422)."
  echo "  Le jeton n'est pas en cause : c'est le transport. Rien n'a ete cree."
  rm -f "$PASSFILE.probe"; exit 4
fi
rm -f "$PASSFILE.probe"
echo "  base retenue : $BASE"

# Mot de passe genere, jamais saisi : alphabet sans symbole pour eviter les echappements dans
# wp-config ('...'), mysqli, et un futur mysqldump. 22 caracteres, ca ne se devine pas.
PASS=$(openssl rand -base64 48 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 22)
if [ "${#PASS}" -lt 16 ]; then
  echo "ARRET : openssl n'a pas rendu un mot de passe exploitable (longueur ${#PASS})."
  exit 5
fi
# Un fichier qui porte un mot de passe ne doit pas survivre a un echec : le runner nettoie son
# repertoire temporaire, mais pas a l'instant ou moi je m'arrete. Le piege est ailleurs que dans
# la creation : si l'etape suivante meurt, le fichier reste.
shred_pass() {
  [ -n "$PASSFILE" ] || return 0
  [ -e "$PASSFILE" ] || return 0
  shred -u "$PASSFILE" "$PASSFILE.noms" 2>/dev/null || rm -f "$PASSFILE" "$PASSFILE.noms"
}
on_exit() { local c=$?; if [ "$c" -ne 0 ]; then shred_pass; fi; }
trap on_exit EXIT

umask 077
printf '%s' "$PASS" > "$PASSFILE"
chmod 600 "$PASSFILE" 2>/dev/null
if [ "${GITHUB_ACTIONS:-}" = "true" ]; then printf '::add-mask::%s\n' "$PASS"; fi

corps=$(printf '{"name":"%s","user":"%s","password":"%s","website_domain":"%s"}' "$NOM" "$USR" "$PASS" "$DOM")
echo "  creation : base '$NOM' + utilisateur '$USR' pour $DOM (le nom reel sera prefixe par le compte)"
rep=$(mktemp); code=$(curl -s -m 30 -o "$rep" -w '%{http_code}' -X POST \
     -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
     -d "$corps" "$BASE/api/hosting/v1/accounts/$ACC/databases" 2>/dev/null || echo 000)
corps=""; echo "  reponse : HTTP $code | $(head -c 160 "$rep" | tr -d '\n')"

case "$code" in
  200|201|202) echo "  -> creee." ;;
  422|409)
    # La base existe deja (tentative anterieure, ou nom deja pris). On ne detruit rien : on
    # remplace seulement le mot de passe par celui qu'on vient de generer, puis on verifie.
    echo "  -> l'API dit qu'elle existe deja. Reprise : on reassigne le mot de passe, pas de suppression."
    p=$(curl -s -m 30 -o "$rep" -w '%{http_code}' -X PATCH \
         -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
         -d "$(printf '{"name":"%s","password":"%s"}' "$NOM" "$PASS")" \
         "$BASE/api/hosting/v1/accounts/$ACC/databases/password" 2>/dev/null || echo 000)
    echo "     password -> HTTP $p | $(head -c 120 "$rep" | tr -d '\n')"
    case "$p" in 200|201|202|204) ;; *) echo "  ECHEC : ni creation ni reprise. Le mot de passe du fichier n'est bon pour personne."; rm -f "$rep"; exit 6;; esac ;;
  *) echo "  ECHEC : code $code. Rien n'est assume : le fichier de mot de passe reste, mais la bascule refusera de partir dessus sans verification."; rm -f "$rep"; exit 6;;
esac

# Verification : la liste doit montrer la base, et c'est la qu'on lit le nom REEL prefixe.
code=$(curl -s -m 30 -o "$rep" -w '%{http_code}' -H "Authorization: Bearer $TOK" \
        "$BASE/api/hosting/v1/accounts/$ACC/databases?domain=$DOM&limit=100" 2>/dev/null || echo 000)
REEL=$(python3 - "$rep" "$NOM" <<'PY'
import json,sys
try:
    d=json.load(open(sys.argv[1],encoding='utf-8'))
except Exception:
    print(''); raise SystemExit
cible=sys.argv[2]
def bal(n):
    for x in (d if isinstance(d,list) else d.get('databases') or d.get('data') or []):
        if isinstance(x,dict):
            noms=[str(x.get(k) or '') for k in ('name','database','database_name')]
            if any(n==cible or n.endswith('_'+cible) for n in noms): return x
    return None
x=bal(cible)
if x is None: print(''); raise SystemExit
print('%s\t%s' % (next((n for n in (x.get('name'),x.get('database'),x.get('database_name')) if n),''),
                 next((n for n in (x.get('user'),x.get('username'),x.get('user_name')) if n),'')))
PY
)
if [ -z "$REEL" ]; then
  echo "  VERIFICATION : base pas retrouvee dans la liste (HTTP $code). Je ne devine pas le nom reel."
  echo "  La bascule s'arretatera donc a sa porte d'entree, qui existe pour ca. Rejouer ce job est sur."
  rm -f "$rep"; exit 7
fi
NOM_REEL=$(printf '%s' "$REEL" | cut -f1); USR_REEL=$(printf '%s' "$REEL" | cut -f2)
rm -f "$rep"
printf '%s\n%s\n' "$NOM_REEL" "$USR_REEL" > "$PASSFILE.noms"
echo "  verifiee dans la liste : base '$NOM_REEL' utilisateur '$USR_REEL'"
echo "  mot de passe : '$PASSFILE' (mode $(stat -c %a "$PASSFILE" 2>/dev/null || echo '?')), 22 caracteres, jamais affiche"
echo "  rien n'a ete ecrit sur le site : c'est l'etape d'apres, et elle sauvegarde avant."
