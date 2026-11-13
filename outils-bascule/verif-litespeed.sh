#!/usr/bin/env bash
# Verification LiteSpeed sur /uat : la page de menu est-elle purgee quand un prix change ?
#
# Le raisonnement, en une phrase : si deux rendus frais consecutifs sont identiques octet pour
# octet, alors l'empreinte devient un instrument de mesure fiable, et on peut departager
# « la page n'a pas bouge parce que le cache la sert » de « la page a change parce qu'on a
# purge la bonne URL ». Quatre empreintes sont comparees a la reference :
#   CLI   (ecriture seule)          -> doit etre identique : en CLI, purge_menu_on_price_change
#                                      sort avant meme de choisir les URI (skip_when_no_response)
#   JSON  (liste de 0.8.22)         -> doit etre identique : c'est le trou
#   PLEIN (liste de 0.8.23)         -> doit DIFFERER : la page du restaurant est dans la liste
#   FIN   (apres restauration)      -> doit revenir a la reference : rien de resident
#
# utilisation : verif-litespeed.sh <racine-uat> <url-de-base> [id-plat]
set -u
RACINE="${1:-}"; BASE="${2:-}"; PLAT="${3:-13}"
S="=============================================================="
[ -n "$RACINE" ] && [ -d "$RACINE" ] || { echo "racine introuvable : $RACINE"; exit 2; }
case "$RACINE" in */uat) ;; *) echo "REFUS : la racine doit finir par /uat ($RACINE)"; exit 2;; esac
[ -n "$BASE" ] || { echo "url de base manquante"; exit 2; }
cd "$RACINE" || exit 2
command -v wp >/dev/null 2>&1 || { echo "wp-cli absent"; exit 2; }
TMP="$(mktemp -d 2>/dev/null || echo /tmp/tuc-verif-$$)"

# Le code du plugin est appele par ses propres fonctions, pas par une copie de leur logique :
# c'est ce qui rend la mesure valable sur la version qui tourne, ici 0.8.23.
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
$slug = (string) get_post_field('post_name', $resto);
// menu_purge_path($slug, true) rend null : c'est le garde-fou CLI du hook. On veut ici la liste
// que le hook aurait produite pendant une reponse HTTP, donc skip = false, assume et dit.
$route = TUC_Security_Headers::menu_purge_path($slug, false);
$item  = get_permalink($plat);
$page  = get_permalink($resto);
$urls  = TUC_Security_Headers::purge_urls_for(
  is_string($route) ? $route : '',
  is_string($item) ? $item : '',
  is_string($page) ? $page : ''
);
echo '    purge_urls_for() du code deploye -> ' . count($urls) . " URI(s)\n";
foreach ($urls as $u) { echo '      - ' . $u . "\n"; }
$subset = ($mode === 'json') ? array_slice($urls, 0, 1) : $urls;
echo '    mode ' . $mode . ' : ' . count($subset) . " URI(s) retenues\n";
if (!$agir) { echo "    (balayage : aucun appel emis)\n"; exit(0); }
$n = 0;
foreach ($subset as $u) {
  foreach (TUC_Security_Headers::purge_requests_for($u) as $call) { $call(); $n++; }
}
echo "    appels de purge emis : $n\n";
exit(0);
PHPEOF

lire_prix() { wp post meta get "$PLAT" tuc_prix --allow-root 2>/dev/null | tr -d '\r\n' | sed 's/^"//; s/"$//'; }
corps() { curl -s -m 25 -o "$TMP/b" -D "$TMP/h" -L "$1"; }
entete() { grep -ai "^$1:" "$TMP/h" 2>/dev/null | head -1 | sed 's/\r$//'; }
vu() { grep -o "$1" "$TMP/b" 2>/dev/null | wc -l | tr -d ' '; }
empreinte() { sha256sum "$TMP/b" | cut -c1-16; }
taille() { stat -c %s "$TMP/b" 2>/dev/null; }
ligne() { printf "  %-12s %s | %6s o | %-26s | %s\n" "$1" "$(empreinte)" "$(taille)" "$(entete 'x-litespeed-cache')" "$(entete 'cache-control')"; }
RELA_ID=""; RELA=""; PAGE=""; JSON=""
purger() { wp eval-file "$TMP/purge.php" "$PLAT" "$RELA_ID" "$1" "${2:-agir}" --allow-root 2>&1 | sed 's/^/  /'; }

RESTAURE=0
restore() {
  [ "$RESTAURE" = 1 ] && return 0
  [ -n "${ANCIEN:-}" ] || return 0
  if [ "$(lire_prix)" != "$ANCIEN" ]; then
    wp post meta update "$PLAT" tuc_prix "$ANCIEN" --allow-root >/dev/null 2>&1
    echo "  [piege] prix remis a $ANCIEN"
  fi
  RESTAURE=1
  [ -n "$PAGE" ] && purger complet >/dev/null 2>&1
}
trap 'restore; rm -rf "$TMP"' EXIT INT TERM

echo "$S ; 0. CE QUI TOURNE, ET CE QUI EST MESURE"
wp plugin list --name=tuc-market-core --fields=name,version,status --format=csv --allow-root 2>&1 | head -2 | sed 's/^/  /'
echo "  php CLI : $(php -r 'echo PHP_VERSION;')"
ANCIEN=$(lire_prix)
case "$ANCIEN" in
  ''|*[!0-9.]*) echo "REFUS : valeur de prix non numerique ou absente ('$ANCIEN') — aucune ecriture"; exit 3;;
esac
RELA_ID=$(wp post meta get "$PLAT" tuc_resto_id --allow-root 2>/dev/null | tr -dc '0-9')
[ -n "$RELA_ID" ] || { echo "REFUS : pas de tuc_resto_id sur le poste $PLAT"; exit 3; }
RELA=$(wp post get "$RELA_ID" --field=post_name --allow-root 2>/dev/null | tr -d '\r\n')
PAGE="$BASE/$RELA/" ; JSON="$BASE/wp-json/tuc/v1/menu/$RELA"
echo "  plat $PLAT (tuc_prix = $ANCIEN) | resto $RELA_ID '$RELA'"
echo "  page : $PAGE"

echo "$S ; 1. LA REFERENCE, ETABLIE"
purger complet agir
corps "$PAGE"; ligne "frais" ; SHA_FRAIS=$(empreinte)
corps "$PAGE"; ligne "re-serve" ; SHA_CACHE=$(empreinte)
if [ "$SHA_FRAIS" = "$SHA_CACHE" ]; then
  echo "  les deux rendus sont identiques : la page est deterministe, l'empreinte est un instrument"
else
  echo "  ATTENTION : deux rendus frais different ($SHA_FRAIS vs $SHA_CACHE) — l'empreinte ne prouve"
  echo "  rien ici, la page porte du variable. Lire le presentiel, pas le verdict."
fi

echo "$S ; 2. ECRITURE SEULE, SANS PURGE"
NOUVEAU=$(php -r 'echo (string) ((float) $argv[1] + 1);' "$ANCIEN" 2>/dev/null)
case "$NOUVEAU" in ''|*[!0-9.]*) echo "REFUS : prix de test non calcule"; exit 3;; esac
RESTAURE=1
wp post meta update "$PLAT" tuc_prix "$NOUVEAU" --allow-root >/dev/null 2>&1 \
  || { echo "  ecriture refusee par WordPress : rien n'est change"; RESTAURE=0; exit 4; }
echo "  $ANCIEN -> $NOUVEAU , relu en base : $(lire_prix)"
corps "$PAGE"; ligne "cli" ; SHA_CLI=$(empreinte)
echo "  ce que le hook aurait purge dans une reponse HTTP :"
purger complet balayage

echo "$S ; 3. A/B SUR LE MEME CACHE"
purger json agir
corps "$PAGE"; ligne "json-seul" ; SHA_JSON=$(empreinte)
purger complet agir
corps "$PAGE"; ligne "liste-entiere" ; SHA_PLEIN=$(empreinte)
corps "$PAGE"
echo "  apres purge complete : nouveau prix vu $(vu "$NOUVEAU") fois, ancien $(vu "$ANCIEN") fois"
echo "  (comptage brut de sous-chaines : indicateur de presence, pas une mesure du prix)"

echo "$S ; 4. RESTAURATION"
wp post meta update "$PLAT" tuc_prix "$ANCIEN" --allow-root >/dev/null 2>&1
RESTAURE=0
FIN=$(lire_prix)
if [ "$FIN" = "$ANCIEN" ]; then echo "  base : $FIN = valeur d'origine"
else echo "  ECHEC DE RESTAURATION : la base dit '$FIN', attendu '$ANCIEN'"; fi
purger complet agir
corps "$PAGE"; ligne "apres-restau" ; SHA_FIN=$(empreinte)

echo "$S ; 5. VERDICT"
egal() { [ "$1" = "$2" ] && printf "identique a la reference" || printf "DIVERGENT de la reference"; }
printf "  %-14s %s  %s\n" "cli" "$SHA_CLI" "$(egal "$SHA_CLI" "$SHA_FRAIS")"
printf "  %-14s %s  %s\n" "json-seul" "$SHA_JSON" "$(egal "$SHA_JSON" "$SHA_FRAIS")"
printf "  %-14s %s  %s\n" "liste-entiere" "$SHA_PLEIN" "$(egal "$SHA_PLEIN" "$SHA_FRAIS")"
printf "  %-14s %s  %s\n" "apres-restau" "$SHA_FIN" "$(egal "$SHA_FIN" "$SHA_FRAIS")"
if [ "$SHA_JSON" = "$SHA_FRAIS" ] && [ "$SHA_PLEIN" != "$SHA_FRAIS" ]; then
  echo "  CONCLUSION : sur /uat, purger la seule route JSON laisse la page de menu intacte octet pour"
  echo "  octet ; ajouter la page du restaurant a la liste la rend immediate. Le correctif produit son"
  echo "  effet sur le cache reel, pas seulement dans la liste d'URI testee dans le rig."
elif [ "$SHA_JSON" != "$SHA_FRAIS" ]; then
  echo "  CONCLUSION : le test ne departage pas les deux cas — la purge JSON a deja touche la page."
  echo "  Soit le cache du serveur ne distingue pas les URL comme attendu, soit la page n'est pas"
  echo "  separee en entree propre. A ne pas presenter comme une validation du correctif."
else
  echo "  CONCLUSION : la liste complete n'a pas change la page non plus. Le correctif ne se voit pas"
  echo "  sur ce serveur ; ne rien publier au client avant d'avoir compris pourquoi."
fi
[ "$FIN" = "$ANCIEN" ] || exit 5
[ "$SHA_FIN" = "$SHA_FRAIS" ] || exit 6
echo "$S ; FIN — empreinte revenue a la reference, aucune valeur fausse servie"
