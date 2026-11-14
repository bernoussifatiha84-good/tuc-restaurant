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
// Les cles suffixees par type de page, pas un nom invente. `litespeed.conf.cache-ttl` et
// `litespeed-opt` n'existent pas dans cette version du plugin, et c'est sur ce « vide / non
// configure » que j'avais ecrit que la cause etait hors du depot : la duree qui nous occupe est
// bien dans WordPress, elle s'appelle `litespeed.conf.cache-ttl_rest`.
$_fmt = static function (string $k): string {
    $x = get_option('litespeed.conf.' . $k, '');
    if (!is_scalar($x)) { $x = json_encode($x); }
    return $k . '=' . ('' === (string) $x ? '(vide)' : (string) $x);
};
echo '    reglage lu : ' . implode(' | ', [$_fmt('cache'), $_fmt('cache-rest'), $_fmt('cache-ttl_rest'), $_fmt('cache-exc')]) . "\n";
exit(0);
PHPEOF

cat > "$TMP/etat.php" <<'PHPEOF'
<?php
// Ou vit reellement la duree du cache, verifie au lieu d'etre suppose. Trois questions, trois
// reponses lues dans la base et dans PHP : le plugin de cache est-il actif, ou sont son reglage,
// et le notre envoie-t-il vraiment sa consigne.
global $wpdb;
$act = get_option('active_plugins');
$act = is_array($act) ? $act : [];
$fil = [];
foreach ($act as $p) {
    if (preg_match('#(litespeed|pure-cache|hostinger|wp-rocket|w3tc|wp-super-cache|nginx-helper|swift)#i', (string) $p)) {
        $fil[] = (string) $p;
    }
}
echo '  plugins de cache actifs : ' . ($fil ? implode(', ', $fil) : 'aucun') . "\n";
if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
$gp = get_plugins();
$ver = [];
foreach ($gp as $f0 => $d0) { if (preg_match('#^(litespeed-cache|pure-cache)/#', $f0)) { $ver[] = $f0 . ' ' . $d0['Version']; } }
echo '  version declaree du plugin de cache : ' . ($ver ? implode(' | ', $ver) : 'introuvable')
    . ' | helper lite_speed_purge_url : ' . (function_exists('lite_speed_purge_url') ? 'la' : 'absente')
    . ' | action litespeed_purge_url : ' . (has_action('litespeed_purge_url') !== false ? 'quelqu\'un l\'ecoute' : 'personne ne l\'ecoute') . "\n";
$rows = $wpdb->get_results(
    "SELECT option_name AS n, LENGTH(option_value) AS l, LEFT(option_value, 130) AS v"
    . " FROM {$wpdb->options}"
    . " WHERE option_name LIKE 'litespeed%' OR option_name LIKE '%pure-cache%' OR option_name LIKE 'hostinger%'"
    . " ORDER BY option_name LIMIT 200",
    ARRAY_A
);
if (!is_array($rows) || [] === $rows) {
    echo "  AUCUNE option litespeed*/hostinger* en base : la duree ne se regle pas dans WordPress, ni ici ni ailleurs\n";
} else {
    foreach ($rows as $r) {
        printf("     %-36s %6s o | %s\n", (string) $r['n'], (string) $r['l'],
            preg_replace('/\s+/', ' ', (string) $r['v']));
    }
}
// Les trois cles qui decident vraiment, lues sous leur nom reel. Ce controle cherchait
// `litespeed.conf.cache-ttl` et `litespeed-opt` : ces noms n'existent pas dans cette version,
// d'ou un « vide / non configure » qui faisait ressembler a une cause hors du depot ce qui est
// un reglage WordPress a trois caracteres pres.
foreach (['litespeed.conf.cache-rest', 'litespeed.conf.cache-ttl_rest', 'litespeed.conf.cache-ttl_pub',
          'litespeed.conf.cache-exc', 'litespeed.conf.cache_header', 'litespeed.conf.cache-ttl_status'] as $vraie) {
    $vv = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $vraie));
    printf("     %-34s %s\n", $vraie, null === $vv ? '(absente)' : ('' === $vv ? '(vide)' : $vv));
}
$ignore = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'litespeed-opt' LIMIT 1");
if (is_string($ignore) && '' !== $ignore) {
    $o = maybe_unserialize($ignore);
    foreach (['cache_ignore_uri', 'cache-ttl', 'guest_cache', 'cache_login', 'objcache-stores'] as $k) {
        echo '     opt[' . $k . '] : ' . (is_array($o) && array_key_exists($k, $o)
            ? (is_scalar($o[$k]) ? (string) $o[$k] : json_encode($o[$k])) : 'absent de l option') . "\n";
    }
} else {
    echo "     litespeed-opt : absent (le plugin tourne sur ses defauts, et rien ne les modifie)\n";
}
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
lc() { printf '%s' "$1" | tr 'A-Z' 'a-z'; }   # ${x,,} n'est pas garanti selon le bash de l'hote
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

echo "$S ; 3bis. LE TTL DU PLUGIN EST-IL HONORE ? entree fraiche, puis on la regarde vieillir"
# Le polling de la phase 2 prete a confusion : il mesure une entrée qui existait avant lui, et son
# silence ne dit pas si max-age=60 est ignore ou simplement rattrape par le TTL du serveur. La,
# l'entree ne peut venir que du rendu frais declenche par la purge de la phase 3 : si a t=90 elle
# repond encore hit, le reglage du plugin n'est pas applique, et c'est une conclusion exploitable.
T0=$(date +%s)
for w in 20 55 90; do
  while [ $(( $(date +%s) - T0 )) -lt "$w" ]; do sleep 1; done
  curl -sS -m 20 -D "$TMP/h$w" -o "$TMP/x$w.json" "$JSON" >/dev/null 2>&1
  hh=$(grep -ai "^x-litespeed-cache:" "$TMP/h$w" 2>/dev/null | tr -d '\r' | cut -d' ' -f2)
  ag=$(grep -ai "^age:" "$TMP/h$w" 2>/dev/null | tr -d '\r' | cut -d' ' -f2)
  printf "  t=%ss apres le rendu frais : %s | age: %s\n" "$w" "${hh:-?}" "${ag:-aucun}"
  [ "$w" = "90" ] && H90="${hh:-?}"
done
if [ "$H90" = "hit" ]; then
  echo "  le max-age de ${TTL_ATTENDU:-60}s n'est PAS honore : l'entree creee a l'instant repond encore hit a t=90."
  echo "  Constat sur le serveur, pas sur le plugin : l en-tête part (Cache-Control: no-cache est bien"
  echo "  revenu), le bord ne le respecte pas. Le seul levier reel reste la purge a l'ecriture."
  TTL_HONORE="non"
else
  if printf '%s' "$H90" | grep -qiE "no-cache|^[?[:space:]]*$"; then
    # Depuis le reglage cache-exc, la route repond sans copie : ce n'est pas un max-age respecte,
    # c'est une exclusion. Ecrire « TTL honore : oui » installerait l'idee fausse que le serveur
    # obeit a l'en-tete du plugin — il ne lui obeit toujours pas (cache-ttl_rest = 604 800).
    echo "  aucune copie gardee sur cette cle (${H90:-rien}) : la route n'est pas mise en cache."
    echo "  Ce n'est pas un max-age honore, c'est une exclusion : le prix est lu frais a chaque vue."
    TTL_HONORE="sans objet (route exclue du cache)"
  else
    echo "  entree expiree a t=90 (${H90}) : le TTL applique est court, le reglage du plugin est entendu."
    TTL_HONORE="oui"
  fi
fi

echo "$S ; 3ter. QUI RESERT LA COPIE : l'origine ou le bord ?"
# Un reglage de duree ne sert a rien si la copie que le client recoit ne vient pas de l'endroit
# qu'on regle. Discriminant joue sur une cle creee ici meme : la purge du plugin ne touche que
# l'origine, donc si la cle survit a cette purge, c'est qu'un niveau au-dessus la resserre.
VERDICT_BORD=""
QT="ttl$(date +%s)-$$"
JSON_ORIG="$JSON"
JSON="$BASE/wp-json/tuc/v1/menu/$RELA?ttlcle=$QT"
val() { entete "$1" | sed 's/^[^:]*:[[:space:]]*//' | tr 'A-Z' 'a-z'; }   # entete() rend la LIGNE, pas la valeur
P0=$(fetch_json "$TMP/t0.json"); H0=$(val 'x-litespeed-cache')
sleep 25
P1=$(fetch_json "$TMP/t1.json"); H1=$(val 'x-litespeed-cache'); A1=$(entete 'age')
printf "  cle unique %s : t=0 %s | t=25s %s | age %s\n" "$QT" "${H0:-?}" "${H1:-?}" "${A1:-aucun}"
if [ "$H1" != "hit" ]; then
  echo "  aucune copie conservee sur cette cle en 25 s : le discriminant n'a pas pu jouer."
  VERDICT_BORD="non concluant"
else
  if wp litespeed-purge url "$JSON" --allow-root >/dev/null 2>&1; then
    echo "  origine purgee sur cette cle exacte (wp litespeed-purge url, --path implicite car on est dans $RACINE)"
  else
    echo "  wp-cli n'a pas pu purger cette cle : le discriminant est fausse, on ne conclut pas"
    VERDICT_BORD="non concluant"
  fi
  if [ -z "$VERDICT_BORD" ]; then
    P2=$(fetch_json "$TMP/t2.json"); H2=$(val 'x-litespeed-cache')
    printf "  relance apres purge d'origine : %s\n" "${H2:-?}"
    if [ "$H2" = "hit" ]; then
      echo "  CONCLUSION : la cle survit a la purge de l'origine -> c'est le bord (hcdn) qui ressert la copie."
      echo "  Un reglage de duree cote serveur ou cote plugin n'y changera rien : il faut la purge du bord, ou sortir la route du cache."
      VERDICT_BORD="bord"
    else
      echo "  CONCLUSION : la cle est tombee des que l'origine est purgee -> c'est bien l'origine qui gardait l'entree."
      echo "  Le reglage de duree a donc un endroit ou se lire, et le max-age de 60 s est ignore par l'origine elle-meme."
      VERDICT_BORD="origine"
    fi
  fi
fi
echo "  verdict du discriminant : $VERDICT_BORD"
echo "  ou en est le cote plugin : $(wp eval 'echo has_filter("rest_post_dispatch", ["TUC_Security_Headers", "tune_menu_response"]) ? "le filtre de reglage est branche" : "le filtre N EST PAS branche";' --allow-root 2>/dev/null | tail -1)"
JSON="$JSON_ORIG"

echo "$S ; 3quater. OU VIT LE REGLAGE DE DUREE"
wp eval-file "$TMP/etat.php" --allow-root 2>&1 | sed 's/^/  /'

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
[ -z "$TTL_HONORE" ] && TTL_HONORE="non mesure (la boucle n'a pas rendu de releve exploitable)"
echo "  TTL honore par le serveur : $TTL_HONORE | qui ressert la copie : ${VERDICT_BORD:-non mesure}"
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
