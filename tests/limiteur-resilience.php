<?php
/**
 * REVUE N°16 — RÉSILIENCE DU LIMITEUR ET DE LA ROUTE COMPLÈTE.
 *
 * À exécuter dans un bac JETABLE uniquement. Ce script REFUSE de tourner
 * ailleurs (voir la garde en tête de fichier) et ne nettoie QUE les clés qu'il
 * a lui-même créées : aucun DELETE sur `tuc_demandes_%` ni `tuc_tentatives_%`.
 *
 * Vocabulaire, volontairement précis :
 *   - « réservation » = appel direct à reserver_demande(), c'est-à-dire le
 *     COMPTEUR de quota. Une réservation acceptée n'est PAS une demande créée.
 *   - « demande » = requête REST complète sur /tuc/v1/restaurant-applications,
 *     avec photo : là seulement on peut parler de quota, de demande, de pièce
 *     jointe et de verrous cohérents.
 *
 * Usage : php limiteur-resilience.php <chemin-wordpress> [--bac-jetable]
 *         (la variable d'environnement TUC_BAC_JETABLE=1 vaut accord explicite)
 */
$wpRoot = $argv[1] ?? '';
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "usage : php limiteur-resilience.php <wordpress> [--bac-jetable]\n");
    exit(2);
}

// -------------------------------------------------------------------------
// GARDE D'ENVIRONNEMENT — trois verrous, tous nécessaires.
// -------------------------------------------------------------------------
$accord = in_array('--bac-jetable', $argv, true)
    || getenv('TUC_BAC_JETABLE') === '1';

// 1. Le bac doit être marqué : fichier présent dans la racine WordPress.
$marqueur = $wpRoot . '/.tuc-bac-jetable';
$marque = is_file($marqueur);

// 2. Refus explicite si cela ressemble à dev/UAT/production.
$interdits = ['tuc.ma', 'hostingersite.com', 'chocolate-gazelle'];
unset($wp);
require $wpRoot . '/wp-load.php';
$urlSite = strtolower((string) get_option('home', ''));
$hostSite = strtolower((string) parse_url($urlSite, PHP_URL_HOST));
$estProtege = false;
foreach ($interdits as $motif) {
    if ($hostSite !== '' && strpos($hostSite, $motif) !== false) {
        $estProtege = true;
    }
}

// 3. L'environnement déclaré ne doit pas être production (si WordPress l'expose).
//    Note : un WordPress sans WP_ENVIRONMENT_TYPE déclare « production » par
//    défaut, même dans un bac. Ce n'est donc PAS un critère suffisant pour
//    refuser : il est affiché pour l'humain, et ce sont le marqueur + l'accord
//    explicite + la liste de domaines protégés qui décident.
$envType = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

if ($estProtege || !$marque || !$accord) {
    fwrite(STDERR, "REFUS D'EXÉCUTION — ce script est destructif, bac jetable uniquement.\n");
    fwrite(STDERR, '  site          : ' . ($urlSite !== '' ? $urlSite : '(inconnu)') . "\n");
    fwrite(STDERR, '  environnement : ' . $envType . "\n");
    fwrite(STDERR, '  marqueur      : ' . $marqueur . ' => ' . ($marque ? 'présent' : 'ABSENT') . "\n");
    fwrite(STDERR, '  accord        : ' . ($accord ? 'oui' : 'NON (--bac-jetable ou TUC_BAC_JETABLE=1)') . "\n");
    fwrite(STDERR, "Aucune écriture n'a été faite.\n");
    exit(3);
}

global $wpdb;
$prefixe = 'tuc_res_test_' . substr(md5((string) mt_rand()), 0, 8) . '_';
$crees = [];        // clés EXACTES créées par ce script
$ipBase = null;     // adresses utilisées par ce script

$nettoyer = static function () use ($wpdb, &$crees): void {
    foreach ($crees as $cle) {
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $cle));
    }
    $crees = [];
};
register_shutdown_function($nettoyer);

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? '  OK    ' : '  ECHEC ') . "— $m\n";
    if (!$c) {
        $ko++;
    }
};

// -------------------------------------------------------------------------
// Sous-processus : une UNITÉ DE TRAVAIL, avec ou sans injection d'erreur.
//   mode 'reservation' : appelle reserver_demande() (le COMPTEUR seulement)
//   mode 'demande'     : envoie une requête REST COMPLÈTE avec photo
// L'IP est fournie par l'orchestrateur : aucune clé n'est devinée.
// -------------------------------------------------------------------------
$mode = $argv[2] ?? 'orchestrateur';
$sousProcessus = in_array($mode, ['reservation', 'demande'], true);

if ($sousProcessus) {
    $ip = (string) ($argv[3] ?? '');
    $scenario = (string) ($argv[4] ?? 'aucun');
    $urlBase = (string) ($argv[5] ?? '');
    $_SERVER['REMOTE_ADDR'] = $ip;

    if ($scenario !== 'aucun') {
        $deadlockVu = false;
        add_filter('query', static function (string $q) use (&$deadlockVu, $scenario): string {
            $err = (string) ($GLOBALS['wpdb']->last_error ?? '');
            if (stripos($err, 'Deadlock') !== false || stripos($err, 'try restarting') !== false) {
                $deadlockVu = true;
            }
            // On ne cible QUE les écritures du compteur de demandes, jamais les verrous.
            $ecriture = (stripos(ltrim($q), 'UPDATE') === 0 || stripos(ltrim($q), 'INSERT') === 0)
                && strpos($q, 'tuc_demandes_') !== false
                && strpos($q, '_verrou') === false;
            if (!$ecriture) {
                return $q;
            }
            if ($scenario === 'panne' || ($scenario === 'panne-apres-reprise' && $deadlockVu)) {
                $deadlockVu = false;
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

    // Requête REST COMPLÈTE : photo réelle + nom.
    $photo = sys_get_temp_dir() . '/res-photo-' . wp_generate_password(6, false) . '.jpg';
    $im = imagecreatetruecolor(320, 240);
    imagejpeg($im, $photo, 80);
    imagedestroy($im);
    $r = new WP_REST_Request('POST', '/tuc/v1/restaurant-applications');
    $r->set_param('restaurant_name', 'Bac ' . substr(md5($ip . $urlBase), 0, 6));
    $r->set_file_params(['menu_photo' => [
        'tmp_name' => $photo, 'name' => 'menu.jpg',
        'size' => filesize($photo), 'error' => UPLOAD_ERR_OK,
    ]]);
    $res = rest_do_request($r);
    $corps = $res->get_data();
    $code = is_array($corps) && isset($corps['code']) ? (string) $corps['code'] : '';
    echo $res->get_status(), ' ', $code !== '' ? $code : '-', "\n";
    @unlink($photo);
    exit(0);
}

// -------------------------------------------------------------------------
// Orchestrateur
// -------------------------------------------------------------------------
$urlBase = (string) get_option('home', '');

/** Déclare les clés de quota d'une IP : c'est CE que le script a le droit d'effacer. */
$declarerIp = static function (string $ip) use ($wpdb, &$crees, &$ipBase): void {
    $cle = 'tuc_demandes_' . md5($ip);
    foreach ([$cle, $cle . '_verrou', 'tuc_tentatives_' . md5($ip), 'tuc_tentatives_' . md5($ip) . '_verrou'] as $c) {
        $crees[] = $c;
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $c));
    }
};

$lancer = static function (array $ips, string $mode, string $scenario = 'aucun') use ($wpRoot, $urlBase): array {
    $procs = [];
    foreach ($ips as $ip) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
            . escapeshellarg($wpRoot) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($ip)
            . ' ' . escapeshellarg($scenario) . ' ' . escapeshellarg($urlBase) . ' --bac-jetable 2>/dev/null';
        $procs[$ip] = popen($cmd, 'r');
    }
    $sortie = [];
    foreach ($procs as $ip => $h) {
        $sortie[$ip] = trim((string) stream_get_contents($h));
        pclose($h);
    }
    return $sortie;
};

/** Compteurs de quota, restreints aux clés déclarées par ce script. */
$compteurs = static function () use ($wpdb, &$crees): array {
    $cles = array_values(array_filter($crees, static fn ($c) => strpos($c, 'tuc_demandes_') === 0 && substr($c, -7) !== '_verrou'));
    if ($cles === []) {
        return [];
    }
    $lignes = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name IN (" . implode(',', array_fill(0, count($cles), '%s')) . ')',
            ...$cles
        ),
        ARRAY_A
    );
    $parCompte = [];
    foreach ((array) $lignes as $l) {
        $n = (int) explode('|', (string) $l['option_value'])[0];
        $parCompte[$n] = ($parCompte[$n] ?? 0) + 1;
    }
    ksort($parCompte);
    return $parCompte;
};

$verrous = static function () use ($wpdb, &$crees): int {
    $cles = array_values(array_filter($crees, static fn ($c) => substr($c, -7) === '_verrou'));
    if ($cles === []) {
        return 0;
    }
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name IN (" . implode(',', array_fill(0, count($cles), '%s')) . ')',
            ...$cles
        )
    );
};

// =========================================================================
$nettoyer(); // chaque section repart de zéro : rien d’hérité de la précédente
echo "=== 1. RÉSERVATIONS (compteur seul) : deadlock réel + concurrence multi-IP ===\n";
// Vocabulaire : ces « acceptées » sont des RÉSERVATIONS de quota, pas des demandes.
$acceptes = 0;
$refus = [];
for ($rafale = 1; $rafale <= 4; $rafale++) {
    $ips = [];
    for ($i = 1; $i <= 12; $i++) {
        $ip = "198.18.$rafale.$i";
        $declarerIp($ip);
        $ips[] = $ip;
    }
    foreach ($lancer($ips, 'reservation') as $r) {
        if ($r === 'reserve') {
            $acceptes++;
        } else {
            $refus[$r] = ($refus[$r] ?? 0) + 1;
        }
    }
}
$ok($acceptes === 48, sprintf('%d réservations acceptées sur 48', $acceptes));
$ok($compteurs() === [1 => 48], 'un compteur par IP, tous à 1 : ' . json_encode($compteurs()));
$ok($verrous() === 0, 'aucun verrou orphelin (' . $verrous() . ')');

// =========================================================================
$nettoyer(); // chaque section repart de zéro : rien d’hérité de la précédente
echo "\n=== 2. RÉSERVATION, panne SQL non récupérable : refus propre, rien de consommé ===\n";
$ip2 = '198.19.0.1';
$declarerIp($ip2);
$r2 = $lancer([$ip2], 'reservation', 'panne')[$ip2] ?? '?';
$ok($r2 === 'tuc_onboarding_storage', 'vraie panne => 503 tuc_onboarding_storage (pas un conflit) : ' . $r2);
$ok($compteurs() === [], 'aucun compteur créé par ce refus : ' . json_encode($compteurs()));
$ok($verrous() === 0, 'verrou rendu (' . $verrous() . ')');

// =========================================================================
$nettoyer(); // chaque section repart de zéro : rien d’hérité de la précédente
echo "\n=== 3. RÉSERVATION, panne injectée APRÈS une reprise : aucun état partiel ===\n";
$acceptes3 = 0;
$refus3 = [];
for ($rafale = 1; $rafale <= 12; $rafale++) {
    $ips = [];
    for ($i = 1; $i <= 12; $i++) {
        $ip = "198.20.$rafale.$i";
        $declarerIp($ip);
        $ips[] = $ip;
    }
    foreach ($lancer($ips, 'reservation', 'panne-apres-reprise') as $r) {
        if ($r === 'reserve') {
            $acceptes3++;
        } else {
            $refus3[$r] = ($refus3[$r] ?? 0) + 1;
        }
    }
}
$c3 = $compteurs();
$somme3 = array_sum(array_map(static fn ($n, $k) => $n * $k, $c3, array_keys($c3)));
$ok(count($c3) <= 1 || max(array_keys($c3)) === 1, 'aucun compteur au-dessus de 1 : ' . json_encode($c3));
$ok($somme3 === $acceptes3, "unités de quota = réservations acceptées ($somme3 = $acceptes3)");
$ok($verrous() === 0, 'aucun verrou orphelin (' . $verrous() . ')');
echo '    refus observés : ' . json_encode($refus3) . "\n";

// =========================================================================
$nettoyer(); // chaque section repart de zéro : rien d’hérité de la précédente
echo "\n=== 4. RÉSERVATION, quota même IP : 3 acceptées puis refus ===\n";
$ip4 = '198.22.0.1';
$declarerIp($ip4);
$t4 = [];
for ($k = 0; $k < 6; $k++) {
    $t4[] = $lancer([$ip4], 'reservation')[$ip4] ?? '?';
}
$ok(count(array_keys($t4, 'reserve', true)) === 3, '3 réservations acceptées (' . count(array_keys($t4, 'reserve', true)) . ')');
$ok(count(array_keys($t4, 'tuc_onboarding_quota', true)) === 3, 'puis 3 refus de quota (' . count(array_keys($t4, 'tuc_onboarding_quota', true)) . ')');

// =========================================================================
$nettoyer(); // chaque section repart de zéro : rien d’hérité de la précédente
echo "\n=== 5. DEMANDE COMPLÈTE (route REST + photo) : panne APRÈS reprise ===\n";
// C'est ici seulement qu'on peut parler de demande, de photo et de cohérence.
$compteDemandes = static function (): int {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tuc_demande_resto'");
};
$comptePieces = static function (): int {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
};
$compteTelecharges = static function (): int {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE '%tuc_onboarding_menu_photo%'");
};

global $wpdb;
$demandesAvant = $compteDemandes();
$piecesAvant = $comptePieces();
// Repère ROBUSTE : l'ID maximal, pas le nombre. Un simple comptage ramasserait
// les pièces jointes laissées par les exécutions précédentes du bac.
$idPieceMaxAvant = (int) $wpdb->get_var("SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts}");
$acceptees5 = 0;
$refus5 = [];
$codesHttp = [];
for ($rafale = 1; $rafale <= 6; $rafale++) {
    $ips = [];
    for ($i = 1; $i <= 12; $i++) {
        $ip = "198.23.$rafale.$i";
        $declarerIp($ip);
        $ips[] = $ip;
    }
    foreach ($lancer($ips, 'demande', 'panne-apres-reprise') as $ip => $r) {
        // Sortie : « <statut> <reference|-> ». On ne compte que le statut.
        $morceaux = preg_split('/\s+/', trim($r));
        $statut = (string) ($morceaux[0] ?? '?');
        $motif = $statut === '201' ? '201' : $statut . ' ' . (string) ($morceaux[1] ?? '-');
        $codesHttp[$motif] = ($codesHttp[$motif] ?? 0) + 1;
        if ($statut === '201') {
            $acceptees5++;
        } else {
            $refus5[$motif] = ($refus5[$motif] ?? 0) + 1;
        }
    }
}
$demandesApres = $compteDemandes();
$piecesApres = $comptePieces();
$creees = $demandesApres - $demandesAvant;
$piecesCreees = $piecesApres - $piecesAvant;
$c5 = $compteurs();
$somme5 = array_sum(array_map(static fn ($n, $k) => $n * $k, $c5, array_keys($c5)));

$ok($creees === $acceptees5, "demandes créées = réponses 201 ($creees = $acceptees5)");
$ok($piecesCreees === $acceptees5, "pièces jointes créées = réponses 201 ($piecesCreees = $acceptees5)");
$ok($somme5 === $acceptees5, "unités de quota consommées = demandes acceptées ($somme5 = $acceptees5)");
$ok($verrous() === 0, 'aucun verrou orphelin (' . $verrous() . ')');
// La photo doit être RÉELLEMENT sur le disque : une pièce jointe sans fichier
// passerait un simple comptage. On vérifie donc le fichier des pièces créées
// pendant ce scénario, et son type.
$piecesDuTest = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d",
    $idPieceMaxAvant
));
$fichiersManquants = 0;
$mauvaisType = 0;
$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d",
    $idPieceMaxAvant
));
foreach ((array) $ids as $idPiece) {
    $chemin = get_attached_file((int) $idPiece);
    if (!$chemin || !is_file($chemin) || filesize($chemin) === 0) {
        $fichiersManquants++;
        continue;
    }
    if (get_post_mime_type((int) $idPiece) !== 'image/jpeg') {
        $mauvaisType++;
    }
}
$ok($piecesDuTest === $acceptees5, "pièces du scénario inspectées ($piecesDuTest)");
$ok($fichiersManquants === 0, "chaque pièce a un fichier non vide sur le disque ($fichiersManquants manquant(s))");
$ok($mauvaisType === 0, "chaque pièce stockée est bien un image/jpeg ($mauvaisType anomalie(s))");
echo '    codes HTTP observés : ' . json_encode($codesHttp) . "\n";

$nettoyer(); // chaque section repart de zéro : rien d’hérité de la précédente
echo "\n=== 5b. La route repart après l'incident : une soumission valide réussit ===\n";
$ip5b = '198.24.0.1';
$declarerIp($ip5b);
$avant5b = $compteDemandes();
$r5b = $lancer([$ip5b], 'demande')[$ip5b] ?? '?';
$apres5b = $compteDemandes();
$ok(strpos($r5b, '201') === 0, 'soumission valide acceptée après les incidents : ' . $r5b);
$ok($apres5b === $avant5b + 1, 'une demande réellement créée (' . ($apres5b - $avant5b) . ')');
$ok($verrous() === 0, 'aucun verrou laissé derrière (' . $verrous() . ')');

// =========================================================================
echo "\n=== 6. GARDE D'ENVIRONNEMENT : refus hors bac jetable ===\n";
$sortieGarde = [];
$codeGarde = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
    . escapeshellarg($wpRoot) . ' 2>&1',
    $sortieGarde,
    $codeGarde
);
$texteGarde = implode(' ', $sortieGarde);
$ok($codeGarde === 3 && strpos($texteGarde, "REFUS D'EXÉCUTION") !== false,
    'sans --bac-jetable : refus net, code 3 (' . $codeGarde . ')');

echo "\n=== Bilan ===\n";
echo 'bilan : ' . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
exit($ko === 0 ? 0 : 1);
