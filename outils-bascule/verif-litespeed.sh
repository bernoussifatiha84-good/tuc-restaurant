#!/usr/bin/env bash
# Verification LiteSpeed sur /uat : la page de menu rendue suit-elle un changement de prix ?
#
# Instrument : le prix LISIBLE DANS LE CORPS DE LA PAGE, releve dans la fenetre du plat, pas une
# empreinte de document. Un rendu frais et sa copie en cache ont la meme empreinte par
# construction, et deux rendus frais peuvent differ sans que le prix change : l'empreinte seule
# ne departageait rien (c'est ce qui a fait sonner l'exit 6 au run precedent).
#
# Les cinq releves attendus :
#   1 frais            -> ancien prix
#   2 apres ecriture   -> ancien prix   (le hook sort avant de purger en CLI : skip_when_no_response)
#   3 purge JSON seule -> ancien prix   (la liste de 0.8.22 : c'est le trou)
#   4 purge complete   -> NOUVEAU prix  (la liste de 0.8.23 : c'est le correctif)
#   5 apres resto+purge-> ancien prix   (rien de resident)
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
// menu_purge_path($slug, true) rend null : le hook s'arrete la hors reponse HTTP. On veut la
// liste qu'il aurait produite pendant une reponse, donc skip = false, assume et dit.
$route = TUC_Security_Headers::menu_purge_path($slug, false);
$item  = get_permalink($plat);
$page  = get_permalink($resto);
$urls  = TUC_Security_Headers::purge_urls_for(
  is_string($route) ? $route : '', is_string($item) ? $item : '', is_string($page) ? $page : ''
);
$subset = ($mode === 'json') ? array_slice($urls, 0, 1) : $urls;
echo '    mode ' . $mode . ' : ' . count($subset) . ' URI(s) sur ' . count($urls) . " retenues\n";
if (!$agir) { echo "    (balayage : aucun appel emis)\n"; exit(0); }
$n = 0;
foreach ($subset as $u) { foreach (TUC_Security_Headers::purge_requests_for($u) as $call) { $call(); $n++; } }
echo "    appels de purge emis : $n\n";
exit(0);
PHPEOF

cat > "$TMP/fenetre.php" <<'PHPEOF'
<?php
$corps = @file_get_contents($argv[1]);
if (false === $corps) { echo "corps absent\n"; exit(0); }
$ancre = (string) $argv[2];
$pos = strpos($corps, $ancre);
if (false === $pos) { echo "ANCRE ABSENTE DU CORPS : extraction non concluante\n"; exit(0); }
// Le prix peut preceder l'ancre (carte, puis lien) : la fenetre s'etend des deux cotes.
$debut = max(0, $pos - 700);
$fen = substr($corps, $debut, 1500);
$brut = trim(preg_replace('/\s+/', ' ', strip_tags($fen)));
$codes = array();
foreach (array('ANCIEN' => (string) $argv[3], 'NOUVEAU' => (string) $argv[4]) as $nom => $v) {
  $chiffres = preg_replace('/[^0-9]/', '', $v);
  if ('' === $chiffres) { $codes[] = $nom . '?'; continue; }
  // "79" doit matcher "79", "79,00" et "79.00", mais pas "1 179" ni "790".
  $re = '/(?<![0-9])' . preg_quote($chiffres, '/') . '(?:[.,][0-9]{1,2})?(?![0-9])/';
  $codes[] = preg_match($re, $brut) ? $nom : '-';
}
printf("%s  |  %s\n", implode(' ', $codes), substr($brut, 0, 120));
PHPEOF

cat > "$TMP/diff.php" <<'PHPEOF'
<?php
$a = @file_get_contents($argv[1]); $b = @file_get_contents($argv[2]);
if (false === $a || false === $b) { echo "    (corps manquant pour le diff)\n"; exit(0); }
$n = min(strlen($a), strlen($b)); $d = 0; $vus = 0;
for ($i = 0; $i < $n; $i++) {
  if ($a[$i] !== $b[$i]) {
    $d++;
    if ($vus < 3) {
      $o = max(0, $i - 46);
      printf("    ecart @%-6d | %-46s | %s\n", $i, substr($a, $o, 92), substr($b, $o, 92));
      $vus++;
    }
  }
}
printf("    octets differents : %d (sur %s compares ; longueurs %d et %d)\n", $d, (string) $n, strlen($a), strlen($b));
PHPEOF

lire_prix() { wp post meta get "$PLAT" tuc_prix --allow-root 2>/dev/null | tr -d '\r\n' | sed 's/^"//; s/"$//'; }
corps() { curl -s -m 25 -o "$TMP/$2" -D "$TMP/h" -L "$1"; }
entete() { grep -ai "^$1:" "$TMP/h" 2>/dev/null | head -1 | sed 's/\r$//'; }
relver() { # $1 = libelle  $2 = fichier corps
  local f="$TMP/$2"
  printf "  %-18s %6s o | %-24s | %s\n" "$1" "$(stat -c %s "$f" 2>/dev/null)" "$(entete 'x-litespeed-cache')" "prix dans la fenetre du plat : $(php "$TMP/fenetre.php" "$f" "$ANCRE" "$ANCIEN" "$NOUVEAU")"
}
RELA_ID=""; RELA=""; PAGE=""
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

echo "$S ; 0. CE QUI TOURNE"
printf "  plugin deploye : "
wp plugin list --name=tuc-market-core --fields=version,status --format=csv --allow-root 2>&1 | tail -1
ANCIEN=$(lire_prix)
case "$ANCIEN" in
  ''|*[!0-9.]*) echo "REFUS : valeur de prix non numerique ou absente ('$ANCIEN') — aucune ecriture"; exit 3;;
esac
NOUVEAU=$(php -r 'echo (string) ((float) $argv[1] + 1);' "$ANCIEN" 2>/dev/null)
case "$NOUVEAU" in ''|*[!0-9.]*) echo "REFUS : prix de test non calcule"; exit 3;; esac
RELA_ID=$(wp post meta get "$PLAT" tuc_resto_id --allow-root 2>/dev/null | tr -dc '0-9')
[ -n "$RELA_ID" ] || { echo "REFUS : pas de tuc_resto_id sur le poste $PLAT"; exit 3; }
RELA=$(wp post get "$RELA_ID" --field=post_name --allow-root 2>/dev/null | tr -d '\r\n')
ANCRE=$(wp post get "$PLAT" --field=post_name --allow-root 2>/dev/null | tr -d '\r\n')
PAGE="$BASE/$RELA/"
echo "  plat $PLAT '$ANCRE' (tuc_prix = $ANCIEN, valeur de test $NOUVEAU) | resto $RELA_ID '$RELA'"
echo "  page : $PAGE"

echo "$S ; 1. POINT DE DEPART, PURGE"
purger complet agir
corps "$PAGE" 01-frais.html ; relver "1 frais" 01-frais.html
corps "$PAGE" 01b-reserve.html ; relver "1b relu (cache)" 01b-reserve.html

echo "$S ; 2. ECRITURE EN BASE, AUCUNE PURGE"
RESTAURE=1
wp post meta update "$PLAT" tuc_prix "$NOUVEAU" --allow-root >/dev/null 2>&1 \
  || { echo "  ecriture refusee par WordPress : rien n'est change"; RESTAURE=0; exit 4; }
echo "  $ANCIEN -> $NOUVEAU , relu en base : $(lire_prix)"
corps "$PAGE" 02-cli.html ; relver "2 relu (cache)" 02-cli.html
echo "  ce que le hook aurait purge pendant une reponse HTTP :"
purger complet balayage

echo "$S ; 3. A/B : LA LISTE DE 0.8.22, PUIS CELLE DE 0.8.23"
purger json agir
corps "$PAGE" 03-json.html ; relver "3 purge JSON" 03-json.html
purger complet agir
corps "$PAGE" 04-plein.html ; relver "4 purge complete" 04-plein.html
echo "  --- ce qui separe le rendu frais initial du rendu apres purge complete"
php "$TMP/diff.php" "$TMP/01-frais.html" "$TMP/04-plein.html"

echo "$S ; 5. RESTAURATION"
wp post meta update "$PLAT" tuc_prix "$ANCIEN" --allow-root >/dev/null 2>&1
RESTAURE=0
FIN=$(lire_prix)
if [ "$FIN" = "$ANCIEN" ]; then echo "  base : $FIN = valeur d'origine"
else echo "  ECHEC DE RESTAURATION : la base dit '$FIN', attendu '$ANCIEN'"; fi
purger complet agir
corps "$PAGE" 05-fin.html ; relver "5 apres resto" 05-fin.html
echo "  --- ce qui separe le rendu frais initial du rendu apres restauration (variance propre a la page)"
php "$TMP/diff.php" "$TMP/01-frais.html" "$TMP/05-fin.html"

echo "$S ; 6. VERDICT (sur les prix lus, pas sur les empreintes)"
L1=$(php "$TMP/fenetre.php" "$TMP/01-frais.html" "$ANCRE" "$ANCIEN" "$NOUVEAU")
L2=$(php "$TMP/fenetre.php" "$TMP/02-cli.html"   "$ANCRE" "$ANCIEN" "$NOUVEAU")
L3=$(php "$TMP/fenetre.php" "$TMP/03-json.html"  "$ANCRE" "$ANCIEN" "$NOUVEAU")
L4=$(php "$TMP/fenetre.php" "$TMP/04-plein.html" "$ANCRE" "$ANCIEN" "$NOUVEAU")
L5=$(php "$TMP/fenetre.php" "$TMP/05-fin.html"   "$ANCRE" "$ANCIEN" "$NOUVEAU")
printf "  1 depart        %s\n  2 ecriture seule %s\n  3 purge JSON     %s\n  4 purge complete %s\n  5 restauration   %s\n" "$L1" "$L2" "$L3" "$L4" "$L5"
cas() {
  local a=0 n=0
  case "$1" in *ANCIEN*) a=1;; esac
  case "$1" in *NOUVEAU*) n=1;; esac
  if [ "$a" = 1 ] && [ "$n" = 1 ]; then printf AMBIGU
  elif [ "$a" = 1 ]; then printf ANCIEN
  elif [ "$n" = 1 ]; then printf NOUVEAU
  else printf INCONNU; fi
}
C2=$(cas "$L2"); C3=$(cas "$L3"); C4=$(cas "$L4"); C5=$(cas "$L5")
echo "  codes : depart=$(cas "$L1") ecriture=$C2 json=$C3 complet=$C4 resto=$C5"
if [ "$C2" = ANCIEN ] && [ "$C3" = ANCIEN ] && [ "$C4" = NOUVEAU ] && [ "$C5" = ANCIEN ]; then
  echo "  CONCLUSION : le cache du serveur rend l'ancien prix tant que la page du restaurant n'est pas"
  echo "  dans la liste de purge ; des qu'elle y est, le nouveau prix apparait a la requete suivante."
  echo "  Le correctif de 0.8.23 est verifie sur le cache reel, pas seulement dans la liste d'URI."
else
  echo "  CONCLUSION : le releve ne departage pas les cas attendus. Ne rien presenter comme une"
  echo "  validation : relire les cinq lignes ci-dessus et l'extraction de fenetre."
fi
[ "$FIN" = "$ANCIEN" ] || exit 5
[ "$C5" = ANCIEN ] || exit 6
echo "$S ; FIN — prix de la base retabli et page purgee"
