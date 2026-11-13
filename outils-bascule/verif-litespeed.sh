#!/usr/bin/env bash
# Verification du cache menu sur /uat : combien de temps un prix ecrit en base reste-t-il faux
# pour le visiteur ?
#
# Ce que les mesures precedentes ont etabli, et qui tient ce script :
#   - la page /uat/<resto>/ ne contient AUCUN prix : <div id="tuc-client-items"> est vide dans le
#     HTML et est remplie par le JS. Les « valeurs monetaires » que j'y avais relevees etaient les
#     0,00 MAD du panier et des nombres CSS (verifie le 13/11 a 19:4x, page de 25 140 o) ;
#   - le prix que lit le client vient de /wp-json/tuc/v1/menu/<slug> ;
#   - cette route repond x-litespeed-cache: hit, donc elle est au cache du serveur ;
#   - X-LiteSpeed-Cache-Control (le max-age=60 que 0.8.21 est cense poser) n'apparait pas dans la
#     reponse. Deux lectures possibles : soit le serveur le consomme, soit il n'est pas applique et
#     l'entree vit 604 800 s. Ca ne se tranche pas a la relecture : ca se mesure a l'horloge.
#
# Polling : TUC_PAS secondes, TUC_DUREE secondes au total (defauts 15 et 480).
#
# utilisation : verif-litespeed.sh <racine-uat> <url-de-base> [id-plat]
set -u
RACINE="${1:-}"; BASE="${2:-}"; PLAT="${3:-13}"
PAS="${TUC_PAS:-15}"; DUREE="${TUC_DUREE:-150}"
S="=============================================================="
[ -n "$RACINE" ] && [ -d "$RACINE" ] || { echo "racine introuvable : $RACINE"; exit 2; }
case "$RACINE" in */uat) ;; *) echo "REFUS : la racine doit finir par /uat ($RACINE)"; exit 2;; esac
[ -n "$BASE" ] || { echo "url de base manquante"; exit 2; }
cd "$RACINE" || exit 2
command -v wp >/dev/null 2>&1 || { echo "wp-cli absent"; exit 2; }
TMP="$(mktemp -d 2>/dev/null || echo /tmp/tuc-verif-$$)"

cat > "$TMP/purge.php" <<'PHPEOF'
<?php
$plat  = isset($args[0]) ? (int) $args[0] : 0;
$resto = isset($args[1]) ? (int) $args[1] : 0;
$mode  = isset($args[2]) ? (string) $args[2] : 'complet';
$agir  = isset($args[3]) && $args[3] === 'agir';
if (!class_exists('TUC_Security_Headers')) {
  echo "    TUC_Security_Headers absent : le module n'est pas charge, rien a mesurer\n";
  exit(2);
}
$slug  = (string) get_post_field('post_name', $resto);
$route = TUC_Security_Headers::menu_purge_path($slug, false);
$item  = get_permalink($plat);
$page  = get_permalink($resto);
$urls  = TUC_Security_Headers::purge_urls_for(
  is_string($route) ? $route : '', is_string($item) ? $item : '', is_string($page) ? $page : ''
);
$subset = ($mode === 'json') ? array_slice($urls, 0, 1) : $urls;
echo '    mode ' . $mode . ' : ' . count($subset) . ' URI(s) sur ' . count($urls) . "\n";
if (!$agir) { echo "    (balayage : aucun appel emis)\n"; exit(0); }
$n = 0;
foreach ($subset as $u) { foreach (TUC_Security_Headers::purge_requests_for($u) as $call) { $call(); $n++; } }
echo "    appels de purge emis : $n\n";
// Le TTL reellement retenu par le serveur, lu cote plugin : rien d'invente.
$ttl = get_option('litespeed.conf.cache-ttl');
$opt = get_option('litespeed-opt');
echo '    litespeed.conf.cache-ttl : ' . (is_scalar($ttl) && $ttl !== '' ? (string) $ttl : 'vide')
   . ' | litespeed-opt : ' . (is_array($opt) ? 'configure (' . count($opt) . ' cles)' : 'non configure') . "\n";
exit(0);
PHPEOF

# Le prix lu dans la reponse du client : le JSON, pas le HTML. Un seul champ, un seul id.
cat > "$TMP/prix.php" <<'PHPEOF'
<?php
$f = @file_get_contents($argv[1]);
if (false === $f || '' === $f) { echo "VIDE"; exit(0); }
if (0 === strpos(ltrim($f), '<')) { echo "PAS-DU-JSON"; exit(0); }
$j = json_decode($f, true);
if (!is_array($j)) { echo "JSON-INVALIDE"; exit(0); }
$items = $j['items'] ?? null;
if (!is_array($items)) { echo "SANS-ITEMS"; exit(0); }
foreach ($items as $it) {
  if ((int) ($it['id'] ?? 0) === (int) $argv[2]) {
    $p = $it['price'] ?? $it['prix'] ?? null;
    echo is_scalar($p) ? (string) $p : 'OBJET';
    exit(0);
  }
}
echo "PLAT-ABSENT";
PHPEOF

lire_prix() { wp post meta get "$PLAT" tuc_prix --allow-root 2>/dev/null | tr -d '\r\n' | sed 's/^"//; s/"$//'; }
JSON=""
CODE="000"
# Le 13/11 a 20:2x, le CDN de l'hebergeur (server: hcdn) a repondu 307 puis un 504 a une partie
# des sondes : le releve devenait « PAS-DU-JSON » et le verdict tombait pour la mauvaise raison.
# Trois tentatives, redirections suivies, et le code HTTP conserve pour que « inexploitable » se
# lise comme tel au lieu de ressembler a un echec du correctif.
fetch_json() { # $1 = fichier ou ecrire le corps ; laisse les en-tetes dans $TMP/h
  local n pri
  for n in 1 2 3; do
    CODE=$(curl -sS -m 25 -L -o "$1" -w '%{http_code}' -D "$TMP/h" "$JSON" 2>/dev/null || echo 000)
    printf '%s' "$CODE" > "$TMP/code"   # $() est une subshell : sans ce fichier, CODE meurt avec elle
    pri=$(php "$TMP/prix.php" "$1" "$PLAT")
    case "$pri" in PAS-DU-JSON|VIDE|JSON-INVALIDE|SANS-ITEMS|PLAT-ABSENT) sleep 5; continue;; esac
    printf "%s" "$pri"
    return 0
  done
  printf "INEXPLOITABLE"
  return 1
}
entete() { grep -ai "^$1:" "$TMP/h" 2>/dev/null | head -1 | sed 's/\r$//'; }
RELA_ID=""
purger() { wp eval-file "$TMP/purge.php" "$PLAT" "$RELA_ID" "$1" "${2:-agir}" --allow-root 2>&1 | sed 's/^/  /'; }

RESTAURE=0
restore() {
  [ "$RESTAURE" = 1 ] && return 0
  [ -n "${ANCIEN:-}" ] || return 0
  if [ "$(lire_prix)" != "$ANCIEN" ]; then
    wp post meta update "$PLAT" tuc_prix "$ANCIEN" --allow-root >/dev/null 2>&1
    echo "  [piege] prix remis a $ANCIEN dans la base"
  fi
  RESTAURE=1
  [ -n "$JSON" ] && purger json >/dev/null 2>&1
}
trap 'restore; rm -rf "$TMP"' EXIT INT TERM

echo "$S ; 0. CE QUI TOURNE"
printf "  plugin : "; wp plugin list --name=tuc-market-core --fields=version,status --format=csv --allow-root 2>&1 | tail -1
ANCIEN=$(lire_prix)
case "$ANCIEN" in
  ''|*[!0-9.]*) echo "REFUS : valeur de prix non numerique ou absente ('$ANCIEN') — aucune ecriture"; exit 3;;
esac
NOUVEAU=$(php -r 'echo (string) ((float) $argv[1] + 1);' "$ANCIEN" 2>/dev/null)
RELA_ID=$(wp post meta get "$PLAT" tuc_resto_id --allow-root 2>/dev/null | tr -dc '0-9')
[ -n "$RELA_ID" ] || { echo "REFUS : pas de tuc_resto_id sur le poste $PLAT"; exit 3; }
RELA=$(wp post get "$RELA_ID" --field=post_name --allow-root 2>/dev/null | tr -d '\r\n')
JSON="$BASE/wp-json/tuc/v1/menu/$RELA"
echo "  plat $PLAT : base = $ANCIEN , valeur de test = $NOUVEAU | resto '$RELA'"
echo "  route mesuree : $JSON"

echo "$S ; 1. DEPART : PURGE, PUIS CE QUE LE SERVEUR RENDE"
purger json agir
P=$(fetch_json "$TMP/n0.json"); printf "  prix rendu : %s | %s | %s\n" "$P" "$(entete 'x-litespeed-cache')" "$(entete 'cache-control')"
echo "  en-tetes de cache complets de ce premier rendu :"
grep -aiE "^(HTTP/|x-litespeed|cache-control|age|vary|etag|last-modified)" "$TMP/h" | sed 's/\r$//' | sed 's/^/      /'

echo "$S ; 2. ECRITURE EN BASE, AUCUNE PURGE : quand le client voit-il la valeur ?"
RESTAURE=1
wp post meta update "$PLAT" tuc_prix "$NOUVEAU" --allow-root >/dev/null 2>&1 \
  || { echo "  ecriture refusee par WordPress : rien n'est change"; RESTAURE=0; exit 4; }
echo "  base : $(lire_prix)"
DEBUT=$(date +%s); VU=""; RETOURNE=""
t=0
while [ "$t" -lt "$DUREE" ]; do
  P=$(fetch_json "$TMP/p.json")
  if [ -z "$RETOURNE" ] && [ "$P" = "$NOUVEAU" ]; then RETOURNE=$t; fi
  printf "  t=%3ss prix=%-14s %s | HTTP %s\n" "$t" "$P" "$(entete 'x-litespeed-cache')" "$(cat "$TMP/code" 2>/dev/null || echo '?')"
  VU="$P"
  [ -n "$RETOURNE" ] && break
  t=$(( t + PAS )); sleep "$PAS"
done
[ -z "$RETOURNE" ] && printf "  aucun retournement en %ss ; dernier prix rendu : %s\n" "$DUREE" "$VU"

echo "$S ; 3. LA PURGE QUE LE HOOK EMET DANS UNE REPONSE HTTP"
purger json agir
P=$(fetch_json "$TMP/a.json"); printf "  apres purge : prix=%s | %s\n" "$P" "$(entete 'x-litespeed-cache')"
# C'est ICI que le correctif se joue, pas au polling : si l'URI est la bonne, l'entree tombe et
# le prix frais apparait a la requete suivante. Le polling, lui, dit autre chose (le TTL).
# fige dans P3 : P est reecrit a la phase 4, et un verdict qui relit P apres coup est faux.
P3="$P"
PURGE_EFFICACE="non"
[ "$P3" = "$NOUVEAU" ] && PURGE_EFFICACE="oui"
if [ "$P3" = "INEXPLOITABLE" ]; then
  echo "  releve de la phase 3 inexploitable (dernier HTTP $(cat "$TMP/code" 2>/dev/null || echo '?')) : la mesure ne peut rien conclure."
  echo "  Ce n'est pas un echec du correctif, c'est l'observateur qui n'a pas eu de reponse exploitable."
  exit 8
fi

echo "$S ; 4. RESTAURATION"
wp post meta update "$PLAT" tuc_prix "$ANCIEN" --allow-root >/dev/null 2>&1
RESTAURE=0
FIN=$(lire_prix)
if [ "$FIN" = "$ANCIEN" ]; then echo "  base : $FIN = valeur d'origine"; else echo "  ECHEC DE RESTAURATION : base a '$FIN', attendu '$ANCIEN'"; fi
purger json agir
P=$(fetch_json "$TMP/z.json"); printf "  rendu apres restauration : prix=%s | %s\n" "$P" "$(entete 'x-litespeed-cache')"

echo "$S ; 5. VERDICT"
if [ -n "$RETOURNE" ]; then
  echo "  le client voit le nouveau prix au bout de ${RETOURNE}s sans aucune purge : c'est le TTL"
  echo "  applique a la route JSON. Le trou est borne a cette duree."
else
  echo "  APRES ${DUREE}s SANS PURGE, LE CLIENT VOIT TOUJOURS L'ANCIEN PRIX. La route JSON reste donc"
  echo "  au cache du serveur bien au-dela des 60 s que le plugin pretend imposer : le TTL qui s'applique"
  echo "  est celui du serveur (604 800 s sur cet hote), pas le notre."
fi
echo "  ce que la purge a produit : releve de la phase 3 = $P3 (attendu $NOUVEAU) -> PURGE_EFFICACE=$PURGE_EFFICACE"
[ "$PURGE_EFFICACE" = oui ] || exit 7
if [ -n "$RETOURNE" ]; then
  echo "  TTL : le prix de lui-meme au bout de ${RETOURNE}s, sans purge"
else
  echo "  TTL : aucun retournement en ${DUREE}s. Le max-age=60 que le plugin pose ne borne pas"
  echo "  l entree sur cet hebergeur — la purge est donc le seul levier reel, et elle doit viser"
  echo "  la bonne URL. Ce point est un constat sur le serveur, pas un echec de ce controle."
fi
if [ "$P" = "$ANCIEN" ] && [ "$FIN" = "$ANCIEN" ]; then
  echo "  etat final conforme : base et rendu a $ANCIEN"
else
  echo "  etat final a verifier : base=$FIN rendu=$P"
fi
[ "$FIN" = "$ANCIEN" ] || exit 5
echo "$S ; FIN"
