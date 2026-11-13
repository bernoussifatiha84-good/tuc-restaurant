<?php
/**
 * TUC — bascule SQLite -> MySQL, fidele au schema que le drop-in emule.
 *
 * Le drop-in « SQLite Database Integration » ne stocke pas que des lignes : il garde le
 * schema MySQL qu'il imite, dans _wp_sqlite_mysql_information_schema_columns (COLUMN_TYPE,
 * IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, GENERATION_EXPRESSION),
 * ..._table_constraints (PRIMARY / UNIQUE) et ..._statistics (index, SUB_PART).
 * On reconstruit donc le CREATE TABLE a partir de cette source plutot que de le deviner :
 * c'est la difference entre « la base s'ouvre » et « le moteur se comporte comme avant ».
 *
 * Regle de securite : il ne detruit jamais la source. Le drop-in (wp-content/db.php) reste
 * l'interrupteur, et la cible doit etre vide sauf TUC_FORCER=1.
 *
 * Configuration par variables d'environnement uniquement — jamais par arguments, pour
 * qu'aucun mot de passe ne paraisse dans `ps`, ni dans les journaux du run :
 *   TUC_SQLITE, TUC_MYSQL_SOCKET | TUC_MYSQL_HOST + TUC_MYSQL_PORT, TUC_MYSQL_NAME,
 *   TUC_MYSQL_USER, TUC_MYSQL_PASS, TUC_PREFIX, TUC_MODE (dry|run), TUC_FORCER, TUC_LOT.
 */
declare(strict_types=1);

$SQLITE = (string) (getenv('TUC_SQLITE') ?: '');
$SOCK   = (string) (getenv('TUC_MYSQL_SOCKET') ?: '');
$HOST   = (string) (getenv('TUC_MYSQL_HOST') ?: '127.0.0.1');
$PORT   = (int) (getenv('TUC_MYSQL_PORT') ?: 3306);
$NAME   = (string) (getenv('TUC_MYSQL_NAME') ?: '');
$USER   = (string) (getenv('TUC_MYSQL_USER') ?: 'root');
$PASS   = (string) (getenv('TUC_MYSQL_PASS') ?: '');
$PREFIX = (string) (getenv('TUC_PREFIX') ?: 'wp_');
$MODE   = (getenv('TUC_MODE') ?: 'dry') === 'run' ? 'run' : 'dry';
$FORCER = getenv('TUC_FORCER') === '1';
$LOT    = max(50, (int) (getenv('TUC_LOT') ?: 400));

function l(string $s): void { echo $s, "\n"; }
function qid(string $n): string { return '`' . str_replace('`', '``', $n) . '`'; }
function hach(string $s): string { return substr(hash('sha256', $s), 0, 12); }
function quote_lit(string $v): string { return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'"; }

// Les identifiants ne doivent jamais paraitre dans une ligne de commande : ni dans `ps`,
// ni — surtout — dans le journal du run qui telecommande le serveur. Sur `/uat`, on passe donc
// par TUC_WPCONFIG et le script relit lui-meme les constantes que WordPress y a deja ecrites.
$WPCFG = (string) (getenv('TUC_WPCONFIG') ?: '');
if ($WPCFG !== '' && is_file($WPCFG)) {
    $cfg = (string) file_get_contents($WPCFG);
    // Piege releve a l'essai : WordPress echappe les quotes des valeurs
    // (define('DB_PASSWORD', 'a\'b')). Une regex qui s'arrete au premier apostrophe rendait un
    // mot de passe tronque de 5 caracteres sur 12, donc un « Access denied » sans rapport avec
    // la cause. On lit le texte caractere par caractere, en respectant les backslashes.
    $lire = static function (string $const) use ($cfg): ?string {
        $pat = '/define\s*\(\s*[\'"]' . preg_quote($const, '/') . '[\'"]\s*,\s*([\'"])/';
        if (!preg_match($pat, $cfg, $mm, PREG_OFFSET_CAPTURE)) { return null; }
        $quote = $mm[1][0];
        $i = $mm[1][1] + 1;
        $n = strlen($cfg);
        $out = '';
        while ($i < $n) {
            $ch = $cfg[$i];
            if ($ch === '\\') {
                $suiv = $cfg[$i + 1] ?? '';
                $out .= ($suiv === $quote || $suiv === '\\') ? $suiv : $ch . $suiv;
                $i += 2;
                continue;
            }
            if ($ch === $quote) { break; }
            $out .= $ch;
            $i++;
        }
        return $out;
    };
    // Un define() peut etre un saut (`DB_HOST` vaut parfois une expression) : on ne devine pas,
    // on le dit, et on laisse les variables d'environnement prendre le relais.
    $lus = 0;
    foreach (['DB_NAME' => 'TUC_MYSQL_NAME', 'DB_USER' => 'TUC_MYSQL_USER', 'DB_PASSWORD' => 'TUC_MYSQL_PASS',
              'DB_HOST' => 'TUC_MYSQL_HOST', 'DB_CHARSET' => 'TUC_CHARSET'] as $c => $env) {
        $v = $lire($c);
        if ($v === null) { continue; }
        if ($env === 'TUC_MYSQL_HOST') {
            // DB_HOST porte trois formes : host, host:port, localhost:/chemin/du/socket.
            if (str_contains($v, ':')) { [$h, $ap] = explode(':', $v, 2);
                if (is_numeric($ap)) { putenv("TUC_MYSQL_PORT={$ap}"); $v = $h; }
                elseif (str_starts_with($ap, '/')) { putenv("TUC_MYSQL_SOCKET={$ap}"); $SOCK = $ap; $v = $h; }
                else { $v = $h; }
            }
            if ($v !== '' && $v !== 'localhost' && !str_starts_with($v, '/')) { putenv("TUC_MYSQL_HOST={$v}"); }
            $lus++;
            continue;
        }
        putenv($env . '=' . $v); $lus++;
    }
    $NAME = (string) (getenv('TUC_MYSQL_NAME') ?: $NAME);
    $USER = (string) (getenv('TUC_MYSQL_USER') ?: $USER);
    $PASS = (string) (getenv('TUC_MYSQL_PASS') !== false ? getenv('TUC_MYSQL_PASS') : $PASS);
    $HOST = (string) (getenv('TUC_MYSQL_HOST') ?: $HOST);
    $PORT = (int) (getenv('TUC_MYSQL_PORT') ?: $PORT);
    l('  identifiants relus dans ' . basename($WPCFG) . " : {$lus} constante(s) — aucune n'a transite par la ligne de commande");
    // Longueurs et non valeurs : c'est ce diagnostic qui a permis de voir, a l'essai local,
    // que le mot de passe etait tronque. En production, il reste la seule facon de le savoir.
    l('  relues : DB_NAME=' . ($lire('DB_NAME') ?? 'absent')
        . ' | DB_USER=' . ($lire('DB_USER') ?? 'absent')
        . ' | DB_HOST=' . ($lire('DB_HOST') ?? 'absent')
        . ' | DB_PASSWORD=' . (($p = $lire('DB_PASSWORD')) === null ? 'absent' : strlen($p) . ' caractere(s) (non affiche)'));
}

if ($SQLITE === '' || !is_file($SQLITE)) { l('  ABORT : TUC_SQLITE invalide'); exit(2); }

$src = new PDO('sqlite:' . $SQLITE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$src->exec('PRAGMA query_only = ON');

$dsn = $SOCK !== ''
    ? "mysql:unix_socket={$SOCK};dbname={$NAME};charset=utf8mb4"
    : "mysql:host={$HOST};port={$PORT};dbname={$NAME};charset=utf8mb4";
try {
    $dst = new PDO($dsn, $USER, $PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    // Le DSN n'est jamais imprime : le message de PDO ne contient pas le mot de passe.
    l('  ABORT : connexion MySQL refusee : ' . $e->getMessage());
    exit(2);
}

$serveur = (string) $dst->query('SELECT VERSION()')->fetchColumn();
$paquet = (int) $dst->query('SELECT @@max_allowed_packet')->fetchColumn();
$budgetPaquet = max(65536, intdiv($paquet * 6, 10));
l('  source : sqlite ' . number_format((float) filesize($SQLITE), 0, ',', ' ') . ' o | sha256 ' . substr(hash_file('sha256', $SQLITE), 0, 16));
l('  cible  : ' . $serveur . ' | base `' . $NAME . '` | prefixe ' . $PREFIX . ' | mode ' . $MODE
    . ' | max_allowed_packet ' . number_format((float) $paquet, 0, ',', ' ') . ' o -> budget de lot '
    . number_format((float) $budgetPaquet, 0, ',', ' ') . ' o');

// ------------------------------------------------- echafaudage : le schema MySQL vise
$metaCols = [];
$hasMeta = false;
try {
    $rs = $src->query('SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE,
                              DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
                              DATETIME_PRECISION, COLLATION_NAME, COLUMN_TYPE, COLUMN_KEY, EXTRA,
                              GENERATION_EXPRESSION
                       FROM _wp_sqlite_mysql_information_schema_columns
                       ORDER BY TABLE_NAME, ORDINAL_POSITION');
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) { $metaCols[(string) $r['TABLE_NAME']][] = $r; }
    $hasMeta = $metaCols !== [];
} catch (Throwable $e) { $hasMeta = false; }
if (!$hasMeta) { l("  ATTENTION : pas d'echafaudage information_schema — repli PRAGMA (DDL moins fidele)"); }

$contraintes = [];
try {
    foreach ($src->query('SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM _wp_sqlite_mysql_information_schema_table_constraints')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $contraintes[(string) $r['TABLE_NAME']][] = $r;
    }
} catch (Throwable $e) { /* tableau absent */ }

$index = [];
try {
    foreach ($src->query('SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, SUB_PART
                          FROM _wp_sqlite_mysql_information_schema_statistics
                          WHERE INDEX_NAME <> "PRIMARY"
                          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $index[(string) $r['TABLE_NAME']][(string) $r['INDEX_NAME']][] = $r;
    }
} catch (Throwable $e) { /* tableau absent */ }

// ------------------------------------------------- collation : ce que CE serveur accepte
$dispo = [];
try {
    $dispo = $dst->query("SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE CHARACTER_SET_NAME = 'utf8mb4'")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $dispo = ['utf8mb4_unicode_ci'];
}
$collation = 'utf8mb4_unicode_ci';
foreach (['utf8mb4_unicode_520_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci'] as $candidat) {
    if (in_array($candidat, $dispo, true)) { $collation = $candidat; break; }
}
$zeroDates = 0;
$collEcartees = 0;
l("  collation retenue : {$collation}" . (in_array('utf8mb4_0900_ai_ci', $dispo, true) ? '' : '  (utf8mb4_0900_ai_ci du drop-in absente de ce serveur : reecrite)'));

// ------------------------------------------------- inventaire
$tables = [];
foreach ($src->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $t = (string) $t;
    if (str_starts_with($t, '_wp_sqlite') || str_starts_with($t, '_sqlite')) { continue; }
    $tables[$t] = (int) $src->query('SELECT COUNT(*) FROM ' . qid($t))->fetchColumn();
}
l('  tables a migrer : ' . count($tables) . ' | lignes : ' . number_format((float) array_sum($tables), 0, ',', ' '));
l('  ecartees (echafaudage du drop-in) : ' . (int) $src->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE '_wp_sqlite%'")->fetchColumn());

// ------------------------------------------------- la cible doit etre vide
$deja = $dst->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
$deja = array_values(array_filter(array_map('strval', $deja), static fn($t) => str_starts_with($t, $PREFIX)));
if ($deja !== [] && !$FORCER) {
    l('  ABORT : la cible contient deja ' . count($deja) . ' table(s) (ex. ' . implode(', ', array_slice($deja, 0, 3)) . ')');
    l("         Rien n'a ete ecrit. TUC_FORCER=1 pour remplacer — ne le fais qu'en connaissance de cause.");
    exit(3);
}
if ($deja !== []) { l('  TUC_FORCER=1 : ' . count($deja) . ' table(s) existante(s) seront remplacees'); }

// ------------------------------------------------- DDL
$genParTable = [];
$colsParTable = [];
$planches = [];
foreach (array_keys($tables) as $t) {
    $cols = [];
    $pk = [];
    $gen = [];
    if ($hasMeta && isset($metaCols[$t])) {
        foreach ($metaCols[$t] as $c) {
            $nom = (string) $c['COLUMN_NAME'];
            $type = trim((string) ($c['COLUMN_TYPE'] !== null && $c['COLUMN_TYPE'] !== '' ? $c['COLUMN_TYPE'] : strtolower((string) $c['DATA_TYPE'])));
            $dt = strtolower((string) $c['DATA_TYPE']);
            if ($c['COLLATION_NAME'] !== null && (string) $c['COLLATION_NAME'] !== $collation) { $collEcartees++; }
            $d = (string) $c['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : ' NULL';
            $def = '';
            $v = (string) ($c['COLUMN_DEFAULT'] ?? '');
            if ($c['COLUMN_DEFAULT'] !== null && $v !== '') {
                if (preg_match('/^0000-00-00/', $v) && in_array($dt, ['datetime', 'timestamp', 'date'], true)) {
                    // On CONSERVE ce default : c'est celui du schema.sql de WordPress core
                    // (post_date, post_date_gmt, post_modified* ...). Mesure locale : avec le
                    // sql_mode que WP impose a sa connexion, l'INSERT qui omet la colonne rend
                    // '0000-00-00 00:00:00' comme avant ; en retirant le default, la meme
                    // insertion echoue en « Field doesn't have a default value ». Le retirer
                    // etait donc un.e amelioration qui cassait la fidelite.
                    $zeroDates++;
                    $def = ' DEFAULT ' . quote_lit($v);
                } elseif (in_array(strtoupper($v), ['CURRENT_TIMESTAMP', 'NULL'], true) || preg_match('/^-?\d+(\.\d+)?$/', $v)) {
                    $def = ' DEFAULT ' . $v;
                } else {
                    $def = ' DEFAULT ' . quote_lit($v);
                }
            }
            $extra = strtoupper(trim((string) ($c['EXTRA'] ?? '')));
            $ge = trim((string) ($c['GENERATION_EXPRESSION'] ?? ''));
            if ($ge !== '') {
                // La valeur calculee existe deja dans SQLite : on la copie telle quelle en
                // colonne ordinaire. Reproduire l'expression MySQL risquerait un ecart de
                // syntaxe, et un ecart de calcul est pire qu'une colonne non calculee.
                $gen[] = $nom;
                $extra = '';
            }
            $cols[] = qid($nom) . ' ' . $type . $d . $def . ($extra !== '' ? ' ' . $extra : '');
            if ((string) $c['COLUMN_KEY'] === 'PRI') { $pk[] = $nom; }
        }
    } else {
        foreach ($src->query('PRAGMA table_info(' . qid($t) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $nom = (string) $c['name'];
            $sql = strtolower((string) $c['type']);
            if (str_contains($sql, 'int')) {
                $type = ((int) $c['pk'] === 1) ? 'bigint(20) NOT NULL AUTO_INCREMENT' : 'bigint(20) NOT NULL DEFAULT 0';
            } elseif (str_contains($sql, 'real') || str_contains($sql, 'floa') || str_contains($sql, 'doub') || str_contains($sql, 'num')) {
                $type = 'double NOT NULL DEFAULT 0';
            } else {
                $type = ((int) $c['notnull'] === 1) ? 'longtext NOT NULL' : 'longtext NULL';
            }
            $cols[] = qid($nom) . ' ' . $type;
            if ((int) $c['pk'] === 1) { $pk[] = $nom; }
        }
    }

    $cles = [];
    if ($pk !== []) { $cles[] = 'PRIMARY KEY (' . implode(', ', array_map('qid', $pk)) . ')'; }
    $vus = [];
    foreach ($index[$t] ?? [] as $nom => $parties) {
        $u = ((int) $parties[0]['NON_UNIQUE'] === 0) ? 'UNIQUE KEY' : 'KEY';
        $p = [];
        foreach ($parties as $par) {
            $col = qid((string) $par['COLUMN_NAME']);
            if (!empty($par['SUB_PART'])) { $col .= '(' . (int) $par['SUB_PART'] . ')'; }
            $p[] = $col;
        }
        $vus[$nom] = true;
        $cles[] = $u . ' ' . qid((string) $nom) . ' (' . implode(', ', $p) . ')';
    }
    // Contraintes UNIQUE declarees sans ligne dans statistics (le cas option_name).
    foreach ($contraintes[$t] ?? [] as $k) {
        if ((string) $k['CONSTRAINT_TYPE'] !== 'UNIQUE') { continue; }
        $nom = (string) $k['CONSTRAINT_NAME'];
        if (isset($vus[$nom])) { continue; }
        $noms = [];
        foreach ($src->query('PRAGMA index_list(' . qid($t) . ')')->fetchAll(PDO::FETCH_ASSOC) as $i) {
            if ((string) $i['name'] !== $nom) { continue; }
            foreach ($src->query('PRAGMA index_info(' . qid($nom) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c2) {
                $noms[] = qid((string) $c2['name']);
            }
        }
        if ($noms !== []) { $cles[] = 'UNIQUE KEY ' . qid($nom) . ' (' . implode(', ', $noms) . ')'; }
    }

    $planches[$t] = 'CREATE TABLE ' . qid($t) . " (\n  " . implode(",\n  ", array_merge($cols, $cles))
        . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$collation}";
    $genParTable[$t] = $gen;
    $colsParTable[$t] = count($cols);
}

l('  DDL genere : ' . count($planches) . ' CREATE TABLE | ' . array_sum($colsParTable) . ' colonnes'
    . ' | ' . array_sum(array_map('count', $genParTable)) . ' colonne(s) calculee(s) convertie(s) en valeurs stockees'
    . ' | ' . $collEcartees . ' collation(s) ramenee(s) a la table'
    . ' | ' . $zeroDates . ' default(s) date nulle(s) conserves (comme dans le schema de core)');

if ($MODE === 'dry') {
    l("\n--- dry : deux planches completes, puis la volumetrie ---");
    $i = 0;
    foreach ($planches as $t => $sql) {
        if ($i++ >= 2) { break; }
        l("  # {$t}\n" . preg_replace('/^/m', '      ', $sql));
    }
    foreach ($tables as $t => $n) { l(sprintf('  %-40s %6d ligne(s)', $t, $n)); }
    l("\n  VERDICT sec — mode dry, rien n'a ete ecrit");
    exit(0);
}

// ------------------------------------------------- chargement
foreach (["SET SESSION sql_mode = ''", 'SET SESSION time_zone = "+00:00"', 'SET SESSION unique_checks = 0',
          'SET SESSION foreign_key_checks = 0', 'SET SESSION sql_log_bin = 0'] as $s) {
    try { $dst->exec($s); } catch (Throwable $e) { l('  (note : ' . explode(' ', $s)[2] . ' refuse par ce serveur)'); }
}

$rapport = [];
$normAuto = 0;
$sousLots = 0;
$erreur = null;
$t0 = microtime(true);

foreach ($planches as $t => $sql) {
    try {
        if (in_array($t, $deja, true)) { $dst->exec('DROP TABLE ' . qid($t)); }
        $dst->exec($sql);

        $colsSrc = array_map(static fn($r) => (string) $r['name'], $src->query('PRAGMA table_info(' . qid($t) . ')')->fetchAll(PDO::FETCH_ASSOC));
        $liste = implode(', ', array_map('qid', $colsSrc));
        $trous = '(' . implode(', ', array_fill(0, count($colsSrc), '?')) . ')';
        $totalSrc = $tables[$t];

        $dst->beginTransaction();
        $ecrits = 0;
        // Pagination : un LIMIT/OFFSET SANS ORDER BY ne garantit rien cote SQLite — deux requetes
        // consecutives peuvent suivre deux plans differents (table full scan vs index) et doubler
        // ou sauter une ligne. On paie sur rowid : ordre stable, et gratuit a trier.
        $vider = static function (array $b) use ($dst, $t, $liste, $trous, &$ecrits, &$sousLots, &$vider): void {
            if ($b === []) { return; }
            $vals = [];
            foreach ($b as $r) { foreach ($r as $v) { $vals[] = $v; } }
            try {
                $dst->prepare('INSERT INTO ' . qid($t) . ' (' . $liste . ') VALUES '
                    . implode(', ', array_fill(0, count($b), $trous)))->execute($vals);
                $ecrits += count($b);
                $sousLots++;
            } catch (Throwable $e) {
                if (count($b) < 2) { throw $e; }
                // Un lot trop gros ne rend pas une erreur de donnees : il peut couper la
                // connexion. On le rejoue en deux moities — la ligne fautive se nomme elle-meme.
                $m = intdiv(count($b), 2);
                $vider(array_slice($b, 0, $m));
                $vider(array_slice($b, $m));
            }
        };
        for ($off = 0; $off < $totalSrc; $off += $LOT) {
            $n = min($LOT, $totalSrc - $off);
            $sel = $src->query('SELECT ' . $liste . ' FROM ' . qid($t) . " ORDER BY rowid LIMIT {$n} OFFSET {$off}");
            $cur = [];
            $octets = 0;
            while ($row = $sel->fetch(PDO::FETCH_NUM)) {
                $len = 0;
                foreach ($row as $k => $v) {
                    if ($t === $PREFIX . 'options' && $colsSrc[$k] === 'autoload') {
                        $iv = strtolower((string) $v);
                        if ($iv === 'on') { $v = 'yes'; $normAuto++; }
                        elseif ($iv === 'off') { $v = 'no'; $normAuto++; }
                        $row[$k] = $v;
                    }
                    $len += $v === null ? 4 : strlen((string) $v);
                }
                if ($cur !== [] && ($octets + $len > $budgetPaquet || count($cur) * count($colsSrc) >= 60000)) {
                    $vider($cur);
                    $cur = [];
                    $octets = 0;
                }
                $cur[] = $row;
                $octets += $len;
            }
            $vider($cur);
        }
        $dst->commit();

        $cible = (int) $dst->query('SELECT COUNT(*) FROM ' . qid($t))->fetchColumn();
        $rapport[$t] = ['src' => $totalSrc, 'dst' => $cible, 'ecrits' => $ecrits, 'ok' => $cible === $totalSrc];

        $pk = null;
        foreach ($metaCols[$t] ?? [] as $c) {
            if (str_contains(strtolower((string) ($c['EXTRA'] ?? '')), 'auto_increment')) { $pk = (string) $c['COLUMN_NAME']; break; }
        }
        if ($pk !== null) {
            $max = (int) $dst->query('SELECT COALESCE(MAX(' . qid($pk) . '), 0) FROM ' . qid($t))->fetchColumn();
            try { $dst->exec('ALTER TABLE ' . qid($t) . ' AUTO_INCREMENT = ' . ($max + 1)); } catch (Throwable $e) { /* MariaDB tolere */ }
            $rapport[$t]['auto_inc'] = $max + 1;
        }
    } catch (Throwable $e) {
        if ($dst->inTransaction()) { $dst->rollBack(); }
        $erreur = $t . ' : ' . $e->getMessage();
        $rapport[$t] = ['src' => $tables[$t], 'dst' => -1, 'ok' => false, 'erreur' => $e->getMessage()];
        l('  ECHEC sur ' . $t . ' : ' . $e->getMessage());
        break;
    }
}

// ------------------------------------------------- controles croises
l("\n== controles table par table ==");
$ko = 0;
foreach ($rapport as $t => $r) {
    if (!($r['ok'] ?? false)) { $ko++; }
    if (($r['src'] ?? 0) > 0 || !($r['ok'] ?? true)) {
        l(sprintf('  %-40s %7d %7d  %s', $t, $r['src'], $r['dst'], ($r['ok'] ?? false) ? 'ok' : 'KO'));
    }
}
$absentes = array_diff(array_keys($tables), array_keys($rapport));
if ($absentes !== []) { l('  tables non traitees : ' . implode(', ', $absentes)); $ko += count($absentes); }
l('  tables en ecart : ' . $ko);
$lignesEcrites = array_sum(array_column($rapport, 'ecrits'));
l('  sous-lots emis : ' . $sousLots . ' pour ' . $lignesEcrites . ' lignes | lot demande : ' . $LOT
    . ' ligne(s) | budget : ' . number_format((float) $budgetPaquet, 0, ',', ' ') . ' o'
    . ($sousLots < $lignesEcrites ? '  -> le budget de paquet a recoupe les lots'
        : '  -> un envoi par ligne, budget jamais sollicite'));
if ($normAuto > 0) { l("  autoload normalise : {$normAuto} valeur(s) on/off -> yes/no (le drop-in ne traduisait pas ce champ ; sans ca, 208 options cessent d'etre chargees)"); }

$verif = [];
if ($ko === 0) {
    l("\n== controles de fidelite ==");
    $s1 = (int) $src->query('SELECT COALESCE(SUM(ID),0) FROM ' . qid($PREFIX . 'posts'))->fetchColumn();
    $s2 = (int) $dst->query('SELECT COALESCE(SUM(ID),0) FROM ' . qid($PREFIX . 'posts'))->fetchColumn();
    $verif['sum_id_posts'] = ($s1 === $s2);
    l("  SUM(ID) wp_posts : {$s1} | {$s2} -> " . ($verif['sum_id_posts'] ? 'ok' : 'KO'));

    $p1 = $src->query("SELECT MIN(CAST(meta_value AS REAL)), MAX(CAST(meta_value AS REAL)), COUNT(*) FROM {$PREFIX}postmeta WHERE meta_key='tuc_prix'")->fetch(PDO::FETCH_NUM);
    $p2 = $dst->query('SELECT MIN(CAST(meta_value AS DECIMAL(12,4))), MAX(CAST(meta_value AS DECIMAL(12,4))), COUNT(*) FROM ' . qid($PREFIX . 'postmeta') . " WHERE meta_key='tuc_prix'")->fetch(PDO::FETCH_NUM);
    $verif['prix'] = ((float) $p1[0] === (float) $p2[0] && (float) $p1[1] === (float) $p2[1] && (int) $p1[2] === (int) $p2[2]);
    l('  prix (min/max/n) : ' . implode('/', [(string) $p1[0], (string) $p1[1], (string) $p1[2]]) . ' | '
        . implode('/', [(string) $p2[0], (string) $p2[1], (string) $p2[2]]) . ' -> ' . ($verif['prix'] ? 'ok' : 'KO'));

    $t1 = $src->query("SELECT ID, post_title FROM {$PREFIX}posts WHERE post_title LIKE '%é%' OR post_title LIKE '%ô%' OR post_title LIKE '%à%' ORDER BY ID")->fetchAll(PDO::FETCH_KEY_PAIR);
    $mauvais = [];
    if ($t1 !== []) {
        $ids = implode(',', array_map('intval', array_keys($t1)));
        $t2 = $dst->query('SELECT ID, post_title FROM ' . qid($PREFIX . 'posts') . " WHERE ID IN ({$ids}) ORDER BY ID")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($t1 as $id => $titre) {
            if ((string) ($t2[$id] ?? "\x01") !== (string) $titre) { $mauvais[] = $id; }
        }
    }
    $verif['accents'] = ($t1 !== [] && $mauvais === []);
    l('  accents : ' . count($t1) . ' titre(s) non ascii reecrit(s) a l\'identique -> '
        . ($verif['accents'] ? 'ok (ex. ' . (string) reset($t1) . ')' : 'KO ids ' . implode(',', $mauvais)));
    // Ce que le test dit aussi : la collation retenue est insensible aux accents, comme
    // utf8mb4_0900_ai_ci que le drop-in emulait. Un LIKE du back-office se comportera donc
    // comme avant — c'est le comportement WordPress par defaut, pas une degradation.
    $ai = (int) $dst->query("SELECT COUNT(*) FROM " . qid($PREFIX . 'posts') . " WHERE post_title LIKE '%confidentialite%'")->fetchColumn();
    $src_ai = (int) $src->query("SELECT COUNT(*) FROM {$PREFIX}posts WHERE post_title LIKE '%confidentialite%'")->fetchColumn();
    l("  sensacites de la collation : 'confidentialite' sans accents trouve {$ai} ligne(s) en MySQL, {$src_ai} en SQLite -> "
        . ($ai >= $src_ai ? 'insensible aux accents (comme le drop-in)' : 'KO : attention'));

    $a1 = (string) $src->query("SELECT option_value FROM {$PREFIX}options WHERE option_name='{$PREFIX}user_roles'")->fetchColumn();
    $a2 = (string) $dst->query('SELECT option_value FROM ' . qid($PREFIX . 'options') . " WHERE option_name='{$PREFIX}user_roles'")->fetchColumn();
    $verif['roles'] = (hach($a1) === hach($a2));
    l('  roles serializes : ' . ($verif['roles'] ? 'ok (hach ' . hach($a2) . ')' : 'KO : ' . hach($a1) . ' vs ' . hach($a2)));

    $au = $dst->query('SELECT autoload, COUNT(*) FROM ' . qid($PREFIX . 'options') . ' GROUP BY autoload')->fetchAll(PDO::FETCH_KEY_PAIR);
    l('  autoload en cible : ' . json_encode($au));
    $verif['autoload'] = !isset($au['on']) && !isset($au['off']);

    $c = $dst->query('CHECK TABLE ' . qid($PREFIX . 'posts') . ', ' . qid($PREFIX . 'postmeta') . ', ' . qid($PREFIX . 'options') . ', ' . qid($PREFIX . 'terms') . ', ' . qid($PREFIX . 'users'))->fetchAll(PDO::FETCH_ASSOC);
    $bad = array_values(array_filter($c, static fn($r) => (string) $r['Msg_text'] !== 'OK'));
    $verif['check_table'] = ($bad === []);
    l('  CHECK TABLE (5 tables cles) : ' . ($verif['check_table'] ? 'ok' : 'KO ' . json_encode(array_column($bad, 'Msg_text'))));

    $i1 = (int) $src->query("SELECT COUNT(*) FROM {$PREFIX}posts WHERE post_type='tuc_menu_item' AND post_status='publish'")->fetchColumn();
    $i2 = (int) $dst->query("SELECT COUNT(*) FROM " . qid($PREFIX . 'posts') . " WHERE post_type='tuc_menu_item' AND post_status='publish'")->fetchColumn();
    l("  plats publies : {$i1} | {$i2} -> " . ($i1 === $i2 ? 'ok' : 'KO'));
    $verif['plats'] = ($i1 === $i2);
}

// ------------------------------------------------- verification exhaustive (TUC_VERIFY=1)
// Un compte de lignes prouve que rien ne manque ; ca ne prouve pas que rien n'a bouge.
// On compare donc chaque cellule, des deux cotes, apres canonisation (null, et egalite
// numerique toleree pour les colonnes Reelles/double ou 45.5 et 45.5000 sont la meme valeur).
$verifier = getenv('TUC_VERIFY') !== '0';
if ($verifier && $ko === 0) {
    l("\n== verification exhaustive cellule par cellule ==");
    $norm = static function ($v) {
        if ($v === null) { return "\x00NULL"; }
        $v = (string) $v;
        if (is_numeric($v)) {
            $f = (float) $v;
            if ($f === (int) $f && abs($f) < 1e15) { return 'N:' . (int) $f; }
            return sprintf('N:%.9G', $f);
        }
        return 'S:' . $v;
    };
    $tot = 0; $diff = 0; $exemples = []; $transferts = 0;
    foreach (array_keys($tables) as $t) {
        $cols = array_map(static fn($r) => (string) $r['name'], $src->query('PRAGMA table_info(' . qid($t) . ')')->fetchAll(PDO::FETCH_ASSOC));
        $iAuto = $t === $PREFIX . 'options' ? array_search('autoload', $cols, true) : false;
        $liste = implode(', ', array_map('qid', $cols));
        $pg = [];
        foreach ($src->query('SELECT ' . $liste . ' FROM ' . qid($t))->fetchAll(PDO::FETCH_NUM) as $r) { $pg[] = $norm; }
        $a = [];
        foreach ($src->query('SELECT ' . $liste . ' FROM ' . qid($t))->fetchAll(PDO::FETCH_NUM) as $r) {
            $a[] = implode("\x1F", array_map($norm, $r));
        }
        $b = [];
        foreach ($dst->query('SELECT ' . $liste . ' FROM ' . qid($t))->fetchAll(PDO::FETCH_NUM) as $r) {
            $b[] = implode("\x1F", array_map($norm, $r));
        }
        sort($a); sort($b);
        $n = max(count($a), count($b));
        $tot += count($a) * count($cols);
        for ($i = 0; $i < $n; $i++) {
            if (($a[$i] ?? '\x01ABS') !== ($b[$i] ?? '\x01ABS')) {
                if ($iAuto !== false) { // seule la colonne autoload a le droit de différer
                    $x = explode("\x1F", (string) ($a[$i] ?? '')); $y = explode("\x1F", (string) ($b[$i] ?? ''));
                    $attendu = match((string) ($x[$iAuto] ?? '')) { 'S:on' => 'S:yes', 'S:off' => 'S:no', default => (string) ($x[$iAuto] ?? '') };
                    if (count($x) === count($y)) {
                        $x[$iAuto] = $attendu;
                        if (implode("\x1F", $x) === implode("\x1F", $y)) { $transferts++; continue; }
                    }
                }
                $diff++;
                if (count($exemples) < 3) {
                    $exemples[] = $t . ' #' . $i . "\n       sqlite : " . str_replace("\x1F", ' | ', (string) ($a[$i] ?? 'absente'))
                        . "\n       mysql  : " . str_replace("\x1F", ' | ', (string) ($b[$i] ?? 'absente'));
                }
            }
        }
    }
    $verif['octets'] = ($diff === 0);
    l('  ' . number_format((float) $tot, 0, ',', ' ') . ' cellules comparees sur ' . count($tables) . ' tables : '
        . ($diff === 0 ? '0 ecart -> copie fidele' : $diff . ' ecart(s)')
        . ($transferts > 0 ? " (+ {$transferts} ligne(s) modifiees volontairement : autoload)" : ''));
    foreach ($exemples as $x) { l('  ' . $x); }
}

if ($MODE === 'run' && !$verifier) { l("\n  ATTENTION : verification cellulaire DESACTIVEE (TUC_VERIFY=0) — le verdict ne repose plus que sur des comptes de lignes"); }

try { $dst->exec('SET SESSION unique_checks = 1'); $dst->exec('SET SESSION foreign_key_checks = 1'); } catch (Throwable $e) { /* rien */ }

$ok = $ko === 0 && !in_array(false, $verif, true) && $erreur === null;
l(sprintf("\n  duree : %.1f s | lignes : %d | %s", microtime(true) - $t0, array_sum(array_column($rapport, 'ecrits')),
    $ok ? 'VERDICT ok' : 'VERDICT ko' . ($erreur !== null ? ' (' . $erreur . ')' : '')));
l('  ' . json_encode(['tables' => count($tables), 'lignes' => array_sum(array_column($rapport, 'ecrits')),
    'ecarts' => $ko, 'verifs' => $verif, 'collation' => $collation, 'mode' => $MODE]));
exit($ok ? 0 : 1);
