#!/usr/bin/env bash
# Verification LiteSpeed sur /uat : le prix enregistre dans la base est-il rendu au visiteur ?
# Ecrit une seule valeur (tuc_prix d'un plat de demo), la restaure toujours, purge la page.
#
# utilisation : verif-litespeed.sh <racine-absolue-uat> <url-de-base> [id-du-plat]
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
$url = isset($args[0]) ? (string) $args[0] : '';
if ($url === '') { echo "    url vide : rien a purger, et surtout aucun appel sur une chaine vide\n"; exit(0); }
if (function_exists('lite_speed_purge_url')) {
  lite_speed_purge_url($url);
  echo "    lite_speed_purge_url() appelee sur " . $url . "\n";
} elseif (has_action('litespeed_purge_url')) {
  do_action('litespeed_purge_url', $url);
  echo "    action litespeed_purge_url() emise sur " . $url . "\n";
} else {
  echo "    AUCUN chemin de purge disponible : la page peut rester fausse, purger dans l'admin\n";
  exit(1);
}
PHPEOF
purger() { wp eval-file "$TMP/purge.php" "$1" --allow-root 2>&1 | sed 's/^/  /'; }

RESTAURE=0
restore() {
  [ "$RESTAURE" = 1 ] && return 0
  [ -n "${ANCIEN:-}" ] || return 0
  ACTUEL=$(wp post meta get "$PLAT" tuc_prix --raw --allow-root 2>/dev/null | tr -d '\r\n')
  if [ "$ACTUEL" != "$ANCIEN" ]; then
    wp post meta update "$PLAT" tuc_prix "$ANCIEN" --allow-root >/dev/null 2>&1
    echo "  [piege] prix remis a $ANCIEN (il etait reste a '$ACTUEL')"
  fi
  RESTAURE=1
  purger "$PAGE"
}
trap 'restore; rm -rf "$TMP"' EXIT INT TERM

corps() { curl -s -m 25 -o "$TMP/b" -D "$TMP/h" -L "$1"; }
entete() { grep -ai "^$1:" "$TMP/h" 2>/dev/null | head -1 | sed 's/\r$//'; }
prix_dans() { grep -o "$1" "$TMP/b" 2>/dev/null | wc -l | tr -d ' '; }

echo "$S ; 1. AVANT"
ANCIEN=$(wp post meta get "$PLAT" tuc_prix --raw --allow-root 2>/dev/null | tr -d '\r\n')
case "$ANCIEN" in
  ''|*[!0-9.]*) echo "REFUS : valeur de prix non numerique ou absente ('$ANCIEN') — aucune ecriture"; exit 3;;
esac
RELA=$(wp post list --post_type=tuc_resto --post_status=publish --field=post_name --allow-root 2>/dev/null | head -1 | tr -d '\r\n')
[ -n "$RELA" ] || RELA="kfc"
PAGE="$BASE/$RELA/"
JSON="$BASE/wp-json/tuc/v1/menu/$RELA"
echo "  plat $PLAT | tuc_prix = $ANCIEN | restaurant '$RELA'"
echo "  page testee : $PAGE"
for t in A B C; do corps "$PAGE"; printf "  %s : %-28s ancien prix vu %s fois\n" "$t" "$(entete 'x-litespeed-cache')" "$(prix_dans "$ANCIEN")"; done
SHA_AVANT=$(sha256sum "$TMP/b" | cut -c1-16); echo "  corps : $SHA_AVANT ($(stat -c %s "$TMP/b") o)"

NOUVEAU=$(php -r 'echo (string) ((float) $argv[1] + 1);' "$ANCIEN" 2>/dev/null)
case "$NOUVEAU" in ''|*[!0-9.]*) echo "REFUS : prix de test non calcule"; exit 3;; esac
echo "$S ; 2. ECRITURE : $ANCIEN -> $NOUVEAU (un poste, une cle)"
RESTAURE=1
wp post meta update "$PLAT" tuc_prix "$NOUVEAU" --allow-root >/dev/null 2>&1 \
  || { echo "  ecriture refusee par WordPress : rien n'est change"; RESTAURE=0; exit 4; }
echo "  relu en base : $(wp post meta get "$PLAT" tuc_prix --raw --allow-root 2>/dev/null | tr -d '\r\n')"
for t in D E F; do corps "$PAGE"; printf "  %s : %-28s nouveau prix vu %s fois, ancien %s fois\n" "$t" "$(entete 'x-litespeed-cache')" "$(prix_dans "$NOUVEAU")" "$(prix_dans "$ANCIEN")"; done
SHA_APRES=$(sha256sum "$TMP/b" | cut -c1-16)
corps "$JSON"; printf "  route JSON : %-24s nouveau prix vu %s fois\n" "$(entete 'x-litespeed-cache')" "$(prix_dans "$NOUVEAU")"

echo "$S ; 3. RESTAURATION ET PURGE"
wp post meta update "$PLAT" tuc_prix "$ANCIEN" --allow-root >/dev/null 2>&1
RESTAURE=0
FIN=$(wp post meta get "$PLAT" tuc_prix --raw --allow-root 2>/dev/null | tr -d '\r\n')
if [ "$FIN" = "$ANCIEN" ]; then echo "  base : $FIN = valeur d'origine, restauration ok"
else echo "  ECHEC DE RESTAURATION : la base dit '$FIN', attendu '$ANCIEN'"; fi
purger "$PAGE"; purger "$JSON"
for t in G H; do corps "$PAGE"; printf "  %s : %-28s ancien prix vu %s fois\n" "$t" "$(entete 'x-litespeed-cache')" "$(prix_dans "$ANCIEN")"; done
SHA_FIN=$(sha256sum "$TMP/b" | cut -c1-16)

echo "$S ; 4. VERDICT"
if [ "$SHA_AVANT" = "$SHA_APRES" ]; then
  echo "  bogue confirme : la page rendue est identique octet pour octet apres l'ecriture ($SHA_AVANT)."
  echo "  Le visiteur qui a la page en cache ne voit pas le nouveau prix."
else
  echo "  la page a change ($SHA_AVANT -> $SHA_APRES) : soit la purge suit l'ecriture, soit le cache ne retient pas cette page."
fi
echo "  apres restauration + purge : $SHA_FIN (page d'origine $SHA_AVANT)"
[ "$FIN" = "$ANCIEN" ] || exit 5
echo "$S ; FIN — aucune valeur fausse ne reste servie"
