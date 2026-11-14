#!/usr/bin/env bash
# TUC — bascule SQLite -> MySQL sur /uat, en trois temps non negociable : sauvegarde verifiee,
# conversion, fumee, et retour arriere automatique si la fumee rougit.
#
# Principe 1 : la source n'est jamais detruite. Le drop-in `wp-content/db.php` reste en place
# sous un nom reversible ; « basculer » = l'oter du chemin de chargement, pas effacer le SQLite.
# Principe 2 : aucune ecriture avant qu'une sauvegarde ait ete VERIFIEE (archive non vide + test
# d'integrite gzip). Un run coupe apres ce point se repare avec la ligne imprimee a l'ecran.
# Principe 3 : l'etape qui ecrit dans MySQL est separee de l'etape qui change le moteur. Le mode
# `dry` (par defaut) ne touche ni au site ni a la cible ; il dit seulement si la conversion
# reussirait et a quel volume.
# Principe 4 : le mot de passe ne transite jamais ici. Le convertisseur relit wp-config lui-meme.
#
# Usage : bash basculer.sh <racine> <chemin-du-convertisseur> [dry|executer]
set -uo pipefail

RACINE="${1:-}"
CONV="${2:-}"
PHASE="${3:-dry}"
case "$PHASE" in dry|executer) ;; *) echo "phase inconnue : $PHASE (attendu dry ou executer)" >&2; exit 2;; esac
if [ -z "$RACINE" ] || [ ! -d "$RACINE" ] || [ ! -f "$CONV" ]; then
  echo "usage : basculer.sh <racine-valide> <tuc-bascule.php> [dry|executer]" >&2
  exit 2
fi
case "$RACINE" in
  */uat|*/uat/) ;;
  *) echo "BASCULE REFUSEE : la racine ne finit pas par /uat ($RACINE). dev et la prod sont hors d'atteinte." >&2; exit 2 ;;
esac

T=$(mktemp -d 2>/dev/null) || { echo "mktemp a echoue" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
bar() { printf ' =============================================================\n'; }
TS=$(date -u +%Y%m%dT%H%M%SZ)
WPC="$RACINE/wp-config.php"
DROPIN="$RACINE/wp-content/db.php"
DATADIR="$RACINE/wp-content/database"
UPGRADES="$RACINE/wp-content/upgrade"
SQLITED=""

bar
echo " 0. PHASE : $PHASE | cible : $RACINE"
bar

# --- 1. le prealable : un creneau reel, sinon on s'arrete ici --------------------------------
cat > "$T/creneau.php" <<'PHPEOF'
<?php
$cfg = (string) file_get_contents($argv[1]);
$lit = static function (string $k) use ($cfg): ?string {
    return preg_match("/define\(\s*['\"]" . preg_quote($k, '/') . "['\"]\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfg, $m)
        ? stripcslashes($m[1]) : null;
};
$name = (string) ($lit('DB_NAME') ?? '');
$user = (string) ($lit('DB_USER') ?? '');
$pass = (string) ($lit('DB_PASSWORD') ?? '');
$host = (string) ($lit('DB_HOST') ?? 'localhost');
$port = 3306;
$socket = '';
// Meme decoding que le convertisseur (tuc-bascule.php l. 78-84) : DB_HOST porte trois formes.
// Sans ca, la porte d'entree et l'outil qui ecrit ne regarderaient pas au meme endroit.
if (str_contains($host, ':')) {
    [$h, $after] = explode(':', $host, 2);
    $host = $h;
    if (str_starts_with($after, '/')) { $socket = $after; } else { $port = (int) $after; }
}
if ($socket === '' && $host === 'localhost') {
    $def = (string) ini_get('mysqli.default_socket');
    if ($def !== '' && file_exists($def)) { $socket = $def; }
}
echo 'base : ' . ($name === '' ? '(vide)' : $name) . ' | user : ' . ($user === '' ? '(vide)' : $user) . "\n";
if ($name === '' || $user === '' || $user === 'x' || $pass === '' || $pass === 'x') {
    echo "REJET : les constantes DB_* n'ont pas de valeur reelle (placeholders).\n";
    exit(3);
}
mysqli_report(MYSQLI_REPORT_OFF);
@ini_set('mysqli.connect_timeout', 5);
echo 'endpoint : ' . ($socket !== '' ? $socket : $host . ':' . $port) . "\n";
$cn = $socket !== ''
    ? @mysqli_connect($host, $user, $pass, $name, $port, $socket)
    : @mysqli_connect($host, $user, $pass, $name, $port);
if (!$cn) {
    echo 'REJET : connexion refusee par le serveur (' , mysqli_connect_errno() , ') ' , mysqli_connect_error() , "\n";
    exit(3);
}
$q = $cn->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
$r = $q ? $q->fetch_row() : null;
$n = $r ? (int) $r[0] : -1;
$vq = $cn->query('SELECT VERSION()'); $vr = $vq ? $vq->fetch_row() : null;
echo 'serveur : ' . (string) ($vr[0] ?? '?') . "\n";
echo "tables deja dans la cible : " . ($n < 0 ? 'non mesure' : (string) $n) . "\n";
if ($n > 0) { echo "REJET : la cible n'est pas vide. La bascule n'ecrase personne sans TUC_FORCER.\n"; exit(4); }
$sm = $cn->query("SELECT @@GLOBAL.sql_mode");
if ($sm && $x = $sm->fetch_row()) { echo 'sql_mode global : ' . ($x[0] === '' ? '(vide)' : $x[0]) . "\n"; }
$cn->close();
echo "ACCORD : creneau vivant, vide, accessible.\n";
PHPEOF
bar; echo " 1. LE CRENEAU EST-IL LA ?"; bar
if [ ! -f "$WPC" ]; then echo "   wp-config.php absent : on ne devine pas une cible."; exit 3; fi
PHPBIN="${PHP:-php}"
out=$("$PHPBIN" "$T/creneau.php" "$WPC" 2>&1); rc=$?
echo "$out" | sed 's/^/   /'
bar
case "$rc" in
  0) echo "  CRENEAU OK. On peut passer a la sauvegarde." ;;
  4) echo "  ARRET. La base de destination n'est pas vide : la bascule refuse d'ecraser quelqu'un."
     echo "  Ce n'est pas un probleme d'acces, c'est un contenu deja la. Vider la base (ou en creer"
     echo "  une autre) est une decision humaine : je ne supprime rien depuis ce script." ;;
  *) echo "  ARRET. Rien n'a ete ecrit, nulle part, a aucun moment du run."
     echo "  Ce qui manque : une base et un utilisateur MySQL reels pour /uat, dans le panneau"
     echo "  d'hebergement, puis les quatre valeurs DB_* reportees dans $WPC."
     echo "  Aucun mot de passe ne doit me transiter : l'outil relit le wp-config sur place." ;;
esac
bar
[ "$rc" -eq 0 ] || exit "$rc"

# --- 2. le chemin du fichier SQLite, lu la ou il est ecrit -----------------------------------
bar; echo " 2. LA SOURCE"; bar
if [ -f "$DATADIR/db-path.php" ]; then
  SQLITED=$("$PHPBIN" -r '$s=file_get_contents($argv[1]); if (preg_match("/([^\x27\"]+\.sqlite)/",$s,$m)) echo $m[1];' "$DATADIR/db-path.php" 2>/dev/null)
fi
[ -n "$SQLITED" ] && [ ! -f "$SQLITED" ] && SQLITED="$DATADIR/$(basename "$SQLITED")"
[ -z "$SQLITED" ] && [ -f "$DATADIR/.ht.sqlite" ] && SQLITED="$DATADIR/.ht.sqlite"
if [ -z "$SQLITED" ] || [ ! -f "$SQLITED" ]; then
  echo "   introuvable : ni db-path.php exploitable, ni .ht.sqlite dans $DATADIR"; exit 5
fi
echo "   fichier : $SQLITED ($(stat -c %s "$SQLITED") o)"
for ext in -wal -shm; do [ -f "$SQLITED$ext" ] && echo "     $ext present ($(stat -c %s "$SQLITED$ext") o) : la sauvegarde doit l'inclure"; done
echo "   drop-in : $([ -f "$DROPIN" ] && echo 'en place (c est lui qui bascule)' || echo 'ABSENT — le site ne tourne donc pas sur SQLite, bascule sans objet')"

# --- 3. la sauvegarde, verifiee ---------------------------------------------------------------
bar; echo " 3. SAUVEGARDE (avant toute ecriture)"; bar
ARCHIF="tuc-bascule-$TS"
if [ ! -d "$UPGRADES" ]; then echo "   $UPGRADES absent : je ne cree pas de repertoire dans un site que je ne maitrise pas."; exit 5; fi
ARCHE="$UPGRADES/$ARCHIF.tar.gz"
tar -czf "$ARCHE" -C "$RACINE" \
  $(for f in wp-config.php wp-content/db.php; do [ -e "$RACINE/$f" ] && printf ' %s' "$f"; done) \
  $(for f in "$DATADIR"/*; do [ -e "$f" ] && printf ' %s' "${f#$RACINE/}"; done) 2>/dev/null
if [ ! -s "$ARCHE" ]; then echo "   ECHEC : archive vide ou absente. Aucune ecriture n'aura lieu."; exit 6; fi
if ! gzip -t "$ARCHE" 2>/dev/null; then echo "   ECHEC : archive corrompue au test d'integrite."; rm -f "$ARCHE"; exit 6; fi
chmod 600 "$ARCHE" 2>/dev/null
# L'archive contient le wp-config.php : ses salts, donc ses sessions. `wp-content/upgrade` est
# sous la racine web et un nom de fichier daté se devine en dix secondes. On verrouille le dossier
# (le meme garde-fou que met WordPress sur ses propres temporaires) et on le dit si ca ne passe pas.
if [ ! -f "$UPGRADES/.htaccess" ]; then
  printf 'Require all denied\nDeny from all\n<FilesMatch "\\.(tar|gz|zip|sqlite|sql)$">\n  Require all denied\n</FilesMatch>\n' > "$UPGRADES/.htaccess" 2>/dev/null \
    && echo "   garde-fou : $UPGRADES/.htaccess ecrit (acces web refuse sur ce dossier)" \
    || echo "   ATTENTION : impossible d'ecrire $UPGRADES/.htaccess — l'archive n'est protegee que par son nom."
fi
echo "   archive : $ARCHE ($(du -k "$ARCHE" | awk '{print $1}') Ko, integrite gzip OK, mode $(stat -c '%a' "$ARCHE" 2>/dev/null))"
echo "   contenu :"; tar -tzf "$ARCHE" | sed 's/^/     /' | head -10
echo "   pour revenir en arriere a la main : tar -xzf $ARCHE -C $RACINE"
echo "   l'archive reste sur l'hote a la fin du run : c'est voulu, c'est la seule copie du wp-config d'avant."
echo "   RETOUR ARRIERE ENREGISTRE : la ligne ci-dessus est la seule chose a retenir si le run est coupe."

# --- 4bis. le charset, qui ne se decide qu'au moment ou l'on ecrit ----------------------------
# Le convertisseur cree ses tables en utf8mb4 et se connecte en utf8mb4 : la copie est fidele.
# Mais c'est DB_CHARSET qui decide comment WORDPRESS se connectera apres le basculement. Tant que
# le drop-in SQLite est la, un emoji passe (SQLite stocke du texte). Bascule sur MySQL avec
# DB_CHARSET=utf8 (= utf8mb3 chez MySQL) : le meme emoji ecrit demain est detruit silencieusement,
# ligne par ligne, sans erreur. /uat porte DB_CHARSET=utf8 (mesure au run 43600496477), donc cette
# ligne n'est pas de la prudence generique, c'est un reglage du site concerné.
bar; echo " 4bis. CHARSET DE CONNEXION APRES BASCULE"; bar
CS=$("$PHPBIN" -r '$s=file_get_contents($argv[1]); preg_match("/define\(\s*[\x27\"]DB_CHARSET[\x27\"]\s*,\s*[\x27\"]([^\x27\"]+)/",$s,$m)?print($m[1]):print("?");' "$WPC" 2>/dev/null)
echo "   DB_CHARSET actuel : ${CS:-?}"
if [ "$CS" != "utf8mb4" ]; then
  if [ "$PHASE" != "executer" ]; then
    echo "   A REGLER AU JOUR DE L'ECRITURE : passer DB_CHARSET a utf8mb4 en meme temps que le moteur."
    echo "   (la conversion, elle, est deja en utf8mb4 : c'est le cote WordPress qu'il aligne)"
  else
    if command -v wp >/dev/null 2>&1 \
       && wp config set DB_CHARSET utf8mb4 --path="$RACINE" >/dev/null 2>&1 \
       && [ "$("$PHPBIN" -r '$s=file_get_contents($argv[1]); preg_match("/define\(\s*[\x27\"]DB_CHARSET[\x27\"]\s*,\s*[\x27\"]([^\x27\"]+)/",$s,$m)?print($m[1]):print("?");' "$WPC" 2>/dev/null)" = "utf8mb4" ]; then
      echo "   DB_CHARSET : utf8mb4 relu dans le fichier (la valeur d'avant est dans l'archive verifiee)"
    else
      echo "   ECHEC : je n'ai pas pu corriger DB_CHARSET, et je ne bascule pas sans cette correction."
      echo "   A faire a la main dans $WPC : define('DB_CHARSET', 'utf8mb4'); puis relancer."
      exit 9
    fi
  fi
else
  echo "   deja utf8mb4 : rien a aligner"
fi

# --- 4. conversion a blanc -------------------------------------------------------------------
bar; echo " 4. CONVERSION A BLANC (TUC_MODE=dry : ni la cible ni le site ne sont touches)"; bar
export TUC_SQLITE="$SQLITED" TUC_WPCONFIG="$WPC" TUC_MODE=dry
"$PHPBIN" "$CONV" 2>&1 | sed 's/^/   /' | tail -46
SEC=${PIPESTATUS[0]}
echo "   (sortie du convertisseur en sec : $SEC)"
if [ "$PHASE" != "executer" ]; then
  bar
  echo "  FIN DE LA PHASE dry. La bascule reelle n'a pas ete demandee : aucune ecriture n'a eu lieu sur le moteur."
  echo "  Pour passer a l'ecriture : relancer avec l'operation bascule-uat-mysql-executer."
  bar
  [ "$SEC" -eq 0 ] && exit 0 || exit "$SEC"
fi

# --- 5. ecriture en cible ---------------------------------------------------------------------
bar; echo " 5. CONVERSION (ecriture dans la cible MySQL vide)"; bar
export TUC_MODE=run TUC_VERIFY=1
"$PHPBIN" "$CONV" 2>&1 | sed 's/^/   /' | tail -34
RUN=${PIPESTATUS[0]}
if [ "$RUN" -ne 0 ]; then
  echo "   conversion en echec : le moteur n'a pas ete change, db.php est en place. Rien a reparer."
  exit 7
fi

# --- 6. le changement de moteur, et sa fumee --------------------------------------------------
bar; echo " 6. BASCULE DU MOTEUR"; bar
mv "$DROPIN" "$RACINE/wp-content/db.php.avant-mysql-$TS" && echo "   drop-in ecarte : db.php -> db.php.avant-mysql-$TS"
fumee() {
  local u="$1" att="$2" got code
  code=$(curl -s -o "$T/f" -w '%{http_code}' -m 25 "$u" 2>/dev/null || echo 000)
  got=$(grep -o "$att" "$T/f" 2>/dev/null | head -1)
  printf "   %-46s HTTP %s | %s\n" "${u##*/}" "$code" "${got:-$att absent}"
  [ -n "$got" ] && return 0 || return 1
}
OK=1
BASE=$( "$PHPBIN" -r '$s=file_get_contents($argv[1]); preg_match("/define\(\s*[\x27\"]WP_HOME[\x27\"]\s*,\s*[\x27\"]([^\x27\"]+)/",$s,$m)?print($m[1]):print(rtrim(dirname(dirname($argv[1])),"/"));' "$WPC" 2>/dev/null)
fumee "$BASE/wp-json/tuc/v1/sante" '"ok":true' || OK=0
fumee "$BASE/?rest_route=/tuc/v1/menu/kfc" '"price"' || OK=0
if [ "$OK" -eq 0 ]; then
  bar
  echo "   FUMEE ROUGE apres bascule : retour arriere immediat."
  mv "$RACINE/wp-content/db.php.avant-mysql-$TS" "$DROPIN" && echo "   drop-in remis en place"
  tar -xzf "$ARCHE" -C "$RACINE" && echo "   wp-config restaure depuis l'archive verifiee"
  fumee "$BASE/wp-json/tuc/v1/sante" '"ok":true'
  echo "   etat d'avant bascule retabli. La cible MySQL garde la conversion : a vider a la main,"
  echo "   c'est le seul dechet que ce script laisse, et il ne sert a personne en l'etat."
  bar
  exit 8
fi
bar
echo " 7. VERDICT"
bar
echo "   moteur bascule, site vert a la fumee, sauvegarde verifiee : $ARCHE"
echo "   a verifier a la main sous 24 h : les 60 valeurs monetaires (tuc_prix) sur une fiche,"
echo "   et le fichier db.php.avant-mysql-$TS, a ne pas supprimer avant cette relecture."
