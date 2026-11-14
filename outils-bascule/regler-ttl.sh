#!/usr/bin/env bash
# Regler la duree de vie de la route JSON du menu, a travers le reglage du plugin lui-meme.
#
#   bash regler-ttl.sh [--executer] [--mode exclure|ttl60] <racine> <url>
#
# Sec par defaut : sans --executer, le script lit, explique ce qu'il ecrirait, et ne touche rien.
# --executer ecrit, mesure, et remet la valeur precedente si la mesure ne confirme pas.
# Sorties : 0 fait (ou sec approuve) · 9 annule, reglage retabli · 2 cible refusee.
#
# Pourquoi le reglage du plugin et pas notre en-tete : la duree qui sert le prix du menu est
# `litespeed.conf.cache-ttl_rest` (604 800 s mesures le 14/11, option listee dans le run
# 43626443343), et le plugin la lit avant notre X-LiteSpeed-Cache-Control. Un en-tete ignore n'est
# pas une option vide : ce controle commence donc par lire les cles reels, pas leur nom suppose.
set -uo pipefail

EXECUTE=0; MODE=exclure; RACINE=""; URL=""
for a in "$@"; do
  case "$a" in
    --executer) EXECUTE=1 ;;
    --mode) MODE="__attend__" ;;
    exclure|ttl60) [ "$MODE" = "__attend__" ] && MODE="$a" || { echo "REFUS : mode inattendu ($a)"; exit 2; } ;;
    *) if [ -z "$RACINE" ]; then RACINE="$a"; elif [ -z "$URL" ]; then URL="$a"; fi ;;
  esac
done
[ "$MODE" = "__attend__" ] && { echo "REFUS : valeur de --mode manquante (exclure ou ttl60)"; exit 2; }
[ -n "$RACINE" ] && [ -d "$RACINE" ] || { echo "REFUS : racine introuvable ($RACINE)"; exit 2; }
case "$RACINE" in */uat) ;; *) echo "REFUS : la racine doit finir par /uat ($RACINE)"; exit 2 ;; esac
[ -n "$URL" ] || { echo "REFUS : url de base manquante"; exit 2; }
command -v wp >/dev/null 2>&1 || { echo "REFUS : wp-cli absent"; exit 2; }
cd "$RACINE" || exit 2
# `--path` doit etre absolu des qu'on a change de repertoire : la racine arrive relative
# (SSH_ROOT = « domains/…/public_html/uat ») et depuis l'interieur, un chemin relatif ne designe
# plus rien — d'ou « No WordPress installation found » sur le run 43627617367, alors que la meme
# commande reussit quand on part de l'accueil.
RACINE_ABS="$(pwd)"

TMP="$(mktemp -d 2>/dev/null || echo /tmp/tuc-ttl-$$)"
SAUVEGARDE="$HOME/archives-uat/$(date -u +%Y%m%dT%H%M%SZ)--reglage-ttl.txt"
S="=============================================================="
CLE_EXC="litespeed.conf.cache-exc"
CLE_TTL="litespeed.conf.cache-ttl_rest"
CLE_REST="litespeed.conf.cache-rest"
ROUTE_URL="$URL/wp-json/tuc/v1/menu/kfc"

cat > "$TMP/regler.php" <<'PHPEOF'
<?php
// Tout passe par le format de stockage du plugin : une option par cle, valeur textuelle JSON.
// Inventer une forme serait exacte jusqu'au premier enregistrement fait depuis l'interface.
// `wp eval-file` rend les parametres positionnels dans $args (c'est ce que fait deja purge.php
// plus haut dans ce controle). Les lire dans $argv rendait $action absent, donc « lire » : la
// branche d'ecriture ne s'est jamais executee, et le run 43627377413 a mesure un etat intact en
// croyant mesurer un reglage applique. Le rouge obtenu etait donc le bon, pour la mauvaise raison.
$args    = isset($args) && is_array($args) ? $args : [];
$action = $args[0] ?? 'lire';
$clef   = $args[1] ?? '';
$charge = $args[2] ?? '';
function lignes() {
    global $wpdb;
    $r = $wpdb->get_results("SELECT option_name n, option_value v FROM {$wpdb->options}"
        . " WHERE option_name LIKE 'litespeed.conf.cache%' ORDER BY option_name", ARRAY_A);
    return is_array($r) ? $r : [];
}
if ('lire' === $action) {
    foreach (lignes() as $l) { printf("%s=%s\n", $l['n'], preg_replace('/\s+/', ' ', (string) $l['v'])); }
    $act = get_option('active_plugins');
    $ok = false;
    foreach ((array) $act as $p) { if (false !== strpos((string) $p, 'litespeed-cache/')) { $ok = true; } }
    printf("plugin_litespeed_actif=%s\n", $ok ? 'oui' : 'non');
    printf("exc_peuplee=%s\n", '' === get_option('litespeed.conf.cache-exc', '') ? 'vide' : 'peuplee');
    exit(0);
}
if ('retablir' === $action) {
    $n = 0;
    foreach (file($charge, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
        if ('litespeed.conf.' !== substr($ligne, 0, 15)) { continue; }
        [$k, $v] = explode('=', $ligne, 2);
        update_option($k, $v);
        ++$n;
    }
    printf("retabli %d option(s)\n", $n);
    exit(0);
}
if ('ecrire' !== $action) { echo "action inconnue\n"; exit(2); }
$avant = [];
foreach (lignes() as $l) { $avant[$l['n']] = (string) $l['v']; }
if ('exclure' === $clef) {
    $brut = $avant['litespeed.conf.cache-exc'] ?? '[]';
    $liste = json_decode($brut, true);
    if (!is_array($liste)) { $liste = []; }
    $motif = '/wp-json/tuc/v1/menu/';
    if (in_array($motif, $liste, true)) { echo "deja en place\n"; exit(3); }
    $liste[] = $motif;
    update_option('litespeed.conf.cache-exc', (string) json_encode($liste));
    printf("cache-exc : %s -> %s\n", $brut, json_encode($liste));
} else {
    $brut = $avant['litespeed.conf.cache-ttl_rest'] ?? '(absente)';
    update_option('litespeed.conf.cache-ttl_rest', '60');
    printf("cache-ttl_rest : %s -> 60\n", $brut);
}
exit(0);
PHPEOF

php -l "$TMP/regler.php" >/dev/null 2>&1 || { echo "REFUS : l'aide ne se relit pas"; exit 2; }

bar() { printf '%s\n' "$S"; }
sonde() { # $1 url ; rend "code|cache|age"
  curl -sS -m 25 -o /dev/null -D "$TMP/h" "$1" >/dev/null 2>/dev/null || { echo "000|injoignable|?"; return; }
  local x a
  x=$(tr -d '\r' < "$TMP/h" | grep -ai '^x-litespeed-cache:' | head -1 | sed 's/^[^:]*:[[:space:]]*//' | tr 'A-Z' 'a-z')
  a=$(tr -d '\r' < "$TMP/h" | grep -ai '^age:' | head -1 | sed 's/^[^:]*:[[:space:]]*//')
  printf '%s|%s|%s' "$(tr -d '\r' < "$TMP/h" | awk 'NR==1{print $2}')" "${x:-aucun}" "${a:-aucun}"
}
purger_tout() {
  # Le message de wp-cli se lit : l'etag ou le format d'option ne sont pas devinables depuis le
  # noir, et un instrument qui cache la raison de son echec finit par la faire passer pour un
  # defaut du site (c'est exactement ce que ce depot a appris le 13/11 avec --path).
  local msg
  msg=$(wp litespeed-purge all --path="$RACINE_ABS" --allow-root 2>&1)
  if [ $? = "0" ]; then echo "   cache vide par l'origine (wp litespeed-purge all)"; return 0; fi
  printf '   wp litespeed-purge all : %s\n' "$(printf '%s' "$msg" | tr '\n' ' ' | head -c 120)"
  msg=$(wp litespeed-purge url "$ROUTE_URL" --path="$RACINE_ABS" --allow-root 2>&1)
  if [ $? = "0" ]; then echo "   repli : la route du menu est purgee a l'unite ($ROUTE_URL)"; return 0; fi
  printf '   repli aussi en echec : %s\n' "$(printf '%s' "$msg" | tr '\n' ' ' | head -c 120)"
  echo "   aucune purge effectuee : la mesure qui suit portera sur des copies d'avant"
  return 1
}

bar; echo " 1. CE QUE LE SERVEUR ET LE PLUGIN DECIDENT AUJOURD'HUI"; bar
wp eval-file "$TMP/regler.php" lire --allow-root > "$TMP/avant.txt" 2>&1 || { echo "REFUS : lecture impossible"; exit 2; }
for c in "$CLE_REST" "$CLE_TTL" "$CLE_EXC" "litespeed.conf.cache-ttl_pub" "litespeed.conf.cache-ttl_status"; do
  printf "   %-34s %s\n" "$c" "$(grep -a "^$c=" "$TMP/avant.txt" | head -1 | cut -d= -f2-)"
done
grep -aq "plugin_litespeed_actif=oui" "$TMP/avant.txt" && echo "   plugin LiteSpeed actif : oui" || { echo "   REFUS : le plugin LiteSpeed n'est pas actif, ses options ne servent a rien"; exit 2; }
t=$(sonde "$ROUTE_URL?pre=$$-0"); printf "   route du menu, premiere lecture : %s\n" "$t"
t2=$(sonde "$ROUTE_URL?pre=$$-0"); printf "   la meme cle, a l'instant      : %s  <- hit ici = la route est servie par une copie\n" "$t2"

bar; echo " 2. CE QUE CETTE OPERATION VEUT CHANGER (mode: $MODE)"; bar
case "$MODE" in
  exclure) echo "   ajouter /wp-json/tuc/v1/menu/ a l'option litespeed.conf.cache-exc : la route n'est plus jamais servie d'une copie."
           echo "   prix lu frais a chaque vue ; cout mesure le 14/11 : 581 o, une requete a p50 0,30 ms / p95 14,86 ms." ;;
  ttl60)   echo "   passer l'option litespeed.conf.cache-ttl_rest de 604 800 a 60 s : la copie existe mais meurt en une minute."
           echo "   garde la protection du DB sous trafic, laisse jusqu'a 60 s de prix perime." ;;
esac
if [ "$EXECUTE" != "1" ]; then
  echo "   SEC : rien n'est ecrit. Rejouer avec --executer pour appliquer (retour automatique si la mesure rougit)."
  bar; exit 0
fi

bar; echo " 3. ECRIURE, MESURE, RETOUR ARRIERE SI LA MESURE NE SUIT PAS"; bar
mkdir -p "$HOME/archives-uat"; chmod 700 "$HOME/archives-uat" 2>/dev/null
cp "$TMP/avant.txt" "$SAUVEGARDE" && chmod 600 "$SAUVEGARDE" && echo "   sauvegarde : $SAUVEGARDE ($(grep -ac 'litespeed.conf.' "$SAUVEGARDE") cles)"
defaire() {
  trap - EXIT INT TERM   # sans ca, le exit 9 du retablissement reentrait dans sa propre trap
  echo "   RETOUR ARRIERE en cours…"
  wp eval-file "$TMP/regler.php" retablir "$SAUVEGARDE" --allow-root 2>&1 | sed 's/^/     /'
  purger_tout
  r=$(sonde "$ROUTE_URL?defaire=$$")
  echo "   relance apres retablissement : $r"
  echo "   reglage remis dans son etat d'avant ; la sauvegarde reste a $SAUVEGARDE"
  exit 9
}
trap 'rc=$?; [ "$rc" != 0 ] && defaire' EXIT INT TERM

wp eval-file "$TMP/regler.php" ecrire "$MODE" --allow-root 2>&1 | sed 's/^/   /'
code_ecriture=${PIPESTATUS[0]}
case "$code_ecriture" in
  0) echo "   ecrit" ;;
  3) echo "   deja en place : aucune ecriture inutile, on re-purge et on re-mesure seulement" ;;
  *) echo "   ecrit refusee par WordPress (code $code_ecriture)"; exit 9 ;;
esac
purger_tout
Q="ap$(date +%s)-$$"
a0=$(sonde "$ROUTE_URL?$Q"); printf "   t= 0s : %s\n" "$a0"
sleep 30
a1=$(sonde "$ROUTE_URL?$Q"); printf "   t=30s : %s\n" "$a1"
code=${a0%%|*}; c1cache=$(printf '%s' "$a1" | cut -d'|' -f2)
ROUGE=0
if [ "$MODE" = "exclure" ]; then
  [ "$c1cache" = "hit" ] && { echo "   ECHEC : la route est encore servie d'une copie 30 s apres l'exclusion demandee"; ROUGE=1; }
  r0=$(sonde "$ROUTE_URL"); r1=$(sonde "$ROUTE_URL")
  printf "   l URL du client, sans cle unique : %s puis %s\n" "$r0" "$r1"
  case "$r1" in *\|hit\|*) echo "   ECHEC : l entree creee avant le reglage est encore servie a l URL reale"; ROUGE=1 ;; esac
  p=$(sonde "$URL/kfc/?garde=$$-a"); pp=$(sonde "$URL/kfc/?garde=$$-a")
  echo "   temoin page client /kfc/ : $p puis $pp (elle doit rester cacheable, sinon c'est tout le site qui vient de perdre son cache)"
  case "$pp" in *hit*) ;; *) echo "   ECHEC : la page non plus n'est plus servie d'une copie : le reglage a deborde"; ROUGE=1 ;; esac
else
  case "$a0" in *hit*|*aucun*) ;; *) echo "   ECHEC : premier rendu sans copie, le ttl n'a pas ete applique"; ROUGE=1 ;; esac
  [ "$c1cache" = "hit" ] || { echo "   ECHEC : plus de copie a t=30s alors que 60 s etaient demandees"; ROUGE=1; }
fi
[ "$ROUGE" = "0" ] && echo "   MESURE CONFORME au mode demande" || echo "   MESURE NON CONFORME"
if [ "$ROUGE" = "0" ]; then trap - EXIT INT TERM; fi
bar; echo "  FIN — mode $MODE, $([ "$EXECUTE" = 1 ] && echo 'ecrit' || echo 'sec')"
[ "$ROUGE" = "0" ] || exit 9
exit 0
