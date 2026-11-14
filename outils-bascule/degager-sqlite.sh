#!/usr/bin/env bash
# TUC — dégager l'ancien moteur de /uat, sans rien jeter.
#
#   1. le dossier wp-content/database/ (copie SQLite figee + db-path.php + verrous) est DEPLACE
#      hors de la racine web, dans $HOME/archives-uat/, horodate. Il reste une base SQLite valide,
#      lisible avec n'importe quel outil — mais plus aucun chemin du site ne la charge.
#   2. wp-content/db.php.avant-mysql-* (l'interrupteur neutralise) part au meme endroit.
#   3. wp-content/plugins/sqlite-database-integration/ est retire du site (lui aussi archive).
#   4. l'error_log publiquement lisible est ferme par un bloc <Files> dans le .htaccess de /uat.
#
# Chaque deplacement est memorise ; si un controle rougit, tout revient a sa place avant sortie.
# Rien n'est supprime du disque. Usage :
#   bash degager-sqlite.sh --executer <racine>/uat <url-publique>
set -uo pipefail

EXECUTER=""; RACINE=""; URL=""
for a in "$@"; do case "$a" in --executer) EXECUTER=1 ;; *) [ -z "$RACINE" ] && RACINE="$a" || URL="$a" ;; esac; done
if [ "$EXECUTER" != 1 ]; then
  echo "REFUS : cette operation deplace des fichiers de la racine web. Passer --executer pour confirmer." >&2; exit 2
fi
if [ -z "$RACINE" ] || [ ! -d "$RACINE" ]; then echo "racine invalide : '$RACINE'" >&2; exit 2; fi
case "$RACINE" in */uat|*/uat/) ;; *) echo "REFUS : la racine ne finit pas par /uat ($RACINE)." >&2; exit 2 ;; esac
URL=$(printf '%s' "$URL" | sed 's:/*$::')
TS=$(date -u +%Y%m%dT%H%M%SZ)
ARCH="$HOME/archives-uat"
T=$(mktemp -d) || { echo "mktemp a echoue" >&2; exit 2; }; trap 'rm -rf "$T"' EXIT
PHP="${PHP:-php}"

# registre des deplacements : "D|source|dest" ou "F|source|dest" — relu a l'envers pour défaire
: > "$T/registre"
bar() { printf ' =============================================================\n'; }
# cle stable d'un chemin d'URL, pour poser son corps dans un fichier : basename("/kfc/") est "kfc"
# comme celui de "/wp-json/tuc/v1/menu/kfc", et basename("/") est vide.
cle() { printf '%s' "$1" | tr -c 'A-Za-z0-9' '_'; }
ROUGE=0
ko() { echo "   KO  $*"; ROUGE=$((ROUGE + 1)); }
ok() { echo "   OK  $*"; }

deplacer() { # $1 chemin à déplacer
  local src="$1" nom dest
  [ -e "$src" ] || return 0
  nom=$(basename "$src")
  dest="$ARCH/$TS--$nom"
  if mv "$src" "$dest"; then
    printf 'F|%s|%s\n' "$src" "$dest" >> "$T/registre"
    echo "   deplace : $src"
    echo "           -> $dest"
    return 0
  fi
  echo "   ECHEC du deplacement : $src"
  return 1
}

defaire() {
  echo " ============================================================"
  echo " RETOUR ARRIERE en cours (contrôle rouge) :"
  tac "$T/registre" | while IFS='|' read -r _ src dest; do
    [ -n "$src" ] || continue
    if mv "$dest" "$src" 2>/dev/null; then echo "   remis en place : $src"; else echo "   IMPOSSIBLE de remettre : $src"; fi
  done
  if [ -f "$T/htaccess.avant" ]; then
    cp "$T/htaccess.avant" "$RACINE/.htaccess" && echo "   .htaccess restaure a l'identique"
  fi
  echo " ============================================================"
}

bar; echo " 0. ETAT AVANT"; bar
echo "   racine : $RACINE"
echo "   url    : ${URL:-non fournie}"
echo "   archive: $ARCH"
mkdir -p "$ARCH" && chmod 700 "$ARCH" || { echo "   impossible de creer $ARCH"; exit 2; }
echo "   droits de l'accueil d'archive : $(stat -c '%a' "$ARCH") (700 = lisible par vous seul)"

# --- l'etatoù sont les choses, et leur signature, pour pouvoir prouver qu'on n'a rien abimé
CIBLES=()
for c in "$RACINE/wp-content/database" "$RACINE/wp-content/db.php" "$RACINE"/wp-content/db.php.avant-mysql-* \
         "$RACINE/wp-content/plugins/sqlite-database-integration"; do
  for x in $c; do [ -e "$x" ] && CIBLES+=("$x"); done
done
SQL=$(find "$RACINE/wp-content" -maxdepth 3 -name '*.sqlite' -size +100k 2>/dev/null | head -1)
SIGN=""
if [ -n "$SQL" ] && [ -f "$SQL" ]; then
  SIGN=$(cat > "$T/sign.php" <<'PHPEOF'
<?php
$f = (string) $argv[1];
$l = new SQLite3($f, SQLITE3_OPEN_READONLY);
$ic = (string) $l->querySingle('PRAGMA integrity_check');
$t = (int) $l->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
$r = 0; $sr = $l->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
while ($sr && $x = $sr->fetchArray(SQLITE3_NUM)) { $r += (int) $l->querySingle('SELECT COUNT(*) FROM "' . str_replace('"', '""', $x[0]) . '"'); }
printf("%s|%d tables|%d lignes|%d octets\n", $ic, $t, $r, filesize($f));
PHPEOF
  "$PHP" "$T/sign.php" "$SQL" 2>&1 | tail -1)
  echo "   copie SQLite : $SQL"
  echo "   signature    : $SIGN"
else
  echo "   aucune copie .sqlite trouvee sous wp-content"
fi
if [ -n "$URL" ]; then
  for p in "/" "/kfc/" "/wp-json/tuc/v1/menu/kfc" "/wp-json/tuc/v1/sante" "/error_log"; do
    printf "   avant  %-32s HTTP %s | %s o\n" "$p" \
      "$(curl -sS -m 25 -o "$T/b$(cle "$p")" -w '%{http_code}' -L "$URL$p" 2>/dev/null)" \
      "$(stat -c %s "$T/b$(cle "$p")" 2>/dev/null)"
  done
fi

bar; echo " 1. DEPLACEMENTS"; bar
for x in "${CIBLES[@]}"; do deplacer "$x" || { defaire; exit 9; }; done
[ ${#CIBLES[@]} -eq 0 ] && echo "   rien a deplacer (tout est deja parti)"

bar; echo " 2. L'ARCHIVE RESTE LISIBLE"; bar
SQL2=$(find "$ARCH" -maxdepth 3 -name '*.sqlite' -size +100k 2>/dev/null | head -1)
if [ -n "$SQL2" ] && [ -n "$SIGN" ]; then
  SIGN2=$("$PHP" "$T/sign.php" "$SQL2" 2>&1 | tail -1)
  echo "   signature apres deplacement : $SIGN2"
  [ "$SIGN" = "$SIGN2" ] && ok "la copie est intacte, table par table et ligne par ligne" || { ko "signature differente apres deplacement"; defaire; exit 9; }
elif [ -n "$SQL2" ]; then
  echo "   copie archivee : $SQL2 ($(stat -c %s "$SQL2") o)"
else
  echo "   pas de copie dans l'archive : rien a prouver"
fi

bar; echo " 3. ERROR_LOG FERME AU PUBLIC"; bar
# Lecons de la premiere tentative sur l'hote, toutes deux mesurees : (1) LiteSpeed n'applique pas
# `Require all denied` a l'interieur d'un <FilesMatch> — le bloc restait sans effet ; (2) il etait ecrit
# EN FIN de fichier, donc apres la regne `RewriteRule . index.php [L]` de WordPress, qui arrete le
# traitement : un garde-fou place derriere un [L] n'est jamais lu. Le bloc est donc PREPOSE, en trois
# syntaxes (rewrite = ce que LiteSpeed honore, filesmatch = Apache sans rewrite, deny = anciens
# serveurs), et le log quitte physiquement la racine. Le .htaccess est reconstruit a chaque passage :
# un bloc TUC precedent est retire puis repose, jamais duplique.
ELOG=""
for c in "$RACINE/error_log" "$RACINE/logs/error_log" "$RACINE/../error_log"; do
  [ -f "$c" ] && { ELOG="$c"; break; }
done
{
  echo "# TUC — fichiers hors sol : journaux, rests d'installation, bases. A ne jamais servir."
  echo "# Place AVANT le bloc WordPress : sa regne finale porte [L] et couperait tout ce qui suit."
  echo "RewriteEngine On"
  echo 'RewriteRule ^(.*/)?(error_log|[^/]*\.log|[^/]*\.sql|[^/]*\.sqlite|db-path\.php|db\.php(\..*)?)$ - [F,NS]'
  echo '<FilesMatch "^(error_log|.*\.log|.*\.sql|.*\.sqlite|db-path\.php|db\.php(\..*)?)$">'
  echo "  <IfModule mod_authz_core.c>"
  echo "    Require all denied"
  echo "  </IfModule>"
  echo "  <IfModule !mod_authz_core.c>"
  echo "    Order deny,allow"
  echo "    Deny from all"
  echo "  </IfModule>"
  echo "</FilesMatch>"
  if [ -n "$ELOG" ]; then
    echo "php_value error_log $ARCH/php-error.log"   # le log continue d'ecrire, hors de portee du web
  fi
  echo "# TUC — fin"
  echo ""
} > "$T/garde"
echo "   bloc de garde : $(wc -l < "$T/garde") lignes, $(stat -c %s "$T/garde") o"
if [ -f "$RACINE/.htaccess" ]; then
  cp "$RACINE/.htaccess" "$T/htaccess.avant"
  echo "   .htaccess avant : $(stat -c %s "$RACINE/.htaccess") o"
else
  echo "   pas de .htaccess a cet endroit : un nouveau sera cree"
fi
# aucun interpretteur externe dans le chemin critique : l'hebergeur ne promet pas python3, et un outil
# de deplacement qui en depend laisse un .htaccess a moitie ecrit avec le registre deja rempli.
{ cat "$T/garde"
  if [ -f "$RACINE/.htaccess" ]; then
    awk 'BEGIN{d=0; gob=0}
      /^# TUC .* fichiers hors sol/{d=1}
      d==0 { if (gob) { if ($0=="") { gob=0; next } gob=0 } print }
      /^# TUC .* fin$/{ if (d) { d=0; gob=1 } }' "$RACINE/.htaccess"
  fi
} > "$T/htaccess.nouveau"
mv "$T/htaccess.nouveau" "$RACINE/.htaccess"
echo "   .htaccess apres : $(stat -c %s "$RACINE/.htaccess") o (bloc en tete)"
if [ -n "$ELOG" ]; then
  echo "   le fichier de log lui-meme quitte la racine : $(stat -c %s "$ELOG") o"
  deplacer "$ELOG" || echo "   (deplacement rate : le bloc .htaccess reste la seule protection)"
else
  echo "   aucun error_log trouve sous la racine : rien a deplacer"
fi
PURGE_NECESSAIRE=0
if [ -n "$URL" ]; then
  c=$(curl -sS -m 20 -o "$T/after-log" -w '%{http_code}' "$URL/error_log" 2>/dev/null)
  if [ "$c" = "200" ] && [ -n "$ELOG" ] && [ ! -f "$ELOG" ]; then
    # Le fichier n'est plus sur le disque : ce 200 ne peut venir que du cache de l'hebergeur
    # (max-age 604800 — sept jours pendant lesquels la fuite se ressert toute seule). Accuser le
    # .htaccess serait un faux diagnostic : on casse la cle de cache, et seulement apres on juge.
    echo "   200 rendu alors que le fichier est parti : corps servi par le cache, pas par le disque"
    c2=$(curl -sS -m 20 -o "$T/after-log2" -w '%{http_code}' "$URL/error_log?nocache=$TS" 2>/dev/null)
    if command -v wp >/dev/null 2>&1; then
      if wp litespeed-purge url "$URL/error_log" >/dev/null 2>&1; then
        echo "   purge de cette URL demandee a LiteSpeed"
      else
        echo "   (wp-cli n'a pas pu purger : la cle de cache reste jusqu'a expiration)"
      fi
      c2=$(curl -sS -m 20 -o "$T/after-log3" -w '%{http_code}' "$URL/error_log?nocache=${TS}b" 2>/dev/null)
    fi
    case "$c2" in
      403|401|404)
        ok "hors cache, la meme URL repond $c2 : le garde-fou tient, c'est la copie cachee qui fuyait"
        PURGE_NECESSAIRE=1 ;;
      200) ko "error_log repond 200 hors cache avec le fichier absent : le serveur sert autre chose" ;;
      *) echo "   --  error_log repond $c2 hors cache : a verifier a la main" ;;
    esac
  else
    case "$c" in
      403|401|404) ok "error_log ne repond plus au public (HTTP $c)" ;;
      200) ko "error_log repond toujours 200 (fichier present) : rewrite et filesmatch non appliques" ;;
      500) ko ".htaccess refuse par le serveur (HTTP 500) : a annuler" ;;
      *) echo "   --  error_log repond $c : ni 200 ni refuse, a verifier a la main" ;;
    esac
  fi
fi

bar; echo " 4. FUMEE : LE SITE DOIT ETRE VERT APRES"; bar
FUMEE_OK=1
# Le fichier d'aide est ecrit AVANT son premier appel — la rechute etait deja arrivee sur ce projet,
# et le symptome etait un "Could not open input file" lu comme un resultat.
cat > "$T/apres.php" <<'PHPEOF'
<?php
declare(strict_types=1);
define('WP_USE_THEMES', false);
ob_start(); require $argv[1]; ob_end_clean();
global $wpdb;
printf("   moteur vu par WordPress : %s\n", (string) $wpdb->get_var('SELECT VERSION()'));
printf("   drop-in db.php pose ? %s\n", is_file(WP_CONTENT_DIR . '/db.php') ? 'OUI — le site peut redevenir SQLite' : 'non');
printf("   dossier de l'extension ? %s\n", is_dir(WP_PLUGIN_DIR . '/sqlite-database-integration') ? 'OUI' : 'non');
printf("   extension listee active ? %s\n", in_array('sqlite-database-integration/db.php', (array) get_option('active_plugins', []), true) ? 'OUI' : 'non');
printf("   taille du menu en base : %s lignes\n", (string) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->posts . " WHERE post_status = 'publish'"));
PHPEOF
if [ -n "$URL" ]; then
  for p in "/" "/kfc/" "/wp-json/tuc/v1/menu/kfc" "/wp-json/tuc/v1/sante"; do
    code=$(curl -sS -m 30 -o "$T/a$(cle "$p")" -w '%{http_code}' -L "$URL$p" 2>/dev/null)
    taille=$(stat -c %s "$T/a$(cle "$p")" 2>/dev/null)
    avant=$(stat -c %s "$T/b$(cle "$p")" 2>/dev/null)
    printf "   apres  %-32s HTTP %s | %s o (avant %s o)\n" "$p" "$code" "$taille" "${avant:-?}"
    [ "$code" = "200" ] || { FUMEE_OK=0; ko "$p ne repond plus 200"; }
    if [ -n "$avant" ] && [ "$avant" != "0" ] && [ -n "$taille" ] && [ "$taille" != "$avant" ]; then
      ko "$p a change de taille : $avant -> $taille"
    fi
  done
  # et le moteur doit toujours etre MySQL, sans plus aucune facon de revenir a SQLite
  "$PHP" "$T/apres.php" "$RACINE/wp-load.php" > "$T/apres.txt" 2>&1
  grep -av 'already defined\|Cannot modify header\|Stack trace\|^  #0\|^    thrown\|Fatal error' "$T/apres.txt" | sed 's/^/ /' | head -6
  MOTEUR=$(grep -a 'moteur vu par' "$T/apres.txt" | head -1)
  case "$MOTEUR" in
    *sqlite*|*SQLite*) ko "le moteur rapporte une version emulee : l'extension a ete rechargee"; FUMEE_OK=0 ;;
    *MariaDB*|*MySQL*) ok "moteur toujours MySQL/MariaDB" ;;
    *) echo "   --  version non relue (pas de wp-load ici) : controle a faire dans le log ci-dessus" ;;
  esac
  for sonde in "/" "/kfc/" "/wp-json/tuc/v1/sante"; do
    cc=$(curl -sS -m 20 -o /dev/null -w '%{http_code}' -L "$URL$sonde" 2>/dev/null)
    [ "$cc" = "200" ] || ko "le nouveau .htaccess casse $sonde (HTTP $cc)"
  done
else
  echo "   --  aucune URL : la fumee n'a pas pu etre jouee"
  FUMEE_OK=0
fi
bar; echo " 5. VERIFICATION FINALE"; bar
RESTE=$(find "$RACINE/wp-content" -maxdepth 3 \( -name '*.sqlite' -o -name 'db.php' -o -name 'db-path.php' \) 2>/dev/null | wc -l | tr -d ' ')
[ "$RESTE" = "0" ] && ok "plus aucun fichier de l'ancien moteur sous wp-content" || { ko "$RESTE fichier(s) restant(s)"; find "$RACINE/wp-content" -maxdepth 3 \( -name '*.sqlite' -o -name 'db.php*' \) 2>/dev/null | sed 's/^/     /'; }
[ -d "$RACINE/wp-content/plugins/sqlite-database-integration" ] && ko "le dossier de l'extension est encore la" || ok "extension retiree du site"
echo "   archive : $(du -sh "$ARCH" 2>/dev/null | awk '{print $1}') sous $ARCH ($TS--*)"
ls -1 "$ARCH" 2>/dev/null | sed -n '1,6p' | sed 's/^/     /'
if [ -n "$SQL" ]; then
  c=$(curl -sS -m 20 -o /dev/null -w '%{http_code}' "$URL${SQL#"$RACINE"}" 2>/dev/null)
  echo "   (l'ancien chemin repond maintenant HTTP ${c:-000} — c'est ce qu'on voulait)"
fi

if [ "${PURGE_NECESSAIRE:-0}" = 1 ]; then
  echo "   note : la copie deja mise en cache par LiteSpeed reste servie jusqu'a purge ou expiration"
  echo "   (max-age 604800 = sept jours). Le fichier est parti, le garde-fou tient : le cache se vide"
  echo "   depuis l'admin (LiteSpeed > Purger tout) ou par l'operation verif-litespeed-uat."
fi
if [ "$ROUGE" -ne 0 ] || [ "$FUMEE_OK" -ne 1 ]; then
  defaire
  echo "   OPERATION ANNULEE et defaite : voir les lignes KO ci-dessus."
  exit 9
fi
bar; echo " 6. VERDICT"; bar
echo "   l'ancien moteur est hors du chemin du site et hors de portee d'un clic ;"
echo "   la copie reste intacte et archivée, le site est vert, error_log est ferme."
echo "   pour revenir en arriere un jour : mv $ARCH/$TS--database \"$RACINE/wp-content/database\""
echo "   puis reposer db.php, et l'extension se re-installera depuis wordpress.org."
exit 0
