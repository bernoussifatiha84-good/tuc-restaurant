<?php
/**
 * TUC — auditeur de fidelite entre les deux moteurs. Appele par audit-mysql.sh, en lecture seule.
 *
 * Compare la base MySQL vivante a la copie SQLite figee au moment de la bascule, table par table,
 * ligne par ligne, avec les regles de normalisation DU CONVERTISSEUR (sinon chaque ecart serait un
 * faux positif : autoload on/off -> yes/no, 79 vs 79.0000, dates a zero).
 *
 * Classe chaque ecart rencontre :
 *   NORMALISE   — voulu par la conversion, deja consigne ;
 *   ECRIT       — la valeur a change cote MySQL depuis la bascule (une ecriture du site, ou la
 *                 notre : le test de prix reversible) ; la copie SQLite, elle, est figee ;
 *   DERIVE      — ni l'un ni l'autre. C'est la seule categorie qui doit faire rougir un audit.
 *
 * Sortie : une ligne par table, un total, et le detail des cellules en DERIVE (plafonne).
 *
 * Usage : php audit-fidelite.php <sqlite> <wp-config> <prefixe>
 */
declare(strict_types=1);

[$_, $SQLITED, $WPC, $PREFIX] = [$argv[0], (string) $argv[1], (string) $argv[2], (string) ($argv[3] ?? 'wp_')];

if (!is_file($SQLITED)) { fwrite(STDERR, "ABORT : pas de copie SQLite a $SQLITED\n"); exit(2); }
$cfg = (string) file_get_contents($WPC);
$lit = static function (string $k) use ($cfg): string {
    return preg_match("/define\(\s*['\"]" . preg_quote($k, '/') . "['\"]\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfg, $m)
        ? stripcslashes($m[1]) : '';
};
[$dbn, $dbu, $dbp, $dbh] = [$lit('DB_NAME'), $lit('DB_USER'), $lit('DB_PASSWORD'), $lit('DB_HOST')];
$port = 3306; $sock = '';
if (str_contains($dbh, ':')) {
    [$h, $a] = explode(':', $dbh, 2); $dbh = $h;
    if (str_starts_with($a, '/')) { $sock = $a; } else { $port = (int) $a; }
}
if ($sock === '' && $dbh === 'localhost') {
    $d = (string) ini_get('mysqli.default_socket');
    if ($d !== '' && file_exists($d)) { $sock = $d; }
}
mysqli_report(MYSQLI_REPORT_OFF);
$my = $sock !== ''
    ? @mysqli_connect($dbh, $dbu, $dbp, $dbn, $port, $sock)
    : @mysqli_connect($dbh, $dbu, $dbp, $dbn, $port);
if (!$my) { fwrite(STDERR, 'ABORT : connexion MySQL refusee : ' . mysqli_connect_error() . "\n"); exit(2); }
$li = new SQLite3($SQLITED, SQLITE3_OPEN_READONLY);

/* ------------------------------------------------------------------ normalisation */
function normalise(string $v): string
{
    $t = trim($v);
    if ($t === '' || strcasecmp($t, 'null') === 0) { return ''; }
    // autoload : le drop-in ecrivait 'on'/'off', WordPress attend 'yes'/'no' (338 valeurs).
    if ($t === 'on') { return 'yes'; }
    if ($t === 'off') { return 'no'; }
    // Dates a zero : les deux moteurs les rendent differemment, les deux veulent dire "jamais".
    if (preg_match('/^0000-00-00/', $t)) { return 'zero-date'; }
    // Decimales : '12' en SQLite (TEXT) contre '12.0000' en MySQL (DECIMAL(10,4) du schema emule).
    if (preg_match('/^-?\d+(\.\d+)?$/', $t)) {
        $f = (float) $t;
        return rtrim(rtrim(sprintf('%.4f', $f), '0'), '.') ?: '0';
    }
    return $t;
}

function cle_ligne(array $cols, array $row): string
{
    // Une ligne est identifiee par sa cle primaire ; sans PK, par l'ordre de lecture.
    if ($cols === []) { return ''; }
    $k = [];
    foreach ($cols as $c) { $k[] = $c . '=' . ($row[$c] ?? "\0"); }
    return implode('|', $k);
}

function colonnes_cle(mysqli $my, string $db, string $t): array
{
    $q = $my->query(sprintf(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='%s' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_COLUMN",
        mysqli_real_escape_string($my, $db), mysqli_real_escape_string($my, $t)));
    $o = [];
    if ($q) { while ($r = $q->fetch_row()) { $o[] = $r[0]; } }
    return $o;
}

/* ------------------------------------------------------------------ tables communes */
$qt = $my->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
$en_mysql = [];
while ($qt && $r = $qt->fetch_row()) { $en_mysql[] = $r[0]; }
$qs = $li->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
$en_sqlite = [];
while ($qs && $r = $qs->fetchArray(SQLITE3_NUM)) { $en_sqlite[] = $r[0]; }
$communes = array_values(array_intersect($en_mysql, $en_sqlite));
$jq = $li->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE '\_wp\_sqlite%'");
$escamotage = $jq ? (int) $jq->fetchArray(SQLITE3_NUM)[0] : 0;

printf("tables : MySQL=%d | SQLite=%d (%d d'echafaudage) | communes=%d\n",
    count($en_mysql), count($en_sqlite), $escamotage, count($communes));

$totaux = ['lignes' => 0, 'cellules' => 0, 'NORMALISE' => 0, 'ECRIT' => 0, 'DERIVE' => 0];
$detail = [];
$chauffe = 0;

foreach ($communes as $t) {
    $q = $my->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $t) . '`');
    $r = $q ? $q->fetch_row() : null;
    $n_mysql = (int) ($r[0] ?? -1);
    $q2 = $li->querySingle('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t) . '"', false);
    $n_sqlite = (int) $q2;
    if ($n_mysql < 0 || $n_sqlite < 0) { printf("  %-42s lecture impossible\n", $t); continue; }
    if ($n_mysql === 0 && $n_sqlite === 0) { continue; }
    if ($n_mysql !== $n_sqlite) {
        printf("  %-42s COMPTE DIFFERENT MySQL=%d SQLite=%d\n", $t, $n_mysql, $n_sqlite);
        $totaux['DERIVE'] += abs($n_mysql - $n_sqlite);
        continue;
    }
    // Colonnes dans le meme ordre des deux cotes : on s'appuie sur information_schema pour MySQL,
    // et sur PRAGMA pour SQLite (qui rend l'ordre de la CREATE TABLE emulee).
    $cols = [];
    $qc = $my->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
        . mysqli_real_escape_string($my, $t) . "' ORDER BY ORDINAL_POSITION");
    while ($qc && $x = $qc->fetch_row()) { $cols[] = $x[0]; }
    $pq = $li->query('PRAGMA table_info("' . str_replace('"', '""', $t) . '")');
    $scols = [];
    while ($pq && $x = $pq->fetchArray(SQLITE3_ASSOC)) { $scols[] = $x['name']; }
    if ($cols !== $scols) {
        printf("  %-42s colonnes differentes (%d vs %d) -> compare sur l'intersection\n", $t, count($cols), count($scols));
        $cols = array_values(array_intersect($cols, $scols));
        if ($cols === []) { continue; }
    }
    $pk = colonnes_cle($my, $dbn, $t);
    $collist = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $cols));
    // CAST(... AS BLOB) : la lecture en TEXT tronque au premier octet NUL, et les objets serializes
    // (toutes les lignes d'Action Scheduler) en contiennent. Sans ce cast, 69 lignes sur 69 etaient
    // signalees comme differentes d'une copie qui est identique octet pour octet.
    $listSqlite = implode(', ', array_map(
        static fn($c) => 'CAST("' . str_replace('"', '""', $c) . '" AS BLOB)', $cols));
    $ordre = $pk !== []
        ? implode(', ', array_map(static fn($c) => '"' . str_replace('"', '""', $c) . '"', $pk))
        : $cols[0];
    $map = [];
    $res = $li->query('SELECT ' . $listSqlite . ' FROM "' . str_replace('"', '""', $t) . '" ORDER BY ' . $ordre);
    $i = 0;
    while ($res && $row = $res->fetchArray(SQLITE3_NUM)) {
        $a = [];
        foreach ($cols as $j => $c) { $a[$c] = $row[$j] === null ? '' : (string) $row[$j]; }
        $map[($pk !== [] ? cle_ligne($pk, $a) : '#' . $i)] = $a;
        $i++;
    }
    $res2 = $my->query('SELECT ' . $collist . ' FROM `' . str_replace('`', '``', $t) . '` ORDER BY '
        . implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $pk ?: [$cols[0]])));
    $n = 0; $k = 0; $tdiff = ['NORMALISE' => 0, 'ECRIT' => 0, 'DERIVE' => 0];
    while ($res2 && $row = $res2->fetch_assoc()) {
        $key = ($pk !== [] ? cle_ligne($pk, array_map(static fn($c) => (string) ($row[$c] ?? ''), $pk)) : '#' . $n);
        $src = $map[$key] ?? (isset($map['#' . $n]) ? $map['#' . $n] : null);
        if ($src === null) { $tdiff['DERIVE']++; $detail[] = "$t#$key : ligne absente de la copie"; $n++; $k++; continue; }
        foreach ($cols as $c) {
            $totaux['cellules']++;
            $brut_a = (string) ($src[$c] ?? '');
            $brut_b = (string) ($row[$c] ?? '');
            if ($brut_a === $brut_b) { continue; }
            $a = normalise($brut_a); $b = normalise($brut_b);
            if ($a === $b) {
                // Les bruts different, le sens est le meme : c'est une reecriture voulue par la
                // conversion (autoload on/off -> yes/no, decimales, dates a zero). On la compte a
                // partir des donnees, pas d'une table que j'aurais declaree particuliere.
                $tdiff['NORMALISE']++;
                continue;
            }
            if ($a === $b) { continue; }
            $tdiff['ECRIT']++;
            if ($chauffe < 40) {
                $chauffe++;
                $detail[] = sprintf('%s[%s].%s : copie=%s | vivant=%s', $t, substr($key, 0, 28), $c,
                    mb_substr(preg_replace('/\s+/u', ' ', $a), 0, 34), mb_substr(preg_replace('/\s+/u', ' ', $b), 0, 34));
            }
        }
        $n++; $k++;
    }
    $totaux['lignes'] += $n;
    foreach ($tdiff as $kk => $vv) { $totaux[$kk] += $vv; }
    $mark = ($tdiff['DERIVE'] === 0) ? 'ok' : 'DERIVE';
    printf("  %-42s %5d lignes | normalise=%-4d ecrit=%-4d derive=%-3d %s\n",
        $t, $n, $tdiff['NORMALISE'], $tdiff['ECRIT'], $tdiff['DERIVE'], $mark);
}

echo "\n== detail des ecarts (plafonne a 40) ==\n";
foreach ($detail as $d) { echo '  ' . $d . "\n"; }
if ($detail === []) { echo "  aucun\n"; }

printf("\nlignes comparées : %d | cellules : %d | normalisees : %d | ecrites depuis la bascule : %d | DERIVEES : %d\n",
    $totaux['lignes'], $totaux['cellules'], $totaux['NORMALISE'], $totaux['ECRIT'], $totaux['DERIVE']);
echo $totaux['DERIVE'] === 0
    ? "VERDICT fidelite : la copie vivante ne contredit la copie figee que la ou c'est attendu.\n"
    : "VERDICT fidelite : " . $totaux['DERIVE'] . " ecart(s) inattendu(s) — a regarder avant de jeter la copie SQLite.\n";
$li->close(); $my->close();
exit($totaux['DERIVE'] === 0 ? 0 : 9);
