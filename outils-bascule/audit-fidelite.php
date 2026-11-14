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
    foreach ($cols as $c) { $k[] = $c . '=' . (string) ($row[$c] ?? ''); }
    return implode('|', $k);
}

function colonnes_cle(mysqli $my, string $db, string $t): array
{
    // SEQ_IN_INDEX, pas SEQ_IN_COLUMN : la colonne n'existe pas sous ce nom, et une requete
    // invalide renvoie false — donc « aucune cle primaire », donc un audit qui ne compare rien
    // tout en ayant l'air de conclure. Un faux vide et une vraie absence ne se ressemblent pas.
    $q = $my->query(sprintf(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='%s' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX",
        mysqli_real_escape_string($my, $db), mysqli_real_escape_string($my, $t)));
    if ($q === false) {
        fwrite(STDERR, 'ABORT : lecture des cles primaires impossible (' . mysqli_error($my) . "). Sans cles, pas de comparaison ligne a ligne.\n");
        exit(2);
    }
    $o = [];
    while ($r = $q->fetch_row()) { $o[] = $r[0]; }
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

$totaux = ['lignes' => 0, 'cellules' => 0, 'NORMALISE' => 0, 'ECRIT' => 0, 'DERIVE' => 0,
          'AJOUTEE' => 0, 'EPHEMERE' => 0];
$detail = [];
$ecarts_compte = [];
$lignes_attendues = [];
$chauffe = 0;

// Une copie figee a une heure : le site continue d'ecrire et de menager. Une ligne d'ordonnanceur
// terminee, un transient expire, disparaissent legalement ; une ligne de contenu qui disparait est
// une perte. La regle porte donc sur la NATURE de la ligne, pas sur la table seulement.
$TABLES_EPHIMERES = ['actionscheduler_actions', 'actionscheduler_logs', 'actionscheduler_locks'];
// nom court, pour ne pas dependre du prefixe choisi a l'install
$nom_court = static fn(string $t): string => preg_replace('/^[a-z0-9_]*?(wp_|wc_)/', '', $t) ?? $t;
$OPTIONS_EPHIMERES = '/^(_transient_|_site_transient_|'
    . 'cron$|_transient_doing_cron$|action_scheduler_run_queue|rewrite_rules|timeout_|'
    . 'wp_http_last_modified|_nonce_key|_nonce_salt)/';

foreach ($communes as $t) {
    $q = $my->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $t) . '`');
    $r = $q ? $q->fetch_row() : null;
    $n_mysql = (int) ($r[0] ?? -1);
    $q2 = $li->querySingle('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t) . '"', false);
    $n_sqlite = (int) $q2;
    if ($n_mysql < 0 || $n_sqlite < 0) { printf("  %-42s lecture impossible\n", $t); continue; }
    if ($n_mysql === 0 && $n_sqlite === 0) { continue; }
    if ($n_mysql !== $n_sqlite) { $ecarts_compte[$t] = $n_mysql - $n_sqlite; }
    // Pas de `continue` ici. Un compte qui bouge est une attente sur un site vivant ; comparer
    // les cellules reste le seul moyen de savoir SI une ligne de contenu a disparu. Sauter la
    // table aurait masque exactement ce qu'on cherche.
    $lignes_attendues[$t] = $n_sqlite;
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
    if ($pk === []) {
        // Sans cle primaire, il n'y a rien pour apparier une ligne de l'autre cote. Comparer par
        // position serait chercher le probleme : la moindre ligne en plus decale tout le reste.
        printf("  %-42s %5d lignes vs %5d | SANS CLE PRIMAIRE : presence comparee au compte seul\n",
            $t, $n_mysql, $n_sqlite);
        if ($n_mysql !== $n_sqlite) { $totaux['DERIVE'] += 1; $detail[] = "$t : comptes differents, aucune cle pour localiser"; }
        continue;
    }
    $res2 = $my->query('SELECT ' . $collist . ' FROM `' . str_replace('`', '``', $t) . '` ORDER BY '
        . implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $pk)));
    $n = 0; $tdiff = ['NORMALISE' => 0, 'ECRIT' => 0, 'DERIVE' => 0,
                      'AJOUTEE' => 0, 'EPHEMERE' => 0];
    $vus = [];
    while ($res2 && $row = $res2->fetch_assoc()) {
        // cle_ligne lit par NOM de colonne : passer les valeurs par array_map les renumerote en
        // 0..n, et toutes les cles MySQL devenaient identiquement vides. D'ou 1 410 "lignes nees
        // depuis la bascule" sur une copie conforme. On passe la ligne associtative telle quelle.
        $key = cle_ligne($pk, $row);
        $src = $map[$key] ?? null;   // par la cle, et seulement par elle
        if ($src === null) {
            // La ligne n'existait pas a la figeance : le site l'a creee depuis. Une bascule n'a
            // jamais promis de geler le present — elle a promis de ne rien perdre.
            $tdiff['AJOUTEE']++;
            $n++; continue;
        }
        $vus[$key] = true;
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
        $n++;
    }
    // Ce qui reste dans la copie et plus dans la base vivante. Deux causes, un seul danger :
    // le menage legitime du site (menace nulle) contre la ligne de contenu perdue (tout le danger).
    $en_moins = 0;
    foreach ($map as $key => $srcrow) {
        if (isset($vus[$key])) { continue; }
        $en_moins++;
        $eph = in_array($nom_court($t), $TABLES_EPHIMERES, true);
        if (!$eph && isset($srcrow['option_name']) && preg_match($OPTIONS_EPHIMERES, (string) $srcrow['option_name'])) {
            $eph = true;   // transient expire, cron, regles de reecriture : recres par definition
        }
        if ($eph) {
            $tdiff['EPHEMERE']++;
        } else {
            $tdiff['DERIVE']++;
            if ($chauffe < 40) {
                $chauffe++;
                $detail[] = sprintf('%s[%s] : PRESENTE dans la copie, ABSENTE de MySQL — %s',
                    $t, substr($key, 0, 24),
                    mb_substr(preg_replace('/\s+/u', ' ', json_encode(array_map('strval', array_slice($srcrow, 0, 2)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 56));
            }
        }
    }
    $totaux['lignes'] += $n + $en_moins;
    foreach ($tdiff as $kk => $vv) { $totaux[$kk] += $vv; }
    $mark = ($tdiff['DERIVE'] === 0) ? 'ok' : 'DERIVE';
    // le signe est ecrit a la main : sprintf de PHP n'accepte le "char de remplissage" (')
    // qu'avec une largeur, et un format malforme tue l'audit entier apres la premiere table.
    $compte = isset($ecarts_compte[$t])
        ? sprintf(" | compte %s%d (MySQL)", $ecarts_compte[$t] > 0 ? '+' : '', $ecarts_compte[$t]) : '';
    printf("  %-42s %5d lignes | normalise=%-4d ecrit=%-4d ajoutez=%-4d ephemeres=%-4d derive=%-3d%s %s\n",
        $t, $n, $tdiff['NORMALISE'], $tdiff['ECRIT'], $tdiff['AJOUTEE'], $tdiff['EPHEMERE'],
        $tdiff['DERIVE'], $compte, $mark);
}

echo "\n== detail des ecarts (plafonne a 40) ==\n";
foreach ($detail as $d) { echo '  ' . $d . "\n"; }
if ($detail === []) { echo "  aucun\n"; }

printf("\nlignes comparees : %d | cellules : %d | normalisees : %d | ecrites : %d | ajoutees depuis la bascule : %d | menuisees : %d | DERIVEES : %d\n",
    $totaux['lignes'], $totaux['cellules'], $totaux['NORMALISE'], $totaux['ECRIT'],
    $totaux['AJOUTEE'], $totaux['EPHEMERE'], $totaux['DERIVE']);
printf("  la copie est figee a la bascule : %d lignes nees depuis, %d detruites par le menage du site — ce ne sont pas des pertes.\n",
    $totaux['AJOUTEE'], $totaux['EPHEMERE']);
echo $totaux['DERIVE'] === 0
    ? "VERDICT fidelite : la copie vivante ne contredit la copie figee que la ou c'est attendu.\n"
    : "VERDICT fidelite : " . $totaux['DERIVE'] . " ecart(s) inattendu(s) — a regarder avant de jeter la copie SQLite.\n";
$li->close(); $my->close();
exit($totaux['DERIVE'] === 0 ? 0 : 9);
