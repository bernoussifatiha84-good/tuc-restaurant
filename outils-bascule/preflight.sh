#!/usr/bin/env bash
# Preflight de la bascule SQLite -> MySQL.
#
# LECTURE SEULE : aucune ecriture, aucun mv, aucun arret de site, aucune requete de
# modification. Ce script doit rendre decidable, avec des chiffres :
#   1. le sql_mode que le core applique vraiment (les DEFAULT '0000-00-00 00:00:00' en dependent)
#   2. l'etat reel de la cible MySQL (vide ? quelle version ? quelles collations ?)
#   3. le dialecte SQL des extensions installees (ce que je ne peux pas auditer depuis mon bac)
#   4. la taille de ce qu'on va copier, et l'etat du WAL
set -u

ROOT="${1:?racine du site en argument}"
cd "$ROOT" || { echo "racine introuvable : $ROOT"; exit 1; }

S='=============================================================='
T="$(mktemp -d 2>/dev/null || echo /tmp/tuc-preflight-$$)"
mkdir -p "$T"
trap 'rm -rf "$T"' EXIT

echo "$S ; 1. ENVIRONNEMENT"
hostname 2>/dev/null || echo "  hostname indisponible"
php -v | head -1
echo "  extensions php : $(php -m | tr '\n' ' ' | grep -o -e pdo_mysql -e mysqli -e pdo_sqlite | sort -u | tr '\n' ' ')"
echo "  disque : $(df -h . | awk 'NR==2{print $4" libres sur "$2}')"
if command -v wp >/dev/null 2>&1; then
  echo "  wp-cli : $(wp --version --allow-root 2>/dev/null || echo present mais non executable)"
else
  echo "  wp-cli : ABSENT du PATH"
fi
wp eval 'echo "  is_ssl() = " . var_export(is_ssl(), true) . " | ABSPATH = " . ABSPATH;' --allow-root 2>&1 | tail -1

# ------------------------------------------------------------------ le fichier d'inventaire SQLite
cat > "$T/inventaire.php" <<'PHPEOF'
<?php
$f = $argv[1] ?? '';
if ($f === '' || !is_file($f)) {
    echo "  aucun fichier .sqlite designe : rien a mesurer\n";
    exit(0);
}
$p = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
printf(
    "  journal_mode=%s | page_size=%s | integrity=%s\n",
    $p->query('PRAGMA journal_mode')->fetchColumn(),
    $p->query('PRAGMA page_size')->fetchColumn(),
    $p->query('PRAGMA integrity_check')->fetchColumn()
);
$tables = (int) $p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
$scaffold = (int) $p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE '_wp_sqlite%'")->fetchColumn();
$lignes = 0;
foreach ($p->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $lignes += (int) $p->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t) . '"')->fetchColumn();
}
printf("  tables : %d (dont %d d'echafaudage du drop-in, exclues de la bascule) | lignes : %d\n", $tables, $scaffold, $lignes);
printf("  WAL non rejoue : %d o | shm : %s\n",
    is_file($f . '-wal') ? filesize($f . '-wal') : 0,
    is_file($f . '-shm') ? 'present' : 'absent');
foreach (['wp_options' => 'COUNT(*)', 'wp_posts' => 'MAX(ID)', 'wp_postmeta' => 'COUNT(*)'] as $t => $agg) {
    try {
        printf("  %-12s %-8s = %s\n", $t, $agg, (string) $p->query("SELECT $agg FROM \"$t\"")->fetchColumn());
    } catch (Throwable $e) {
        printf("  %-12s : erreur %s\n", $t, $e->getMessage());
    }
}
printf("  autoload en lecture : %s\n", json_encode($p->query('SELECT autoload, COUNT(*) n FROM wp_options GROUP BY autoload')->fetchAll(PDO::FETCH_KEY_PAIR)));
PHPEOF

# ------------------------------------------------------------------ la cible MySQL
cat > "$T/cible.php" <<'PHPEOF'
<?php
// Lit wp-config.php sans jamais l'afficher : ce qu'on imprime ici, ce sont des etats
// (version, sql_mode, nombre de tables), pas des identifiants.
$cfg = file_get_contents('wp-config.php');
$li = static function (string $c) use ($cfg): ?string {
    $pat = '/define\s*\(\s*[\'"]' . preg_quote($c, '/') . '[\'"]\s*,\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*?)\1/';
    return preg_match($pat, $cfg, $m) ? stripcslashes($m[2]) : null;
};
$name = $li('DB_NAME');
$user = (string) $li('DB_USER');
$pass = (string) $li('DB_PASSWORD');
$host = (string) $li('DB_HOST');
$prefix = null;
if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]*)[\'"]/', $cfg, $m)) {
    $prefix = $m[1];
}
if (null === $name || '' === $name) {
    echo "  DB_NAME introuvable dans wp-config.php : cible non evaluee\n";
    exit(0);
}
$sock = null;
$port = 3306;
if (strpos($host, ':') !== false) {
    [$h, $ap] = explode(':', $host, 2);
    if (is_numeric($ap)) {
        $port = (int) $ap;
        $host = $h;
    } elseif (strncmp($ap, '/', 1) === 0) {
        $sock = $ap;
        $host = $h !== '' ? $h : 'localhost';
    }
}
// mysqli::__construct(hostname, username, password, database, port, socket). Le socket n'est
// pas le quatrieme argument : le passer comme hote rend une erreur reseau qu'on lirait comme un
// probleme de droits. Formes explicites, donc.
mysqli_report(MYSQLI_REPORT_OFF);
$m = $sock
    ? @new mysqli(null, $user, $pass, $name, 0, $sock)
    : @new mysqli($host !== '' ? $host : 'localhost', $user, $pass, $name, $port);
if ($m->connect_errno) {
    echo '  connexion refusee : #' . $m->connect_errno . ' ' . $m->connect_error . "\n";
    echo "  (ce n'est pas un jugement sur la bascule : c'est la connexion que refuserait WordPress)\n";
    exit(0);
}
$r = $m->query('SELECT VERSION(), @@GLOBAL.sql_mode, @@GLOBAL.max_allowed_packet, @@SESSION.sql_mode, DATABASE()')->fetch_row();
printf("  version        : %s\n", $r[0]);
printf("  sql_mode global: %s\n", ($r[1] === '' || null === $r[1]) ? '(vide)' : $r[1]);
printf("  sql_mode session: %s\n", ($r[3] === '' || null === $r[3]) ? '(vide)' : $r[3]);
printf("  max_allowed_packet : %s o\n", number_format((int) $r[2], 0, ',', ' '));
printf("  base `%s` (prefixe %s) : %d table(s)\n", $r[4], (string) $prefix,
    (int) $m->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetch_row()[0]);
$v = $m->query(
    "SELECT COLLATION_NAME FROM information_schema.COLLATIONS
     WHERE CHARACTER_SET_NAME = 'utf8mb4'
       AND COLLATION_NAME IN ('utf8mb4_0900_ai_ci','utf8mb4_unicode_520_ci','utf8mb4_unicode_ci','utf8mb4_general_ci')
     ORDER BY COLLATION_NAME"
);
$co = [];
while ($x = $v->fetch_row()) {
    $co[] = $x[0];
}
echo '  collations     : ' . ($co === [] ? 'aucune de celles recherchees' : implode(', ', $co)) . "\n";
printf(
    "  ce qui decide du sort des dates nulles : STRICT global %s | STRICT session %s | NO_ZERO_DATE global %s\n",
    stripos((string) $r[1], 'STRICT') !== false ? 'OUI' : 'non',
    stripos((string) $r[3], 'STRICT') !== false ? 'OUI' : 'non',
    stripos((string) $r[1], 'NO_ZERO_DATE') !== false ? 'OUI' : 'non'
);
$utf = $m->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()")->fetch_row();
printf("  defauts de la base : charset %s | collation %s\n", (string) $utf[0], (string) $utf[1]);
PHPEOF

echo "$S ; 2. BASE SQLITE COURANTE"
ls -l wp-content/db.php 2>&1 | sed 's/^/  /'
ls -l wp-content/database/ 2>&1 | tail -6 | sed 's/^/  /'
SQL="$(ls -1 wp-content/database/*.sqlite 2>/dev/null | head -1)"
echo "  fichier actif : ${SQL:-AUCUN}"
php "$T/inventaire.php" "$SQL" 2>&1 | sed 's/^/  /'

echo "$S ; 3. SQL_MODE IMPOSE PAR LE CORE A SA PROPRE CONNEXION"
if [ -f wp-includes/class-wpdb.php ]; then
  grep -n "sql_mode" wp-includes/class-wpdb.php | head -8 | sed 's/^/  /'
  echo "  (si le core ne force rien, c'est le sql_mode global ci-dessous qui s'applique)"
else
  echo "  wp-includes/class-wpdb.php introuvable ici — a chercher dans le vrai chemin du core"
fi

echo "$S ; 4. DIALECTE SQL DES EXTENSIONS (ce que le bac local ne peut pas voir)"
for d in wp-content/plugins wp-content/mu-plugins wp-content/themes; do
  if [ -d "$d" ]; then
    echo "  --- $d"
    grep -rlE "INSERT OR (IGNORE|REPLACE|ABORT)|[Pp]RAGMA |AUTOINCREMENT|strftime\(" "$d" --include='*.php' 2>/dev/null \
      | sed "s|$d/||" | cut -d/ -f1 | sort | uniq -c | sort -rn | head -8 | sed 's/^/     /'
    echo "     (nombre de fichiers touches par extension ; vide = rien de specifique a SQLite)"
  fi
done

echo "$S ; 5. CIBLE MYSQL"
php "$T/cible.php" 2>&1 | sed 's/^/  /'

echo "$S ; 6. ETAT DU SITE, POUR LES CONTROLES APRES BASCULE"
wp plugin list --status=active --fields=name,version,status --allow-root 2>&1 | head -10 | sed 's/^/  /'
wp theme list --fields=name,status,version --allow-root 2>&1 | head -6 | sed 's/^/  /'
echo "  menu actif : $(wp eval 'echo (string) get_nav_menu_locations() ? json_encode(array_keys(get_nav_menu_locations())) : "aucun";' --allow-root 2>&1 | tail -1)"
echo "  mode maintenance : $(wp maintenance-mode status --allow-root 2>&1 | tail -1)"
echo "  plugin de cache present : $(ls wp-content/plugins 2>/dev/null | grep -ic litespeed) (litespeed)"
echo "  drop-in presents : $(ls wp-content/ 2>/dev/null | grep -E '^(db\.php|object-cache\.php|advanced-cache\.php)$' | tr '\n' ' ')"
echo "$S ; FIN DU PREFLIGHT (rien n'a ete modifie)"

echo "$S ; 7. CE QUI BLOQUE, MESURE"
echo "  --- wp-content/db-path.php : le pointeur pose par le drop-in"
if [ -f wp-content/db-path.php ]; then
  sed -n '1,24p' wp-content/db-path.php | sed -e 's/^/    /' -e 's/[A-Za-z0-9_]*\.sqlite/<fichier>/g'
fi

echo "  --- ou sont reellement les bases SQLite"
find . -maxdepth 4 -name '*.sqlite*' -printf '%s o  %p\n' 2>/dev/null | sort -rn | head -6 | sed 's/^/    /'
echo "    (total fichiers : $(find . -maxdepth 4 -name '*.sqlite*' 2>/dev/null | wc -l))"

echo "  --- wp-config : les constantes DB, longueur seulement, jamais la valeur"
if command -v wp >/dev/null 2>&1; then
  wp config list --format=json --allow-root 2>/dev/null | php -r '
    $j = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($j)) { echo "    (wp config list na rien rendu)\n"; exit(0); }
    foreach (["DB_NAME","DB_USER","DB_PASSWORD","DB_HOST","DB_CHARSET"] as $k) {
      if (!array_key_exists($k, $j)) { printf("    %-12s : absente\n", $k); continue; }
      $v = $j[$k]["value"] ?? "";
      $est_expr = is_string($v) && (stripos($v, "getenv") !== false || stripos($v, "$_ENV") !== false || $v === "x");
      printf("    %-12s : %s\n", $k, $est_expr
        ? "expression (getenv/placeholder) — value = " . substr((string) $v, 0, 46)
        : strlen((string) $v) . " caractere(s)");
    }'
fi
echo "  --- le PHP de CLI voit-il les variables d'environnement de l'hebergeur ?"
php -r '
$n = 0;
foreach ($_ENV as $k => $v) { if (preg_match("/DB|MYSQL|WORDPRESS/i", (string) $k)) { $n++; echo "    $_ENV key : " . preg_replace("/[A-Za-z0-9]+/", "<v>", (string) $k) . "\n"; } }
printf("    cles ENV candidates en CLI : %d | getenv(\"DB_USER\") : %s | getenv(\"MYSQL_USER\") : %s\n",
  $n, getenv("DB_USER") === false ? "absente" : "PRESENTE", getenv("MYSQL_USER") === false ? "absente" : "PRESENTE");
printf("    count(printenv) = %d\n", (int) trim((string) shell_exec("printenv | wc -l")));'
echo "  --- ce que wp-cli lui-meme obtient comme identifiants (test decisif)"
wp db check --allow-root 2>&1 | head -3 | sed 's/^/    /'
wp db size --allow-root 2>&1 | head -2 | sed 's/^/    /'

echo "  --- le sql_mode que le core retire (la reponse sur les dates nulles)"
grep -n "incompatible_modes" -A 12 wp-includes/class-wpdb.php | sed -n '1,22p' | sed 's/^/    /'

echo "  --- ce qui a sonne chez UpdraftPlus (seule extension tierce signalee)"
grep -rnE "INSERT OR (IGNORE|REPLACE|ABORT)|[Pp]RAGMA |AUTOINCREMENT|strftime\(" wp-content/plugins/updraftplus --include='*.php' 2>/dev/null \
  | head -3 | cut -c1-150 | sed 's/^/    /'

echo "  --- themes et extensions desinstalles (le menage qui reduit la surface de bascule)"
wp theme list --status=inactive --fields=name,version --allow-root 2>&1 | head -6 | sed 's/^/    /'
echo "    tables orphanes candidate : $(php -r '
$cfg = @file_get_contents("wp-config.php");
echo "a compter apres bascule, la cible etant vide aujourd\x27hui";')"
echo "$S ; FIN DU COMPLEMENT (toujours lecture seule)"

echo "$S ; 8. DERNIERES INCONNUES (pointeur, credentiels, etat courant de la base)"
echo "  --- wp-content/db-path.php, lu en entier (200 o : un chemin, pas un secret)"
if [ -r wp-content/db-path.php ]; then
  cat wp-content/db-path.php | sed 's/^/    /'
else
  echo "    illisible : $(ls -l wp-content/db-path.php 2>&1 | tail -1)"
fi

cat > "$T/credentiels.php" <<'PHPEOF'
<?php
$t = @file_get_contents('wp-config.php');
if ($t === false) { echo "    wp-config.php illisible\n"; exit(0); }
$clés = array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE');
foreach ($clés as $k) {
  if (!preg_match('/define\(\s*[\'"]' . $k . '[\'"]\s*,\s*(.+?)\s*\)\s*;/s', $t, $m)) {
    printf("    %-12s : ABSENT du fichier\n", $k);
    continue;
  }
  $v = trim($m[1]);
  if (preg_match('/^getenv\s*\(/i', $v) || strpos($v, '$_ENV') !== false || strpos($v, '$_SERVER') !== false) {
    printf("    %-12s : expression d'environnement, %d octets — le PHP de CLI ne la resout pas\n", $k, strlen($v));
    continue;
  }
  if (preg_match('/^[\'"](.*)[\'"]$/s', $v, $q)) {
    $court = strlen($q[1]) <= 3 ? ' (valeur litterale : "' . $q[1] . '" — ce n est pas un identifiant exploitable)' : '';
    printf("    %-12s : litteral de %d caractere(s)%s\n", $k, strlen($q[1]), $court);
    continue;
  }
  printf("    %-12s : autre forme, %d octets\n", $k, strlen($v));
}
PHPEOF
echo "  --- le bloc DB de wp-config.php : forme et longueur, jamais la valeur"
php "$T/credentiels.php"

cat > "$T/base-courante.php" <<'PHPEOF'
<?php
$pointeur = @file_get_contents('wp-content/db-path.php');
$chemin = 'wp-content/database/.ht.04e43856d7780fa60a6240de78c3927a/.ht.sqlite';
if ($pointeur !== false && preg_match('/[\'"]([^\'"]*\.sqlite)[\'"]/', $pointeur, $m)) {
  $chemin = $m[1];
}
echo '    chemin retenu : ' . $chemin . "\n";
if (!is_file($chemin)) { echo "    le pointeur ne designe pas un fichier existant\n"; exit(0); }
printf("    taille : %d o | mtime : %s\n", filesize($chemin), date('Y-m-d H:i:s', filemtime($chemin)));
try {
  $p = new PDO('sqlite:' . $chemin);
} catch (Throwable $e) {
  echo '    ouverture : ' . $e->getMessage() . "\n";
  exit(0);
}
$nt = (int) $p->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
$nl = 0; $detail = array();
$liste = $p->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
foreach ($liste as $r) {
  $nom = str_replace("'", "''", $r['name']);
  $c = (int) $p->query('SELECT count(*) FROM "' . $nom . '"')->fetchColumn();
  $nl += $c;
  $detail[$r['name']] = $c;
}
printf("    tables : %d | lignes : %d\n", $nt, $nl);
arsort($detail);
$i = 0;
foreach ($detail as $t => $c) {
  if ($i++ >= 6) { break; }
  printf("      %-28s %6d\n", $t, $c);
}
$ic = $p->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
echo '    integrity_check : ' . (implode(',', (array) $ic) ?: '(aucun resultat)') . "\n";
echo '    journal_mode : ' . $p->query('PRAGMA journal_mode')->fetchColumn();
echo ' | -wal : ' . (file_exists($chemin . '-wal') ? filesize($chemin . '-wal') . ' o' : 'absent') . "\n";
$opt = $p->query("SELECT count(*) FROM wp_options")->fetchColumn();
$po = $p->query("SELECT count(*) FROM wp_posts")->fetchColumn();
$pm = $p->query("SELECT count(*) FROM wp_postmeta")->fetchColumn();
printf("    wp_options : %d | wp_posts : %d | wp_postmeta : %d\n", $opt, $po, $pm);
$px = $p->query("SELECT count(*) FROM wp_postmeta WHERE meta_key='_tuc_prix'")->fetchColumn();
echo '    lignes _tuc_prix : ' . $px . "\n";
PHPEOF
echo "  --- la base SQLite courante, comptee en direct (pdo_sqlite est charge)"
php "$T/base-courante.php"
echo "  --- referentiel : la copie du 13/11 comparee a blanc par le convertisseur faisait"
echo "      54 tables / 1 410 lignes / 613 options / 68 posts / 369 postmeta / 38 _tuc_prix."
echo "      Si les chiffres au-dessus different, il faut re-exporter avant toute bascule."
echo "$S ; FIN PREFLIGHT v3"

echo "$S ; 9. LITESPEED CACHE : est-il reellement actif, et que renvoie la page de menu"
cat > "$T/litespeed.php" <<'PHPEOF'
<?php
$sql = 'wp-content/database/.ht.04e43856d7780fa60a6240de78c3927a/.ht.sqlite';
try {
  $p = new PDO('sqlite:' . $sql . '?mode=ro');
} catch (Throwable $e) {
  try { $p = new PDO('sqlite:' . $sql); } catch (Throwable $e2) { echo "    base inaccessible\n"; exit(0); }
}
$q = $p->query("SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'litespeed%' OR option_name LIKE 'lite%' ORDER BY option_name");
$n = 0;
foreach ($q as $r) {
  $n++;
  if ($n > 40) { break; }
  $cle = $r['option_name'];
  $val = (string) $r['option_value'];
  if (preg_match('/key|secret|token|pass/i', $cle)) { printf("    %-34s : <masque, %d octets>\n", $cle, strlen($val)); continue; }
  if (strlen($val) > 240) { $val = substr($val, 0, 240) . '…'; }
  printf("    %-34s : %s\n", $cle, str_replace("\n", ' ', $val));
}
echo "    (options litespeed total : " . (int) $p->query("SELECT count(*) FROM wp_options WHERE option_name LIKE 'litespeed%'")->fetchColumn() . ")\n";
echo "    --- prix courants des plats (tuc_prix), 6 premiers\n";
$d = $p->query("SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key = 'tuc_prix' ORDER BY post_id LIMIT 6");
foreach ($d as $r) printf("      poste %-6s prix %s\n", $r['post_id'], $r['meta_value']);
echo "      total : " . (int) $p->query("SELECT count(*) FROM wp_postmeta WHERE meta_key = 'tuc_prix'")->fetchColumn() . " lignes, "
   . (int) $p->query("SELECT count(DISTINCT meta_value) FROM wp_postmeta WHERE meta_key = 'tuc_prix'")->fetchColumn() . " valeurs distinctes\n";
PHPEOF
php "$T/litespeed.php"

if command -v wp >/dev/null 2>&1; then
  U=$(wp option get siteurl --allow-root 2>/dev/null | tr -d '\r\n')
else
  U=""
fi
[ -z "$U" ] && U="https://chocolate-gazelle-457691.hostingersite.com/uat"
echo "  --- deux requetes sur la page du menu, en-tetes de cache seulement"
for tete in P1 P2; do
  echo "    $tete :"
  curl -s -o /dev/null -D - "$U/menu/kfc/" 2>/dev/null \
    | grep -aiE '^(HTTP/|x-litespeed|cache-control|age|x-turbo|content-type|last-modified|vary)' \
    | sed 's/\r$//' | sed 's/^/      /'
done
echo "  --- idem sur la racine (temoin : le cache fonctionne-t-il ailleurs ?)"
curl -s -o /dev/null -D - "$U/" 2>/dev/null | grep -aiE '^(HTTP/|x-litespeed|cache-control|age)' | sed 's/\r$//' | sed 's/^/    /'
echo "$S ; FIN PREFLIGHT v4"
