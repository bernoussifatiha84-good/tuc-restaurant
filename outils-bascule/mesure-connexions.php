<?php
/**
 * TUC — mesurer ce que le compte MySQL supporte, sans passer par SHOW PROCESSLIST.
 *
 * Sur cet hebergeur, SHOW PROCESSLIST rend zero ligne et SHOW STATUS est tantot refuse : le compte
 * n'a pas PROCESS. On ne peut donc pas « regarder » les connexions. On les FABRIQUE : ouvrir N
 * connexions reelles, lire le compteur global du serveur pendant qu'elles sont tenues, puis les
 * fermer et relire — la difference entre les deux lectures est le nombre de connexions que ce compte
 * tient vraiment. C'est une mesure directe, pas une estimation, et elle ne depend d'aucun privilege.
 *
 * Le script s'arrete des qu'une connexion est refusee : ce refus-la EST le plafond, et c'est la
 * seule maniere de le connaitre autrement que par la valeur affichee de max_user_connections.
 *
 * Usage : php mesure-connexions.php <wp-config.php> [N]
 *   N = nombre de connexions a ouvrir en parallele (defaut 24, plafonne a 40).
 */
declare(strict_types=1);

$cfgPath = (string) ($argv[1] ?? '');
$voulu = min(40, max(2, (int) ($argv[2] ?? 24)));
if ($cfgPath === '' || !is_file($cfgPath)) { fwrite(STDERR, "usage : php mesure-connexions.php <wp-config> [N]\n"); exit(2); }
$cfg = (string) file_get_contents($cfgPath);
$lit = static function (string $k) use ($cfg): string {
    return preg_match("/define\\(\\s*['\"]" . preg_quote($k, '/') . "['\"]\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfg, $m)
        ? stripcslashes($m[1]) : '';
};
[$dbn, $dbu, $dbp, $dbh] = [$lit('DB_NAME'), $lit('DB_USER'), $lit('DB_PASSWORD'), $lit('DB_HOST')];
if ($dbn === '' || $dbu === '') { fwrite(STDERR, "ABORT : DB_NAME/DB_USER absents du wp-config lu\n"); exit(2); }
$port = 3306; $sock = '';
if (str_contains($dbh, ':')) { [$h, $a] = explode(':', $dbh, 2); $dbh = $h; if (str_starts_with($a, '/')) { $sock = $a; } else { $port = (int) $a; } }
if ($sock === '' && $dbh === 'localhost') {
    $d = (string) ini_get('mysqli.default_socket');
    if ($d !== '' && file_exists($d)) { $sock = $d; }
}
mysqli_report(MYSQLI_REPORT_OFF);
function ouvrir(): mysqli|false
{
    global $dbh, $dbu, $dbp, $dbn, $port, $sock;
    $m = $sock !== ''
        ? @mysqli_connect($dbh, $dbu, $dbp, $dbn, $port, $sock)
        : @mysqli_connect($dbh, $dbu, $dbp, $dbn, $port);
    if ($m && !mysqli_connect_errno()) { $m->set_charset('utf8mb4'); }
    return $m ?: false;
}
function statut(mysqli $m, string $nom): string
{
    foreach ([
        "SHOW GLOBAL STATUS LIKE '$nom'",
        "SHOW STATUS LIKE '$nom'",
        "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = '$nom'",
    ] as $q) {
        $r = @$m->query($q);
        if ($r && $x = $r->fetch_row()) { return trim((string) ($x[1] ?? $x[0] ?? '')); }
    }
    return 'non expose';
}
function variable(mysqli $m, string $nom): string
{
    $r = @$m->query("SHOW VARIABLES LIKE '$nom'");
    if ($r && $x = $r->fetch_row()) { return (string) $x[1]; }
    return 'non expose';
}

echo " == ce que le serveur dit de lui-meme ==\n";
$t0 = microtime(true);
$ref = ouvrir();
if (!$ref) { fwrite(STDERR, 'ABORT : connexion de reference impossible : ' . mysqli_connect_error() . "\n"); exit(2); }
printf("  connexion de reference : ok en %.1f ms | compte %s | base %s | %s\n",
    (microtime(true) - $t0) * 1000, $dbu, $dbn, $sock !== '' ? 'socket ' . $sock : 'tcp ' . $dbh . ':' . $port);
printf("  version : %s\n", (string) $ref->query('SELECT VERSION()')->fetch_row()[0]);

// Pourquoi processlist est vide : la reponse est dans les privileges, pas dans la charge.
$g = $ref->query('SHOW GRANTS FOR CURRENT_USER()');
$grants = [];
if ($g) { while ($x = $g->fetch_row()) { $grants[] = (string) $x[0]; } }
printf("  privileges du compte : %s\n", $grants === [] ? 'non lus' : implode(' ;; ', array_slice($grants, 0, 3)));
printf("  → PROCESS %s : %s\n",
    str_contains(strtolower(implode(' ', $grants)), 'process') ? 'ACCORDE' : 'NON accorde',
    str_contains(strtolower(implode(' ', $grants)), 'process')
        ? 'processlist aurait du repondre — le compte a zero ligne viendrait d ailleurs'
        : 'voila pourquoi SHOW PROCESSLIST rend zero ligne : la mesure doit etre indirecte');
foreach (['max_connections', 'max_user_connections', 'wait_timeout', 'interactive_timeout',
          'thread_cache_size', 'innodb_buffer_pool_size', 'innodb_io_capacity'] as $v) {
    printf("     %-24s %s\n", $v, variable($ref, $v));
}
foreach (['Threads_connected', 'Threads_running', 'Max_used_connections', 'Aborted_connects',
          'Aborted_clients', 'Connections'] as $s) {
    printf("     %-24s %s\n", $s, statut($ref, $s));
}

echo "\n == mesure : ouvrir des connexions reelles et tenir == \n";
$avant = (int) statut($ref, 'Threads_connected');
if ($avant <= 0) { echo "  --  Threads_connected non expose : la mesure par delta ne peut pas etre lue\n"; }
$tenues = [];
$premiereErreur = '';
$t0 = microtime(true);
for ($i = 0; $i < $voulu; $i++) {
    $t1 = microtime(true);
    $m = ouvrir();
    if (!$m) { $premiereErreur = mysqli_connect_error() ?? 'erreur inconnue'; break; }
    $duree = (microtime(true) - $t1) * 1000;
    if ($i === 0) { printf("  premiere connexion : %.1f ms\n", $duree); }
    $tenues[] = ['m' => $m, 'ms' => $duree, 'n' => $i + 1];
    // requete minuscule sur chacune : une connexion oisive peut etre cachee par le thread_cache
    $m->query('DO 1');
}
$ouvertureMs = (microtime(true) - $t0) * 1000;
$ouvertes = count($tenues);
printf("  tentatives : %d | ouvertes : %d | refus : %s\n", $voulu, $ouvertes,
    $premiereErreur === '' ? 'aucun' : sprintf("« %s » a la %de tentative", $premiereErreur, $ouvertes + 1));
if ($premiereErreur !== '') {
    echo "  ce refus est le plafond reel du compte, et il est plus fiable que la variable affichee.\n";
}
$pendant = (int) statut($ref, 'Threads_connected');
$course = $tenues ? array_column($tenues, 'ms') : [];
sort($course);
printf("  temps d'ouverture : median %.1f ms | pire %.1f ms | %.1f ms pour les %d\n",
    $course ? $course[(int) (count($course) / 2)] : 0, $course ? end($course) : 0, $ouvertureMs, $ouvertes);
printf("  Threads_connected : %d avant -> %d pendant (ecart %s) | attendu %d\n",
    $avant, $pendant, ($avant > 0 && $pendant > 0) ? (string) ($pendant - $avant) : 'non lisible', $ouvertes);
if ($avant > 0 && $pendant > 0) {
    $ecart = $pendant - $avant;
    printf("  %s\n", $ecart >= $ouvertes
        ? '  → les connexions sont bien comptees : le serveur les a tenues en meme temps'
        : '  → ecart inferieur aux connexions ouvertes : le compteur global est partage avec les autres comptes,'
          . " le serveur n'est pas vide pendant la mesure — lire le chiffre comme un ordre de grandeur");
}

echo "\n == latence des requetes du chemin chaud, 40 allers-retours == \n";
// Les noms de tables viennent du prefixe relu dans le wp-config, pas d'une convention supposee, et
// ils sont resolus AVANT de composer les requetes : interpoler un $GLOBALS pas encore rempli rendait
// une requete sur `` et un p50 de 0,00 ms pris pour une mesure.
$prefix = preg_match("/table_prefix\\s*=\\s*['\"]([^'\"]+)/", $cfg, $mp) ? $mp[1] : 'wp_';
$T = ['posts' => $prefix . 'posts', 'postmeta' => $prefix . 'postmeta', 'options' => $prefix . 'options'];
foreach ($T as $tab) {
    if (!$ref->query("SELECT 1 FROM `$tab` LIMIT 1")) {
        printf("  --  table %s illisible (%s) : sa requete ne sera pas chronometree\n", $tab, mysqli_error($ref));
    }
}
$menuQ = "SELECT p.ID, p.post_title, CAST(pm.meta_value AS DECIMAL(10,2)) prix
    FROM `{$T['posts']}` p INNER JOIN `{$T['postmeta']}` pm ON pm.post_id = p.ID AND pm.meta_key = 'tuc_prix'
    WHERE p.post_status = 'publish' ORDER BY prix ASC LIMIT 20";
function chrono(callable $f, int $n): array
{
    $t = [];
    for ($i = 0; $i < $n; $i++) { $s = microtime(true); $f(); $t[] = (microtime(true) - $s) * 1000; }
    sort($t);
    return [$t[(int) ($n * 0.5)], $t[(int) ($n * 0.95)], end($t), $t[0]];
}
$mes = chrono(static function () use ($ref, $menuQ) { $r = $ref->query($menuQ); if ($r) { while ($r->fetch_row()) {} } }, 40);
printf("  requete du menu (jointure + tri numerique) : p50 %.2f ms | p95 %.2f ms | max %.2f ms | min %.2f ms\n",
    $mes[0], $mes[1], $mes[2], $mes[3]);
$mes2 = chrono(static function () use ($ref, $T) {
    $r = $ref->query("SELECT option_name, option_value FROM `{$T['options']}` WHERE autoload = 'yes'");
    if ($r) { while ($r->fetch_row()) {} }
}, 40);
printf("  lecture autoload (la requete que WordPress refait a chaque hit) : p50 %.2f ms | p95 %.2f ms | max %.2f ms\n",
    $mes2[0], $mes2[1], $mes2[2]);

echo "\n == ce qu'un cache d'objets changerait vraiment, mesure avant de decider == \n";
// La question n'est pas « Redis est-il bien » mais « combien de requetes et d'octets un cache
// d'objets sortirait de MySQL, par hit, et le plan le propose-t-il ». Les trois reponses ci-dessous
// viennent de mesures, pas de la reputation du plugin.
$auto = $ref->query("SELECT COUNT(*) n, COALESCE(SUM(LENGTH(option_value)),0) o FROM `{$T['options']}` WHERE autoload = 'yes'");
$au = $auto ? $auto->fetch_assoc() : [];
printf("  autoload : %s options, %s o rechargés a chaque hit non cache -> si 300 hits/heure, %s Mo lus\n",
    number_format((int) ($au['n'] ?? 0)), number_format((int) ($au['o'] ?? 0)),
    number_format(round((int) ($au['o'] ?? 0) * 300 / 1048576, 1)));
$trans = $ref->query("SELECT COUNT(*) n FROM `{$T['options']}` WHERE option_name LIKE '\\\_transient\\_%'");
$tr = $trans ? $trans->fetch_assoc() : [];
printf("  transients stockes en base : %s (ce sont eux que le cache d'objets absorbe le mieux)\n",
    number_format((int) ($tr['n'] ?? 0)));
// Le drop-in vit dans wp-content, pas a la racine du site : le chercher au mauvais endroit
// rendrait « non » alors qu'un object-cache.php est deja pose (et a retirer avant d'en poser un 2e).
$racine = rtrim(dirname((string) $argv[1]), '/');
$chemins = [$racine . '/wp-content/object-cache.php', $racine . '/object-cache.php'];
$trouve = '';
foreach ($chemins as $c) { if (is_file($c)) { $trouve = $c; break; } }
printf("  drop-in de cache present : %s\n", $trouve === '' ? 'non (donc pas de desinstallation a prevoir)' : $trouve);
foreach (['redis' => 'extension PHP Redis', 'memcached' => 'extension PHP Memcached', 'pdo_mysql' => 'pdo_mysql'] as $ext => $libelle) {
    printf("     %-22s %s\n", $libelle, extension_loaded($ext) ? 'chargee' : 'absente');
}
foreach ([['127.0.0.1', 6379, 'redis'], ['127.0.0.1', 11211, 'memcached']] as [$h, $pt, $nom]) {
    $t1 = microtime(true);
    $e = @fsockopen($h, $pt, $errno, $errstr, 1.2);
    $ms = (microtime(true) - $t1) * 1000;
    if ($e) {
        $reponse = '';
        if ($nom === 'redis') { fwrite($e, "PING\r\n"); $reponse = trim((string) fgets($e, 64)); }
        else { fwrite($e, "version\r\n"); $reponse = trim((string) fgets($e, 96)); }
        fclose($e);
        printf("     %s joint sur %s:%d en %.1f ms | premiere reponse : %s\n", $nom, $h, $pt, $ms,
            $reponse === '' ? '(rien lu)' : $reponse);
    } else {
        printf("     %s : pas de serveur joint joignable sur %s:%d en %.1f ms (%s)\n", $nom, $h, $pt, $ms,
            $errstr === '' ? "errno $errno" : $errstr);
    }
}

echo "\n == fermeture == \n";
foreach ($tenues as $x) { $x['m']->close(); }
usleep(200000);
$apres = (int) statut($ref, 'Threads_connected');
printf("  Threads_connected apres fermeture : %d (revient a la base %s)\n", $apres,
    ($avant > 0 && $apres > 0) ? ($apres <= $avant + 2 ? 'oui' : 'NON — des connexions sont restees ouvertes') : 'n/a');
$ref->close();
echo "  toutes les connexions de mesure sont fermees par ce script.\n";
exit(0);
