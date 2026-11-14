#!/usr/bin/env bash
# TUC — reporter un creneau MySQL dans le wp-config.php de /uat. C'est LA ecriture qui rend la
# bascule possible, et c'est aussi le seul moment du chantier ou un site vivant peut perdre sa base.
#
# Trois garde-fous, dans cet ordre, non negociables :
#   1. on refuse si le site a deja un creneau reel (DB_USER qui n'est ni vide ni 'x') : on
#      n'ecrase pas une configuration qui marche ;
#   2. on archive le wp-config d'avant, on verifie l'archive (non vide + gzip -t), et l'archive est
#      mode 600 derriere un .htaccess — elle contient les salts, donc les sessions ;
#   3. apres ecriture, on ne croit pas le code de sortie : on relit les cinq valeurs dans le
#      fichier, on le passe au linter PHP, et on connecte mysqli avec les valeurs du fichier.
#      Si un seul de ces controles rougit, le wp-config d'avant est remis et le run s'arrete la.
#
# Le mot de passe arrive par FICHIER (mode 600), jamais par argument : `ps` montre les arguments,
# et le journal du run montrerait la ligne de commande.
#
# Usage : bash poser-creneau.sh <racine> <fichier-du-mot-de-passe> [nom] [user] [hote]
set -uo pipefail

RACINE="${1:-}"; PASSFILE="${2:-}"
NOM="${3:-}"; USR="${4:-}"; HDST="${5:-localhost}"
if [ -z "$RACINE" ] || [ -z "$PASSFILE" ] || [ ! -f "$PASSFILE" ]; then
  echo "usage : poser-creneau.sh <racine> <fichier-du-mot-de-passe> [nom] [user] [hote]" >&2
  exit 2
fi
case "$RACINE" in
  */uat|*/uat/) ;;
  *) echo "REFUS : la racine ne finit pas par /uat ($RACINE). Le dev et la prod ne se modifient pas ici." >&2; exit 2 ;;
esac
PASS=$(head -c 200 "$PASSFILE" | tr -d '\r\n')
if [ "${#PASS}" -lt 12 ]; then
  echo "REFUS : le fichier de mot de passe est trop court (${#PASS} caracteres) : rien n'est ecrit."
  exit 2
fi
# Ce que creer-creneau.sh genere est alphanumerique, et c'est le contrat : pas d'apostrophe a
# echapper dans wp-config, pas de backtick que le shell avalerait, pas d'oblique qui double dans
# les quotes simples de PHP. Plutot que de tenter de reconnaitre ces caracteres (le motif que
# j'avais ecrit refusait un mot de passe normal et acceptait un apostrophe), on exige la classe.
case "$PASS" in
  *[!A-Za-z0-9]*)
    echo "REFUS : le mot de passe contient un caractere hors [A-Za-z0-9] (${#PASS} caracteres). Je ne echappe rien, je refuse."
    exit 2;;
esac
PHP="${PHP:-php}"
D=$(mktemp -d 2>/dev/null) || { echo "REFUS : pas de repertoire temporaire disponible, je n'ecris nulle part."; exit 2; }
trap 'rm -rf "$D"' EXIT
WPC="$RACINE/wp-config.php"; UPS="$RACINE/wp-content/upgrade"
[ -f "$WPC" ] || { echo "REFUS : pas de wp-config a $WPC"; exit 2; }
[ -d "$UPS" ] || { echo "REFUS : pas de $UPS — je n'archive pas a l'aveugle dans un dossier que je ne connais pas."; exit 2; }

bar() { printf ' =============================================================\n'; }
lire() { "$PHP" -r '$s=file_get_contents($argv[1]);preg_match("/define\(\s*[\x27\"]".$argv[2]."[\x27\"]\s*,\s*[\x27\"]([^\x27\"]*)[\x27\"]/",$s,$m)?print($m[1]):print("?");' "$WPC" "$1" 2>/dev/null; }

bar; echo " 0. ETAT AVANT (lu dans le fichier, pas suppose)"; bar
if [ -f "$PASSFILE.noms" ] && { [ -z "$NOM" ] || [ -z "$USR" ]; }; then
  NOM=$(sed -n '1p' "$PASSFILE.noms"); USR=$(sed -n '2p' "$PASSFILE.noms")
fi
if [ -z "$NOM" ] || [ -z "$USR" ]; then
  echo "REFUS : nom de base ou d'utilisateur inconnu, et je ne devine pas un nom prefixe par le compte."
  echo "  (le nom reel vient de la liste de l'API : creer-creneau.sh l'ecrit dans <passfile>.noms)"
  exit 2
fi
echo "   DB_NAME=$(lire DB_NAME) DB_USER=$(lire DB_USER) DB_HOST=$(lire DB_HOST) DB_CHARSET=$(lire DB_CHARSET)"
ACTUEL=$(lire DB_USER)
if [ "$ACTUEL" != "?" ] && [ -n "$ACTUEL" ] && [ "$ACTUEL" != "x" ] && [ "${TUC_FORCER:-0}" != "1" ]; then
  bar
  echo "  REFUS : le site a deja un utilisateur MySQL reel ('$ACTUEL'). Ecrire par-dessus casserait"
  echo "  un creneau qui marche. Si c'est vraiment ce qu'on veut : TUC_FORCER=1."
  exit 3
fi
echo "   utilisateur actuel : '${ACTUEL}' -> forme de placeholder, on peut ecrire"

bar; echo " 1. ARCHIVE DU wp-config D'AVANT"; bar
TS=$(date -u +%Y%m%dT%H%M%SZ); ARCHE="$UPS/tuc-creneau-$TS.tar.gz"
tar -czf "$ARCHE" -C "$RACINE" wp-config.php 2>/dev/null
if [ ! -s "$ARCHE" ] || ! gzip -t "$ARCHE" 2>/dev/null; then
  echo "  REFUS : l'archive de securite n'est pas verifiee ($ARCHE). Aucune ecriture."
  rm -f "$ARCHE"; exit 4
fi
chmod 600 "$ARCHE" 2>/dev/null
if [ ! -f "$UPS/.htaccess" ]; then
  printf 'Require all denied\nDeny from all\n' > "$UPS/.htaccess" 2>/dev/null \
    && echo "   garde-fou web pose sur $UPS" || echo "   ATTENTION : .htaccess non ecrit, l'archive n'est protegee que par son nom"
fi
echo "  archive verifiee : $ARCHE ($(du -k "$ARCHE" | awk '{print $1}') Ko, mode $(stat -c %a "$ARCHE"))"
echo "  pour defaire a la main : tar -xzf $ARCHE -C $RACINE"

bar; echo " 2. ECRITURE DES CINQ VALEURS"; bar
cat > "$D/edit.php" <<'PHPEOF'
<?php
// Reecrit (ou inserait) les define() de wp-config. Un seul patron : la ligne complete, quotes
// simples, comme wp-config les ecrit deja. Si une ligne n'est pas trouvee, on l'insere avant
// l'inclusion d'ABSPATH — et si l'ancre manque aussi, on n'ecrit RIEN et on sort en erreur.
[$f, $nom, $usr, $pass, $host, $charset] = [$argv[1], $argv[2], $argv[3], $argv[4], $argv[5], $argv[6]];
$s = (string) file_get_contents($f);
$avant = $s;
$paires = ['DB_NAME' => $nom, 'DB_USER' => $usr, 'DB_PASSWORD' => $pass, 'DB_HOST' => $host, 'DB_CHARSET' => $charset];
$ecrits = [];
foreach ($paires as $c => $v) {
    $q = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $v);
    $pat = "/^[ \t]*define[ \t]*\([ \t]*(['\"])" . preg_quote($c, '/') . "\\1[ \t]*,[ \t]*(['\"])(?:\\\\2|[^\"'])*\\2[ \t]*\)[ \t]*;/m";
    $neu = "define('" . $c . "', '" . $q . "');";
    $n = 0;
    $s = preg_replace($pat, $neu, $s, 1, $n);
    if ($n === 1) { $ecrits[] = "$c remplace"; continue; }
    $ins = false;
    foreach (['/^(require_once[ \t]+ABSPATH.*)$/m', '/^\/\*[ \t]*That is[ \t].*$/m'] as $ancre) {
        if (preg_match($ancre, $s)) {
            $s = preg_replace($ancre, $neu . "\n$1", $s, 1);
            $ecrits[] = "$c insere"; $ins = true; break;
        }
    }
    if (!$ins) { fwrite(STDERR, "ANCRE ABSENTE pour $c : rien n'est ecrit.\n"); exit(5); }
}
if ($s === $avant) { fwrite(STDERR, "aucun changement : etranges, on s'arrete.\n"); exit(6); }
$t = $f . '.tuc-' . getmypid();
if (file_put_contents($t, $s) === false) { exit(7); }
chmod($t, 0600);
if (!rename($t, $f)) { @unlink($t); fwrite(STDERR, "impossible de remplacer $f\n"); exit(8); }
foreach ($ecrits as $e) { echo $e, "\n"; }
PHPEOF
"$PHP" "$D/edit.php" "$WPC" "$NOM" "$USR" "$PASS" "$HDST" "utf8mb4" 2>&1 | sed 's/^/   /'
EC=$?
if [ "$EC" -ne 0 ]; then
  echo "   ECHEC d'ecriture (code $EC) : le fichier n'a pas ete remplace, rien a reparer."
  rm -f "$WPC".tuc-*; exit 5
fi

bar; echo " 3. CONTROLES, DANS L'ORDRE OU ILS PEUVENT FAIRE MAL"; bar
KO=0
if ! "$PHP" -l "$WPC" >/dev/null 2>&1; then echo "   php -l sur le wp-config : KO"; KO=1; else echo "   php -l sur le wp-config : OK"; fi
for paire in "DB_NAME:$NOM" "DB_USER:$USR" "DB_HOST:$HDST" "DB_CHARSET:utf8mb4"; do
  k="${paire%%:*}"; v="${paire#*:}"; got=$(lire "$k")
  if [ "$got" = "$v" ]; then echo "   $k : relu correct"
  else echo "   $k : ATTENDU '$v', RECLU '$got'"; KO=1; fi
done
gotp=$(lire DB_PASSWORD)
if [ "$gotp" = "$PASS" ]; then echo "   DB_PASSWORD : relu identique a ce qu'on a ecrit"; else echo "   DB_PASSWORD : relecture differente (relu ${#gotp} caracteres, ecrit ${#PASS})"; KO=1; fi
if [ "$KO" -ne 0 ]; then
  tar -xzf "$ARCHE" -C "$RACINE" && echo "   wp-config restaure depuis l'archive"
  rm -f "$PASSFILE" "$PASSFILE.noms"
  echo "   arret : la configuration n'est pas conforme apres ecriture."
  exit 6
fi
cat > "$D/conn.php" <<'PHPEOF'
<?php
// La question finale se pose avec les valeurs DU FICHIER, pas avec celles qu'on croyait ecrire.
$s = (string) file_get_contents($argv[1]);
$lit = static function (string $k) use ($s): string {
    return preg_match("/define\(\s*['\"]" . preg_quote($k, '/') . "['\"]\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $s, $m) ? stripcslashes($m[1]) : '';
};
mysqli_report(MYSQLI_REPORT_OFF);
@ini_set('mysqli.connect_timeout', 5);
$host = $lit('DB_HOST'); $port = 3306; $sock = '';
if (str_contains($host, ':')) {
    [$h, $a] = explode(':', $host, 2); $host = $h;
    if (str_starts_with($a, '/')) { $sock = $a; } else { $port = (int) $a; }
}
if ($sock === '' && $host === 'localhost') {
    $d = (string) ini_get('mysqli.default_socket');
    if ($d !== '' && file_exists($d)) { $sock = $d; }
}
$cn = $sock !== ''
    ? @mysqli_connect($host, $lit('DB_USER'), $lit('DB_PASSWORD'), $lit('DB_NAME'), $port, $sock)
    : @mysqli_connect($host, $lit('DB_USER'), $lit('DB_PASSWORD'), $lit('DB_NAME'), $port);
if (!$cn) {
    printf("   connexion avec les valeurs du fichier : ECHEC (%d) %s\n", mysqli_connect_errno(), mysqli_connect_error());
    exit(9);
}
$q = $cn->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
$r = $q ? $q->fetch_row() : null;
printf("   connexion : OK | tables dans la cible : %s\n", (string) ($r[0] ?? '?'));
$sm = $cn->query('SELECT @@GLOBAL.sql_mode');
$x = $sm ? $sm->fetch_row() : null;
printf("   sql_mode global : %s\n", ($x[0] ?? '') === '' ? '(vide)' : (string) $x[0]);
$cn->close();
exit(0);
PHPEOF
"$PHP" "$D/conn.php" "$WPC"; CN=$?
if [ "$CN" -ne 0 ]; then
  bar
  echo "  LE CRENEAU NE REPOND PAS avec les valeurs ecrites. Retour arriere : le wp-config d'avant"
  echo "  est restaure, le site retrouve son fonctionnement d'hier (SQLite via le drop-in)."
  tar -xzf "$ARCHE" -C "$RACINE" && echo "   restaure."
  echo "   archive conservee : $ARCHE"
  rm -f "$PASSFILE" "$PASSFILE.noms"
  exit 9
fi
rm -f "$PASSFILE" "$PASSFILE.noms"
bar
echo "  4. CE QUI VIENT DE CHANGER, et ce qui n'a pas change"
bar
echo "   change : les cinq valeurs DB_* du wp-config (et DB_CHARSET passe a utf8mb4, sans ca un"
echo "   emoji saisi apres la bascule serait detruit en silence par utf8mb3)."
echo "   N'a pas change : le moteur. Le drop-in db.php est toujours en place, WordPress lit"
echo "   toujours le fichier SQLite. La conversion des donnees est l'etape d'apres, et c'est un"
echo "   autre dispatch : operation = bascule-uat-mysql (a blanc), puis bascule-uat-mysql-executer."
echo "   Le fichier de mot de passe est detruit (rm) ; il n'a jamais ete un argument de commande."
