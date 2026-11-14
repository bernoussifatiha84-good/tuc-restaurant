#!/usr/bin/env bash
# TUC — etat du creneau MySQL avant toute bascule. LECTURE SEULE : ce script n'ecrit rien,
# n'arrete rien, ne cree rien. Il repond a une question et une seule : est-ce qu'on peut
# basculer /uat de SQLite vers MySQL, et si non, qu'est-ce qui manque exactement.
#
# Pourquoi cet ordre : la bascule a ete demandee « maintenant, tant qu'on n'a pas de vrai
# restaurant ni de vraie base ». C'est le bon moment, mais le moment ne suffit pas — il faut
# un creneau (base + utilisateur reels dans le wp-config). Sur /uat au 13/11, wp-config porte
# DB_NAME=tuc DB_USER=x DB_PASSWORD=<placeholder> : l'authentification repond #1045. Le
# serveur est donc VIVANT (une reponse 1045 prouve qu'il ecoute) et le creneau est absent.
# Ce script est ce qui transforme ce constat en decision, sans que j'aie a inventer un chiffre.
#
# Le mot de passe n'est jamais imprime : ni sa valeur, ni sa longueur significative. Seule sa
# forme (« placeholder » ou « non vide ») sort, pour que le journal du run reste publiable.
#
# Regles de l'outil, heritees de preflight.sh :
#   - aucun `wp db *` : proc_open est desactive sur cet hote, ces commandes pendent ;
#   - la question au serveur se pose en PHP/mysqli, pas en CLI ;
#   - tout ce qui n'est pas mesuré est écrit « non mesure », jamais suppose.
#
# Usage : bash creneau-mysql.sh <racine-du-site>   (ex. /home/u123456789/domaine/public_html/uat)
set -uo pipefail

RACINE="${1:-}"
if [ -z "$RACINE" ] || [ ! -d "$RACINE" ]; then
  echo "racine de site absente ou invalide : '$RACINE'" >&2
  exit 2
fi
case "$RACINE" in
  */uat|*/uat/) ;;
  *) echo "CE SCRIPT N'ACCEPTE UNE RACINE QUI FINIT PAR /uat. recu : $RACINE" >&2; exit 2 ;;
esac

T=$(mktemp -d 2>/dev/null) || { echo "mktemp a echoue" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
PHP=php
command -v php >/dev/null 2>&1 || PHP="/usr/bin/php"

bar() { printf ' =============================================================\n'; }

bar
echo " 0. CE QU'ON REGARDE"
bar
echo "   racine : $RACINE"
echo "   hote   : $(hostname 2>/dev/null || echo '?') | user : $(id -un 2>/dev/null || echo '?')"
echo "   date   : $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "   lecture seule : ce script ne ecrit dans aucun fichier du site."

# --- 1. le creneau tel que WordPress le voit, lu dans le wp-config --------------------------
cat > "$T/cfg.php" <<'PHPEOF'
<?php
// Lit les constantes DB_* sans executer wp-config (le fichier se comporte mal quand on
// l'inclut hors WordPress : il appelle abs() et define ABSPATH). Regex, pas include.
$cfg = (string) file_get_contents($argv[1]);
$out = [];
foreach (['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST','DB_CHARSET','DB_COLLATE','table_prefix'] as $k) {
    // WordPress echappe les quotes dans la valeur : 'a\'b'. Le motif doit survivre a \'.
    if (preg_match("/define\(\s*['\"]" . preg_quote($k, '/') . "['\"]\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfg, $m)) {
        $out[$k] = stripcslashes($m[1]);
    } elseif (preg_match("/\\\$" . preg_quote($k, '/') . "\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfg, $m)) {
        $out[$k] = stripcslashes($m[1]);
    } else {
        $out[$k] = null;
    }
}
// Le mot de passe ne sort jamais. On ne dit que s'il a une forme de placeholder.
$pass = (string) ($out['DB_PASSWORD'] ?? '');
$out['DB_PASSWORD_FORME'] = ($pass === '' ? 'vide' : ($pass === 'x' || $pass === 'password' || $pass === 'changeme' ? 'placeholder' : 'non vide'));
unset($out['DB_PASSWORD']);
$wpc = $cfg;
if (preg_match_all('/^\s*<(\?php|\?=)/m', $wpc, $mm, PREG_SET_ORDER)) {
    $out['LIGNES'] = substr_count($cfg, "\n") + 1;
}
$out['MARQUE_DROPIN'] = str_contains($cfg, 'sqlite') || str_contains($cfg, 'SQLITE') ? 'oui' : 'non';
foreach ($out as $k => $v) {
    fwrite(STDOUT, str_pad('   ' . $k, 22) . ($v === null ? 'absent du fichier' : (string) $v) . "\n");
}
PHPEOF
bar
echo " 1. LE CRENEAU TEL QUE WORDPRESS LE VOIT (wp-config.php)"
bar
CFG="$RACINE/wp-config.php"
if [ ! -f "$CFG" ]; then
  echo "   wp-config.php introuvable a la racine : $CFG"
  echo "   (site installe ailleurs ? la bascule ne se decrete pas d'ici)"
else
  $PHP "$T/cfg.php" "$CFG"
fi
echo "   le mot de passe n'est jamais affiche : seule sa FORME sort (vide / placeholder / non vide)."

# --- 2. le serveur repond-il, et avec quel code --------------------------------------------
cat > "$T/sonde.php" <<'PHPEOF'
<?php
// Meme lecture que ci-dessus, mais en variables, pour tenter une connexion relle.
$cfg = (string) file_get_contents($argv[1]);
$lit = static function (string $k) use ($cfg): ?string {
    if (preg_match("/define\(\s*['\"]" . preg_quote($k, '/') . "['\"]\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfg, $m)) {
        return stripcslashes($m[1]);
    }
    return null;
};
$name = (string) ($lit('DB_NAME') ?? '');
$user = (string) ($lit('DB_USER') ?? '');
$pass = (string) ($lit('DB_PASSWORD') ?? '');
$host = (string) ($lit('DB_HOST') ?? 'localhost');
$port = 3306;
if (str_contains($host, ':')) { [$host, $p] = explode(':', $host, 2); $port = (int) $p; }
$socket = '';
if ($host === 'localhost' && is_string((string) ini_get('mysqli.default_socket')) && file_exists((string) ini_get('mysqli.default_socket'))) {
    $socket = (string) ini_get('mysqli.default_socket');
}
echo '   cible : ' . ($socket !== '' ? 'socket ' . $socket : 'tcp ' . $host . ':' . $port) . "\n";
echo '   base : ' . ($name === '' ? '(vide)' : $name) . ' | user : ' . ($user === '' ? '(vide)' : $user) . "\n";

mysqli_report(MYSQLI_REPORT_OFF);
@ini_set('mysqli.connect_timeout', 4);
// Signature reelle : mysqli_connect(host, user, pass, dbname, port, socket).
$cn = ($socket !== '')
    ? @mysqli_connect($host, $user, $pass, $name, $port, $socket)
    : @mysqli_connect($host, $user, $pass, $name, $port);
if (!$cn) {
    $e = mysqli_connect_errno();
    $msg = mysqli_connect_error();
    // 1045 = le serveur ecoute et refuse l'identite. 2002/2003 = rien derriere.
    $verdict = match (true) {
        $e === 1045 => 'SERVEUR VIVANT, IDENTIFIANTS REJETES -> le creneau n existe pas (ou pas encore)',
        $e === 1044 => 'SERVEUR VIVANT, UTILISATEUR SANS DROIT SUR CETTE BASE -> creneau existant mais mal attribue',
        $e === 2002 || $e === 2003 => 'RIEN N ECOUTE -> pas de serveur MySQL accessible depuis ce site',
        default => 'ECHEC (' . $e . ')',
    };
    echo '   connexion : ' . $msg . "\n";
    echo "   -> $verdict\n";
    exit(0);
}
echo "   connexion : OK (le creneau existe et cet utilisateur y a acces)\n";
$ver = $cn->query('SELECT VERSION()');
if ($ver && $r = $ver->fetch_row()) { echo '   version   : ' . $r[0] . "\n"; }
$sm = $cn->query("SELECT @@GLOBAL.sql_mode AS m, @@GLOBAL.time_zone AS tz");
if ($sm && $r = $sm->fetch_assoc()) {
    echo '   sql_mode GLOBAL : ' . ($r['m'] === '' ? '(vide)' : $r['m']) . "\n";
    echo '   time_zone       : ' . $r['tz'] . "\n";
    // Le seul point dur de la conversion : 29 colonnes du schema de core portent
    // DEFAULT '0000-00-00 00:00:00'. WordPress ne detend NO_ZERO_DATE que dans SA session,
    // pas pour les CREATE TABLE. D'ou la ligne ci-dessous, non negociee.
    foreach (['NO_ZERO_DATE','NO_ZERO_IN_DATE','STRICT_TRANS_TABLES','STRICT_ALL_TABLES','TRADITIONAL','ONLY_FULL_GROUP_BY','ANSI'] as $flag) {
        if (str_contains((string) $r['m'], $flag)) { echo "     + $flag present au niveau GLOBAL : les CREATE TABLE en heriteront\n"; }
    }
}
$q = $cn->query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
$n = ($q && $r = $q->fetch_assoc()) ? (int) $r['n'] : -1;
echo '   tables deja presentes dans cette base : ' . ($n < 0 ? 'non mesure' : (string) $n)
   . ($n === 0 ? "  <- vide, comme attendu pour une cible de bascule\n" : "\n");
if ($n > 0) {
    echo "   ATTENTION : la base de destination n est pas vide. La bascule refusera d'ecrir\n";
    echo "   sans TUC_FORCER=1. Ni effacement ni ecrasage implicite.\n";
}
$g = $cn->query('SHOW GRANTS FOR CURRENT_USER()');
if ($g) { while ($r = $g->fetch_row()) { echo '   grant : ' . $r[0] . "\n"; } }
$cn->close();
PHPEOF
bar
echo " 2. LE SERVEUR REPEND-IL (question posee en PHP, pas en CLI : proc_open est desactive)"
bar
if [ -f "$CFG" ]; then $PHP "$T/sonde.php" "$CFG"; else echo "   non mesure : pas de wp-config"; fi

# --- 3. l'etat reel du site : ce qui porte les donnees aujourd'hui -------------------------
bar
echo " 3. CE QUI PORTE LES DONNEES AUJOURD'HUI"
bar
for f in wp-content/db.php wp-content/database; do
  if [ -e "$RACINE/$f" ]; then
    if [ -d "$RACINE/$f" ]; then
      echo "   $f : dossier, $(du -sk "$RACINE/$f" 2>/dev/null | awk '{print $1}') Ko"
      ls -1 "$RACINE/$f" 2>/dev/null | sed 's/^/       /' | head -8
    else
      echo "   $f : $(stat -c '%s octets, modifie %y' "$RACINE/$f" 2>/dev/null | cut -d. -f1)"
    fi
  else
    echo "   $f : ABSENT"
  fi
done
DP="$RACINE/wp-content/database/db-path.php"
if [ -f "$DP" ]; then
  echo "   db-path.php : $(cut -c1-150 "$DP" | tr -d '\n' | tr -d "'<>?;=" | sed 's/^ *//')"
  SQLITE=$(sed -n "s/.*['\"]\(.*\.sqlite\)['\"].*/\1/p" "$DP" | head -1 | tr -d ' \r')
  for cand in "$RACINE/wp-content/database/$SQLITE" "$RACINE/wp-content/database/.ht.sqlite"; do
    if [ -n "$cand" ] && [ -f "$cand" ]; then
      echo "   fichier SQLite : $cand ($(stat -c %s "$cand" 2>/dev/null) o)"
      for ext in -wal -shm; do
        [ -f "$cand$ext" ] && echo "     $ext : $(stat -c %s "$cand$ext") o"
      done
      break
    fi
  done
else
  echo "   db-path.php : ABSENT -> soit le drop-in n'est pas ce plugin, soit la base n'est pas SQLite."
fi

# --- 4. les poids qui decident du temps et de la place --------------------------------------
bar
echo " 4. POIDS ET PLACE (ce que la conversion va devoir deplacer)"
bar
for d in wp-content/uploads wp-content; do
  if [ -d "$RACINE/$d" ]; then
    echo "   $d : $(du -sk "$RACINE/$d" 2>/dev/null | awk '{print $1}') Ko"
  else
    echo "   $d : absent"
  fi
done
echo "   place libre sur le volume : $(df -Pk "$RACINE" 2>/dev/null | awk 'NR==2{printf "%.0f Mo", $4/1024}')"
for c in mysql mysqldump mariadb wp php; do
  printf "   outillage : %-11s %s\n" "$c" "$(command -v "$c" >/dev/null 2>&1 && echo present || echo ABSENT)"
done

# --- 5. ce que le site sert en public, pour ne pas confondre avec un etat de base ----------
bar
echo " 5. COMPTES REELS (mesures, pas des suppositions)"
bar
WPC="$RACINE/wp-load.php"
if [ -f "$WPC" ]; then
  cat > "$T/comptes.php" <<'PHPEOF'
<?php
// Les comptes se prennent par WordPress lui-meme : c'est le drop-in qui sait ce qu'est une
// table dans cette installation, pas une requete que j'inventerais a cote.
define('WP_USE_THEMES', false);
ob_start();
require $argv[1];
ob_end_clean();
global $wpdb;
$wpdb->show_errors = false;
$tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
echo '   tables vues par WordPress (via son drop-in) : ', is_array($tables) ? count($tables) : 'non mesure', "\n";
$n = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tuc_resto'");
echo '   fiches resto en base : ', (string) (int) $n, "\n";
$m = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'tuc_prix'");
echo '   valeurs monetaires (tuc_prix) : ', (string) (int) $m, "\n";
$h = get_option('home');
echo '   home_url : ', is_string($h) ? $h : '?', "\n";
echo '   moteur vu par le drop-in : ', (string) (defined('DB_DRIVER') ? DB_DRIVER : get_option('db_driver', '?')), "\n";
PHPEOF
  $PHP "$T/comptes.php" "$WPC" 2>&1 | grep -av 'already defined\|Cannot modify header' | head -14
  echo "   wp-load.php absent : comptes non mesures depuis ce script (le preflight les porte)."
fi

bar
echo " 6. CE QUI RESTE A FAIRE, PAR ORDRE"
bar
echo "   a. cote humain : creer base + utilisateur MySQL pour /uat dans le panneau d'hebergement,"
echo "      puis reporter les quatre valeurs dans le wp-config du site (c est la que la bascule les relit)."
echo "      Aucun mot de passe ne doit transiter par moi : le convertisseur relit wp-config lui-meme."
echo "   b. cote pipeline : TUC_MODE=dry (conversion a blanc, rien d'ecrit) -> run -> controle"
echo "      cellulaire -> mv du drop-in -> fumee -> retour arriere automatique si la fumee rouge."
echo "   c. le trou de cache n'est PAS dans ce chantier : verifie le 13/11, la purge fonctionne"
echo "      depuis que l'URL est la bonne (0.8.24). Rien ici ne le rouvre."
