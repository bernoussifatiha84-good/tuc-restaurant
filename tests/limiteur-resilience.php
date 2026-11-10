<?php
/**
 * REVUE N°18 — RÉSILIENCE DU LIMITEUR, MESURÉE ET EXIGÉE.
 *
 * Deux objets distincts, nommés exactement :
 *   - « réservation » : appel au compteur de quota (reserver_demande()).
 *   - « demande »     : POST multipart HTTP RÉEL sur /tuc/v1/restaurant-applications,
 *                       à travers le serveur web, avec une photo.
 * Le test « dispatch interne » (rest_do_request) est un TROISIÈME objet,
 * volontairement séparé : il ne prouve rien sur l'upload HTTP.
 *
 * ISOLATION. Ce script ne devine aucune clé : il crée lui-même ses adresses de
 * test (bloc réservé 198.51.100.0/24 + suffixe aléatoire par exécution), vérifie
 * qu'AUCUNE clé de ce bloc n'existe déjà, et REFUSE de continuer en cas de
 * collision. Une clé témoin préexistante est posée hors de son périmètre : elle
 * ne doit jamais être touchée.
 *
 * GARDE. Refus (code 3) sans : marqueur `.tuc-bac-jetable`, accord explicite
 * (--bac-jetable ou TUC_BAC_JETABLE=1), et URL de site en localhost/127.0.0.1.
 * Note honnête : wp-load.php est chargé AVANT le refus (il faut lire l'URL et
 * les options). Ses effets se limitent à des lectures et au cache d'options en
 * mémoire ; aucune écriture de ce script n'a lieu avant l'accord. Les effets
 * propres à WordPress au chargement (cron opportuniste, transients) ne sont pas
 * annulables : d'où l'exigence d'un environnement réellement jetable.
 *
 * Usage :
 *   php limiteur-resilience.php <wordpress> <url-http> [--bac-jetable]
 */
$wpRoot = $argv[1] ?? '';
$urlHttp = rtrim((string) ($argv[2] ?? ''), '/');
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "usage : php limiteur-resilience.php <wordpress> <url-http> [--bac-jetable]\n");
    exit(2);
}

// Analyse par DRAPEAUX, jamais par position : l'ordre des options ne doit pas
// pouvoir décaler les arguments (défaut constaté : le sous-processus recevait
// le mode à la place de l'IP).
$opts = [];
$argLibres = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--bac-jetable') {
        $opts['bac'] = true;
    } elseif (preg_match('/^--(mode|ip|scenario|url|journal)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        $argLibres[] = $a;
    }
}
$wpRoot = $argLibres[0] ?? $wpRoot;
$urlHttp = rtrim((string) ($argLibres[1] ?? $urlHttp), '/');
$accord = isset($opts['bac']) || getenv('TUC_BAC_JETABLE') === '1';
$marqueur = $wpRoot . '/.tuc-bac-jetable';
$marque = is_file($marqueur);

unset($wp);
require $wpRoot . '/wp-load.php';
global $wpdb;

$urlSite = strtolower((string) get_option('home', ''));
$hostSite = strtolower((string) parse_url($urlSite, PHP_URL_HOST));
$local = in_array($hostSite, ['127.0.0.1', 'localhost', '::1'], true);
$envType = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

if (!$accord || !$marque || !$local) {
    fwrite(STDERR, "REFUS D'EXÉCUTION — script destructif, bac jetable uniquement.\n");
    fwrite(STDERR, '  site          : ' . ($urlSite !== '' ? $urlSite : '(inconnu)') . "\n");
    fwrite(STDERR, '  hôte local    : ' . ($local ? 'oui' : 'NON') . "\n");
    fwrite(STDERR, '  environnement : ' . $envType . ' (informatif : vaut « production » par défaut)\n');
    fwrite(STDERR, '  marqueur      : ' . $marqueur . ' => ' . ($marque ? 'présent' : 'ABSENT') . "\n");
    fwrite(STDERR, '  accord        : ' . ($accord ? 'oui' : 'NON (--bac-jetable ou TUC_BAC_JETABLE=1)') . "\n");
    fwrite(STDERR, "Aucune écriture effectuée par ce script.\n");
    exit(3);
}

// -------------------------------------------------------------------------
// Identifiants ISOLÉS : bloc réservé aux tests + suffixe tiré au hasard.
// Aucune clé n'est calculée « au hasard de l'IP » : elles sont toutes dérivées
// de ce suffixe, donc aucune ne peut appartenir à un site réel.
// -------------------------------------------------------------------------
$suffixe = substr(bin2hex(random_bytes(6)), 0, 10);
$plage = '198.51.100.'; // RFC 5737, réservée à la documentation
$crees = [];
$ecrireIdentifiants = static function (string $ip): array {
    return [
        'tuc_demandes_' . md5($ip),
        'tuc_demandes_' . md5($ip) . '_verrou',
        'tuc_tentatives_' . md5($ip),
        'tuc_tentatives_' . md5($ip) . '_verrou',
    ];
};
// Trois blocs RFC 5737 (documentation) : aucun trafic réel ne peut en venir.
$blocs = ['198.51.100.', '192.0.2.', '203.0.113.'];
// Compteur GLOBAL strictement croissant : deux appels ne peuvent pas produire
// la même adresse (défaut constaté : ipDe(400) et ipDe(1000) tombaient sur
// 203.0.113.20 tous les deux, d'où une « collision » en section 3).
$compteurIp = 0;
$ipDe = static function (int $n = 0) use ($blocs, &$compteurIp): string {
    $compteurIp++;
    $bloc = $blocs[$compteurIp % 3];
    $position = intdiv($compteurIp, 3) % 200;
    return $bloc . ($position + 20); // jamais .0/.255
};

/**
 * Déclare un lot d'IP : vérifie d'abord qu'AUCUNE clé n'existe déjà, et refuse
 * en cas de collision. Aucune clé préexistante n'est jamais supprimée.
 */
$declarerLot = static function (array $ips) use ($wpdb, $ecrireIdentifiants, &$crees): bool {
    $toutes = [];
    foreach ($ips as $ip) {
        foreach ($ecrireIdentifiants($ip) as $cle) {
            $toutes[] = $cle;
        }
    }
    if ($toutes === []) {
        return true;
    }
    $existantes = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name IN (" . implode(',', array_fill(0, count($toutes), '%s')) . ')',
        ...$toutes
    ));
    if ($existantes > 0) {
        return false; // collision : on ne touche à rien
    }
    foreach ($toutes as $cle) {
        $crees[] = $cle;
    }
    return true;
};

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? '  OK    ' : '  ECHEC ') . "— $m\n";
    if (!$c) {
        $ko++;
    }
};

// Nettoyage : uniquement les clés déclarées, jamais un motif LIKE.
register_shutdown_function(static function () use ($wpdb, &$crees): void {
    foreach ($crees as $cle) {
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $cle));
    }
});

$mode = (string) ($opts['mode'] ?? 'orchestrateur');
$sousProcessus = in_array($mode, ['reservation', 'demande-http'], true);
$ipSous = (string) ($opts['ip'] ?? '');
$scenarioSous = (string) ($opts['scenario'] ?? 'aucun');
$urlSous = rtrim((string) ($opts['url'] ?? $urlHttp), '/');

// -------------------------------------------------------------------------
// Effets à bord : journalisés dans un fichier pour être COMPTÉS, pas supposés.
// -------------------------------------------------------------------------
// Le sous-processus a son PROPRE suffixe aléatoire : le journal doit donc lui
// être transmis explicitement, sinon il écrit ailleurs et les compteurs
// d'événements tombent à zéro (défaut constaté).
$journal = (string) ($opts['journal'] ?? (sys_get_temp_dir() . '/tuc-res-' . $suffixe . '.log'));

if ($sousProcessus) {
    $ip = $ipSous;
    $scenario = $scenarioSous;
    $urlBase = $urlSous;
    $_SERVER['REMOTE_ADDR'] = $ip;

    // Le témoin est TOUJOURS posé (même sans injection) : sans lui, un deadlock
    // réel survenu pendant une rafale passe inaperçu et le compteur tombe à zéro.
    // Défaut constaté : 16 deadlocks côté serveur, 0 dans mon journal.
    {
        $deadlockVu = false;
        add_filter('query', static function (string $q) use (&$deadlockVu, $scenario, $journal): string {
            $err = (string) ($GLOBALS['wpdb']->last_error ?? '');
            if (stripos($err, 'Deadlock') !== false || stripos($err, 'try restarting') !== false) {
                $deadlockVu = true;
                @file_put_contents($journal, "deadlock\n", FILE_APPEND);
            }
            $ecriture = (stripos(ltrim($q), 'UPDATE') === 0 || stripos(ltrim($q), 'INSERT') === 0)
                && strpos($q, 'tuc_demandes_') !== false
                && strpos($q, '_verrou') === false;
            if (!$ecriture) {
                return $q;
            }
            // 'panne' : une seule injection par processus.
            // 'panne-apres-reprise' : injection UNIQUEMENT après un deadlock vu.
            if ($scenario === 'panne' || ($scenario === 'panne-apres-reprise' && $deadlockVu)) {
                $deadlockVu = false;
                @file_put_contents($journal, "injection\n", FILE_APPEND);
                return "UPDATE `{$GLOBALS['wpdb']->prefix}table_absente_du_test` SET option_value = 1";
            }
            return $q;
        }, 10, 1);
    }

    if ($mode === 'reservation') {
        $ref = new ReflectionMethod('TUC_Onboarding_Restaurant', 'reserver_demande');
        $ref->setAccessible(true);
        $r = $ref->invoke(null);
        echo is_wp_error($r) ? $r->get_error_code() : 'reserve', "\n";
        exit(0);
    }

    // ---- demande par HTTP MULTIPART RÉEL (pas rest_do_request) ------------
    // La panne est injectée CÔTÉ SERVEUR par un mu-plugin de bac piloté par le
    // fichier /tmp/tuc-injection.flag : un filtre posé dans ce processus CLI
    // n'atteindrait pas le processus qui sert la requête.
    $drapeauInjection = getenv('TUC_INJECTION_FLAG') ?: '/tmp/tuc-injection.flag';
    if ($scenario !== 'aucun' && is_writable(dirname($drapeauInjection))) {
        @file_put_contents($drapeauInjection, $scenario);
    } else {
        @unlink($drapeauInjection);
    }
    $jpg = sys_get_temp_dir() . '/tuc-photo-' . $suffixe . '.jpg';
    if (!is_file($jpg)) {
        $im = imagecreatetruecolor(320, 240);
        imagejpeg($im, $jpg, 80);
        imagedestroy($im);
    }
    $ch = curl_init($urlBase . '/wp-json/tuc/v1/restaurant-applications');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => [
            'restaurant_name' => 'Bac ' . $ip . ' ' . $suffixe,
            'menu_photo' => new CURLFile($jpg, 'image/jpeg', 'menu.jpg'),
        ],
    ]);
    $corps = (string) curl_exec($ch);
    $statut = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($corps, true);
    $code = is_array($json) && isset($json['code']) ? (string) $json['code'] : '-';
    echo $statut . ' ' . $code . "\n";
    exit(0);
}

// -------------------------------------------------------------------------
// Orchestrateur
// -------------------------------------------------------------------------
@unlink($journal);
$lancer = static function (array $ips, string $mode, string $scenario = 'aucun') use ($wpRoot, $urlHttp, $journal): array {
    $procs = [];
    foreach ($ips as $ip) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
            . escapeshellarg($wpRoot) . ' ' . escapeshellarg($urlHttp) . ' --bac-jetable '
            . escapeshellarg('--mode=' . $mode) . ' ' . escapeshellarg('--ip=' . $ip) . ' '
            . escapeshellarg('--scenario=' . $scenario) . ' ' . escapeshellarg('--url=' . $urlHttp)
            . ' ' . escapeshellarg('--journal=' . $journal)
            . ' 2>/dev/null';
        $procs[$ip] = popen($cmd, 'r');
    }
    $sortie = [];
    foreach ($procs as $ip => $h) {
        $sortie[$ip] = trim((string) stream_get_contents($h));
        pclose($h);
    }
    return $sortie;
};

/** État PAR IP : quota consommé, verrou résiduel, demande créée. */
$etatParIp = static function (array $ips) use ($wpdb, $ecrireIdentifiants): array {
    $etat = [];
    foreach ($ips as $ip) {
        $cles = $ecrireIdentifiants($ip);
        $lignes = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM `{$wpdb->options}` WHERE option_name IN (%s,%s,%s,%s)",
            ...$cles
        ), ARRAY_A);
        $quota = 0;
        $verrou = 0;
        foreach ((array) $lignes as $l) {
            if (substr((string) $l['option_name'], -7) === '_verrou') {
                $verrou++;
            } elseif (strpos((string) $l['option_name'], 'tuc_demandes_') === 0) {
                $quota = (int) explode('|', (string) $l['option_value'])[0];
            }
        }
        $etat[$ip] = ['quota' => $quota, 'verrou' => $verrou];
    }
    return $etat;
};

$compteEvenements = static function (string $motif) use ($journal): int {
    $total = 0;
    foreach ([$journal, '/tmp/tuc-injection.log'] as $f) {
        if (is_file($f)) {
            $total += substr_count((string) file_get_contents($f), $motif);
        }
    }
    return $total;
};
$compteDemandes = static function (): int {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tuc_demande_resto'");
};
$comptePieces = static function (): int {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
};

// =========================================================================
echo "=== 0. Gardes d'environnement : chaque condition est testée séparément ===\n";
$testerGarde = static function (array $args, ?string $env = null): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $sortie = [];
    $code = 0;
    $prefixe = $env === null ? '' : ('TUC_BAC_JETABLE=' . escapeshellarg($env) . ' ');
    exec($prefixe . $cmd, $sortie, $code);
    return [$code, implode(' ', $sortie)];
};
[$c0, $t0] = $testerGarde([$wpRoot, $urlHttp]);            // ni accord ni marqueur
$ok($c0 === 3 && strpos($t0, "REFUS D'EXÉCUTION") !== false, 'sans accord : refus code 3 (' . $c0 . ')');
[$c1, $t1] = $testerGarde([$wpRoot, $urlHttp], '0');       // accord explicite absent
$ok($c1 === 3, 'accord à 0 : refus (' . $c1 . ')');
// marqueurabsent : on le retire temporairement
$sauvegarde = is_file($marqueur) ? (string) file_get_contents($marqueur) : null;
if (is_file($marqueur)) {
    unlink($marqueur);
}
[$c2, $t2] = $testerGarde([$wpRoot, $urlHttp, '--bac-jetable']);
$ok($c2 === 3 && strpos($t2, 'marqueur') !== false, 'sans marqueur : refus malgré l’accord (' . $c2 . ')');
if ($sauvegarde !== null) {
    file_put_contents($marqueur, $sauvegarde);
    $ok(true, 'marqueur restauré après le test de garde');
}

// =========================================================================
echo "\n=== 0b. Clé TÉMOIN préexistante : elle doit survivre à tout le test ===\n";
$temoinCle = 'tuc_demandes_' . md5('tuc-temoin-preexistant');
$wpdb->query($wpdb->prepare(
    "INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
    $temoinCle,
    '7|' . (time() + 1800) . '|123456|9'
));
$crees[] = $temoinCle; // autorisé : c'est nous qui l'avons créée
$valeurTemoinAvant = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $temoinCle));

// Collision : on pose une clé du lot d'IP du test, hors déclaration.
$ipCollision = $ipDe(0);
$cleCollision = $ecrireIdentifiants($ipCollision)[0];
$wpdb->query($wpdb->prepare(
    "INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
    $cleCollision,
    '5|' . (time() + 1800) . '|999|9'
));
$collisionDetectee = !$declarerLot([$ipCollision]);
$ok($collisionDetectee, 'une clé préexistante dans le lot est DÉTECTÉE et fait refuser le lot');
$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $cleCollision));

// =========================================================================
echo "\n=== 1. RÉSERVATIONS isolées : 96 IP distinctes (aucune contention attendue) ===\n";
$acceptes = 0;
$refus = [];
$toutesIps = [];
$NB_RAFALES_1 = 8; // mesuré : à 4 rafales, le deadlock n'est pas systématique
for ($rafale = 1; $rafale <= $NB_RAFALES_1; $rafale++) {
    $ips = [];
    for ($i = 1; $i <= 12; $i++) {
        $ips[] = $ipDe(($rafale - 1) * 12 + $i);
    }
    if (!$declarerLot($ips)) {
        $ok(false, 'collision inattendue sur le lot ' . $rafale);
        break;
    }
    $toutesIps = array_merge($toutesIps, $ips);
    foreach ($lancer($ips, 'reservation') as $r) {
        if ($r === 'reserve') {
            $acceptes++;
        } else {
            $refus[$r] = ($refus[$r] ?? 0) + 1;
        }
    }
}
$etat1 = $etatParIp($toutesIps);
$attendu1 = $NB_RAFALES_1 * 12;
$ok($acceptes === $attendu1, sprintf('%d réservations acceptées sur %d', $acceptes, $attendu1));
$ok(max(array_map(static fn ($e) => $e['quota'], $etat1)) <= 1, 'aucune IP au-dessus de 1 unité de quota');
$ok(count(array_filter($etat1, static fn ($e) => $e['verrou'] > 0)) === 0, 'aucun verrou résiduel, par IP');

// =========================================================================
echo "\n=== 1b. CONTENTION RÉELLE : 144 réservations concurrentes — deadlock EXIGÉ ===\n";
// Constat mesuré : des IP DISTINCTES lancées par petits lots ne se croisent pas
// (96 réservations, 0 deadlock). La contention vient du NOMBRE de processus
// simultanés sur la même table, pas du partage d'une clé — et une même IP est de
// toute façon plafonnée à 3 par heure, ce qui coupe la contention.
// Ici : 12 rafales de 12 IP distinctes, sans injection, exactement la
// configuration qui a produit 21 deadlocks réels.
@unlink($journal);
@unlink('/tmp/tuc-injection.log');
$avantDeadlocks = (int) $wpdb->get_var("SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'INNODB_DEADLOCKS'");
$ipsContention = [];
$acceptesContention = 0;
$refusContention = [];
for ($rafale = 1; $rafale <= 12; $rafale++) {
    $lot = [];
    for ($i = 1; $i <= 12; $i++) {
        $lot[] = $ipDe(1000 + ($rafale - 1) * 12 + $i);
    }
    if (!$declarerLot($lot)) {
        $ok(false, 'collision inattendue sur le lot ' . $rafale);
        break;
    }
    $ipsContention = array_merge($ipsContention, $lot);
    foreach ($lancer($lot, 'reservation') as $r) {
        if ($r === 'reserve') {
            $acceptesContention++;
        } else {
            $refusContention[$r] = ($refusContention[$r] ?? 0) + 1;
        }
    }
}
// La variable globale n'est pas exposée par cette MariaDB : on retombe sur le
// témoin du journal, rempli par le filtre quand une erreur « deadlock » est vue.
$deadlocksContention = $compteEvenements('deadlock');
$etatContention = $etatParIp($ipsContention);
$auDessusContention = array_filter($etatContention, static fn ($e) => $e['quota'] > 1);
$ok($deadlocksContention > 0, 'au moins un DEADLOCK RÉEL observé : ' . $deadlocksContention . ' (échoue si 0)');
$ok($acceptesContention === 144, 'toutes les réservations acceptées : ' . $acceptesContention . '/144');
$ok($auDessusContention === [], 'AUCUNE IP au-dessus de 1 unité (aucune double consommation) : ' . count($auDessusContention) . ' anomalie(s)');
$ok(count(array_filter($etatContention, static fn ($e) => $e['verrou'] > 0)) === 0, 'aucun verrou résiduel');
echo '    refus observés : ' . json_encode($refusContention) . "\n";

// =========================================================================
echo "\n=== 2. RÉSERVATION, panne non récupérable : refus, rien de consommé ===\n";
@unlink($journal);
$ips2 = [$ipDe(300)];
$declarerLot($ips2);
$r2 = $lancer($ips2, 'reservation', 'panne')[$ips2[0]] ?? '?';
$ok($r2 === 'tuc_onboarding_storage', 'vraie panne => tuc_onboarding_storage : ' . $r2);
$ok($compteEvenements('injection') >= 1, 'au moins une injection a bien eu lieu (' . $compteEvenements('injection') . ')');
$etat2 = $etatParIp($ips2);
$ok(($etat2[$ips2[0]]['quota'] ?? 0) === 0, 'aucune unité de quota consommée par ce refus');
$ok(($etat2[$ips2[0]]['verrou'] ?? 0) === 0, 'aucun verrou résiduel');

// =========================================================================
echo "\n=== 3. RÉSERVATION, injection APRÈS reprise : événements EXIGÉS ===\n";
@unlink($journal);
$acceptes3 = 0;
$refus3 = [];
$ips3 = [];
for ($rafale = 1; $rafale <= 12; $rafale++) {
    $lot = [];
    for ($i = 1; $i <= 12; $i++) {
        $lot[] = $ipDe(400 + ($rafale - 1) * 12 + $i);
    }
    if (!$declarerLot($lot)) {
        $ok(false, 'collision inattendue sur le lot ' . $rafale);
        break;
    }
    $ips3 = array_merge($ips3, $lot);
    foreach ($lancer($lot, 'reservation', 'panne-apres-reprise') as $r) {
        if ($r === 'reserve') {
            $acceptes3++;
        } else {
            $refus3[$r] = ($refus3[$r] ?? 0) + 1;
        }
    }
}
$deadlocks3 = $compteEvenements('deadlock');
$injections3 = $compteEvenements('injection');
$ok($deadlocks3 > 0, 'deadlocks RÉELS observés : ' . $deadlocks3 . ' (échoue si 0)');
$ok($injections3 > 0, 'injections APRÈS reprise réellement déclenchées : ' . $injections3 . ' (échoue si 0)');
$etat3 = $etatParIp($ips3);
$auDessus = array_filter($etat3, static fn ($e) => $e['quota'] > 1);
$ok($auDessus === [], 'AUCUNE IP au-dessus de 1 unité de quota (' . count($auDessus) . ' anomalie(s))');
$somme3 = array_sum(array_map(static fn ($e) => $e['quota'], $etat3));
$ok($somme3 === $acceptes3, "unités de quota = réservations acceptées ($somme3 = $acceptes3)");
$ok(count(array_filter($etat3, static fn ($e) => $e['verrou'] > 0)) === 0, 'aucun verrou résiduel, par IP');
echo '    refus observés : ' . json_encode($refus3) . "\n";

// =========================================================================
echo "\n=== 4. RÉSERVATION, quota même IP : 3 acceptées puis refus ===\n";
$ips4 = [$ipDe(700)];
$declarerLot($ips4);
$t4 = [];
for ($k = 0; $k < 6; $k++) {
    $t4[] = $lancer($ips4, 'reservation')[$ips4[0]] ?? '?';
}
$ok(count(array_keys($t4, 'reserve', true)) === 3, '3 acceptées (' . count(array_keys($t4, 'reserve', true)) . ')');
$ok(count(array_keys($t4, 'tuc_onboarding_quota', true)) === 3, 'puis 3 refus de quota (' . count(array_keys($t4, 'tuc_onboarding_quota', true)) . ')');
$ok(($etatParIp($ips4)[$ips4[0]]['quota'] ?? 0) === 3, 'quota final de cette IP = 3');

// =========================================================================
echo "\n=== 5. DEMANDE par HTTP MULTIPART RÉEL (serveur web, pas rest_do_request) ===\n";
// PORTÉE EXACTE, et ses limites :
//   - ce qui est traversé : curl -> serveur web -> WordPress -> route REST -> module,
//     avec un vrai corps multipart et une vraie photo ;
//   - ce qui n'est PAS prouvé ici : le deadlock. Les requêtes HTTP séquentielles
//     ne se croisent pas, donc aucun verrou ne s'affronte. La preuve du deadlock
//     est faite au scénario 3, sur la MÊME section critique (c'est le même code).
// L'IP vue par le serveur est celle du client local : on remet donc à zéro le
// seul compteur de TENTATIVES entre deux requêtes (documenté, et le compteur de
// demandes n'est jamais touché) pour ne pas buter sur la limite anti-abus.
@unlink($journal);
@unlink('/tmp/tuc-injection.log');
global $wpdb;
$ipHttp = $ipDe(800);
$declarerLot([$ipHttp]);
// Le serveur web local voit le client comme 127.0.0.1 : c'est CETTE adresse que
// le module limite (5 tentatives par heure). Le script ne supprime AUCUNE de ces
// clés — ce serait exactement le défaut reproché. Il vérifie à la place que le
// compteur est vierge et refuse de conclure sur un bac déjà utilisé.
$cleDemandesLoopback = 'tuc_demandes_' . md5('127.0.0.1');
$cleTentativesLoopback = 'tuc_tentatives_' . md5('127.0.0.1');
$dejaUtilise = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name IN (%s,%s,%s,%s)",
    $cleDemandesLoopback,
    $cleDemandesLoopback . '_verrou',
    $cleTentativesLoopback,
    $cleTentativesLoopback . '_verrou'
));
$ok($dejaUtilise === 0, 'bac frais : compteurs (quota + anti-abus) de 127.0.0.1 vierges (' . $dejaUtilise . ' clé(s))');
if ($dejaUtilise > 0) {
    echo "    -> le scénario HTTP est SAUTÉ, pas contourné : un bac neuf est requis.\n";
}
$idMaxAvant = (int) $wpdb->get_var("SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts}");
$demandesAvant = $compteDemandes();

$requete = static function (string $scenario) use ($lancer, $ipHttp): string {
    return $lancer([$ipHttp], 'demande-http', $scenario)[$ipHttp] ?? '?';
};

// a) deux demandes valides : 201, quota +1 chacune, photo réelle.
$r1 = $requete('aucun');
$r2 = $requete('aucun');
$ok(strpos($r1, '201') === 0, 'demande HTTP réelle acceptée : ' . $r1);
$ok(strpos($r2, '201') === 0, 'deuxième demande HTTP réelle acceptée : ' . $r2);
$quotaLoopbackApres2 = (int) explode('|', (string) $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
    $cleDemandesLoopback
)))[0];
$ok($quotaLoopbackApres2 === 2, 'quota vu par le serveur = 2 après deux demandes (' . $quotaLoopbackApres2 . ')');
$ok($compteDemandes() - $demandesAvant === 2, 'deux demandes réellement créées (' . ($compteDemandes() - $demandesAvant) . ')');

// b) panne SQL injectée sur le chemin HTTP : refus propre, rien d'orphelin.
@unlink('/tmp/tuc-injection.log');
$rPanne = $requete('panne');
$ok(strpos($rPanne, '503') === 0, 'panne injectée sur le chemin HTTP => 503 : ' . $rPanne);
$ok($compteEvenements('injection') >= 1, 'l’injection a réellement eu lieu (' . $compteEvenements('injection') . ')');
$ok($compteDemandes() - $demandesAvant === 2, 'AUCUNE demande créée par la panne (' . ($compteDemandes() - $demandesAvant) . ')');
$quotaApresPanne = (int) explode('|', (string) $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
    $cleDemandesLoopback
)))[0];
$ok($quotaApresPanne === 2, 'le quota N’A PAS bougé après la panne (' . $quotaApresPanne . ')');
$verrouLoopback = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name = %s",
    $cleDemandesLoopback . '_verrou'
));
$ok($verrouLoopback === 0, 'aucun verrou résiduel après la panne');

// c) le service repart, et la photo est réellement stockée.
$r3 = $requete('aucun');
$ok(strpos($r3, '201') === 0, 'après l’incident, une demande HTTP valide repart : ' . $r3);
$ok($compteDemandes() - $demandesAvant === 3, 'trois demandes créées au total (' . ($compteDemandes() - $demandesAvant) . ')');
// Le module limite par IP RÉELLE vue par le serveur (127.0.0.1), pas par l'IP
// symbolique passée au sous-processus : c'est donc la clé loopback qui porte le
// quota. Vérifié explicitement plutôt que supposé.
$quotaLoopback = (int) explode('|', (string) $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
    $cleDemandesLoopback
)))[0];
$ok($quotaLoopback === 3, 'quota réellement consommé côté serveur (127.0.0.1) = 3 (' . $quotaLoopback . ')');

$ids = (array) $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d",
    $idMaxAvant
));
$manquants = 0;
$mauvaisType = 0;
foreach ($ids as $idPiece) {
    $chemin = get_attached_file((int) $idPiece);
    if (!$chemin || !is_file($chemin) || filesize($chemin) === 0) {
        $manquants++;
    } elseif (get_post_mime_type((int) $idPiece) !== 'image/jpeg') {
        $mauvaisType++;
    }
}
$ok(count($ids) === 3, 'trois photos réellement stockées (' . count($ids) . '), aucune pour la panne');
$ok($manquants === 0, 'chaque photo a un fichier non vide sur le disque (' . $manquants . ' manquant(s))');
$ok($mauvaisType === 0, 'chaque photo stockée est un image/jpeg (' . $mauvaisType . ' anomalie(s))');

// =========================================================================
@unlink('/tmp/tuc-injection.flag');

echo "\n=== 6. Clé témoin préexistante : intacte après tout le test ===\n";
$valeurTemoinApres = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $temoinCle));
$ok($valeurTemoinApres === $valeurTemoinAvant, 'la clé témoin hors périmètre est INCHANGÉE (' . $valeurTemoinAvant . ')');

echo "\n=== Bilan ===\n";
echo 'bilan : ' . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
@unlink($journal);
exit($ko === 0 ? 0 : 1);
