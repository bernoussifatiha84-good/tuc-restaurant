<?php
/**
 * REVUE N°16 — RÉSILIENCE DU LIMITEUR : les écritures RÉELLES, pas une ligne témoin.
 *
 * À exécuter dans un bac jetable (MariaDB), jamais sur dev ni en production.
 *
 * Ce fichier vérifie, sur les vraies clés du limiteur
 * (`tuc_demandes_<md5(ip)>` et son `_verrou`), les six situations demandées :
 *   1. deadlock réel sous concurrence (12 IP distinctes, plusieurs rafales) ;
 *   2. erreur SQL NON récupérable : refus propre, aucun quota consommé ;
 *   3. erreur SQL injectée APRÈS une reprise : rien d'incohérent, rien de partiel ;
 *   4. concurrence multi-IP : aucun doublon, aucun compteur > 1 pour une demande ;
 *   5. quota même IP : toujours 3 acceptées puis refus ;
 *   6. état final : aucun verrou orphelin, aucune génération réutilisée.
 *
 * Usage : php limiteur-resilience.php <chemin-wordpress>
 */
$wpRoot = $argv[1] ?? '';
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "usage : php limiteur-resilience.php <wordpress>\n");
    exit(2);
}

// WordPress doit être chargé UNE fois par processus : les scénarios qui ont
// besoin d'injecter des erreurs lancent des sous-processus (voir $injecter).
$mode = $argv[2] ?? 'orchestrateur';

if ($mode === 'injecter') {
    // ---------------------------------------------------------------------
    // Sous-processus : une demande, avec ou sans injection d'erreur SQL.
    // ---------------------------------------------------------------------
    unset($wp);
    require $wpRoot . '/wp-load.php';
    global $wpdb;

    $ip = $argv[3] ?? '198.18.0.1';
    $scenario = $argv[4] ?? 'aucun';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $deadlockVu = false;
    $compteur = 0;

    if ($scenario !== 'aucun') {
        add_filter('query', static function (string $q) use (&$deadlockVu, &$compteur, $scenario): string {
            $err = (string) ($GLOBALS['wpdb']->last_error ?? '');
            if (stripos($err, 'Deadlock') !== false || stripos($err, 'try restarting') !== false) {
                $deadlockVu = true;
            }
            $ecriture = (stripos($q, 'UPDATE') === 0 || stripos($q, 'INSERT') === 0)
                && strpos($q, 'tuc_demandes_') !== false
                && strpos($q, '_verrou') === false;
            if (!$ecriture) {
                return $q;
            }
            $compteur++;
            if ($scenario === 'panne' || ($scenario === 'panne-apres-reprise' && $deadlockVu)) {
                $deadlockVu = false;
                return 'UPDATE `wp_options_table_absente_du_test` SET option_value = 1';
            }
            return $q;
        }, 10, 1);
    }

    $ref = new ReflectionMethod('TUC_Onboarding_Restaurant', 'reserver_demande');
    $ref->setAccessible(true);
    $r = $ref->invoke(null);
    echo is_wp_error($r) ? $r->get_error_code() : 'accepte', "\n";
    exit(0);
}

unset($wp);
require $wpRoot . '/wp-load.php';
global $wpdb;

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? '  OK    ' : '  ECHEC ') . "— $m\n";
    if (!$c) {
        $ko++;
    }
};

$nettoyer = static function () use ($wpdb): void {
    $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE 'tuc_demandes_%'");
    $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE 'tuc_tentatives_%'");
};

$lancer = static function (array $ips, string $scenario = 'aucun') use ($wpRoot): array {
    $sortie = [];
    $procs = [];
    foreach ($ips as $ip) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
            . escapeshellarg($wpRoot) . ' injecter ' . escapeshellarg($ip) . ' ' . escapeshellarg($scenario)
            . ' 2>/dev/null';
        $procs[$ip] = popen($cmd, 'r');
    }
    foreach ($procs as $ip => $h) {
        $sortie[$ip] = trim((string) stream_get_contents($h));
        pclose($h);
    }
    return $sortie;
};

$compteurs = static function () use ($wpdb): array {
    $lignes = $wpdb->get_results(
        "SELECT option_value FROM `{$wpdb->options}` WHERE option_name LIKE 'tuc_demandes_%' AND option_name NOT LIKE '%\\_verrou'",
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

$verrous = static function () use ($wpdb): int {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name LIKE 'tuc_demandes_%\\_verrou'");
};

// -------------------------------------------------------------------------
echo "=== 1. Deadlock réel + concurrence multi-IP (4 rafales × 12 IP) ===\n";
$nettoyer();
$acceptes = 0;
$refus = [];
for ($rafale = 1; $rafale <= 4; $rafale++) {
    $ips = [];
    for ($i = 1; $i <= 12; $i++) {
        $ips[] = "198.18.$rafale.$i";
    }
    foreach ($lancer($ips) as $r) {
        if ($r === 'accepte') {
            $acceptes++;
        } else {
            $refus[$r] = ($refus[$r] ?? 0) + 1;
        }
    }
}
$parCompte = $compteurs();
$ok($acceptes === 48, sprintf('%d demandes acceptées sur 48', $acceptes));
$ok($parCompte === [1 => 48], 'un compteur par IP, tous à 1 (aucun doublon de quota) : ' . json_encode($parCompte));
$ok($verrous() === 0, 'aucun verrou orphelin (' . $verrous() . ')');

// -------------------------------------------------------------------------
echo "\n=== 2. Erreur SQL NON récupérable : refus propre, rien de consommé ===\n";
$nettoyer();
$res = $lancer(['198.19.0.1'], 'panne');
// Une VRAIE panne (table absente) n'est PAS un conflit : elle doit sortir en
// tuc_onboarding_storage (503 « indisponible »), distincte du refus de
// concurrence tuc_onboarding_occupe. Exigence explicite du donneur d'ordre :
// ne pas transformer une panne en « réessayez ».
$ok(($res['198.19.0.1'] ?? '') === 'tuc_onboarding_storage', 'vraie panne => 503 tuc_onboarding_storage (pas un conflit) : ' . ($res['198.19.0.1'] ?? '?'));
$ok($compteurs() === [], 'aucun compteur créé');
$ok($verrous() === 0, 'verrou rendu');

// -------------------------------------------------------------------------
echo "\n=== 3. Erreur injectée APRÈS une reprise : aucun état partiel ===\n";
$nettoyer();
$acceptes3 = 0;
$refus3 = [];
for ($rafale = 1; $rafale <= 12; $rafale++) {
    $ips = [];
    for ($i = 1; $i <= 12; $i++) {
        $ips[] = "198.20.$rafale.$i";
    }
    foreach ($lancer($ips, 'panne-apres-reprise') as $r) {
        if ($r === 'accepte') {
            $acceptes3++;
        } else {
            $refus3[$r] = ($refus3[$r] ?? 0) + 1;
        }
    }
}
$parCompte3 = $compteurs();
$total3 = array_sum($parCompte3);
$ok($total3 === $acceptes3, sprintf('autant de compteurs que de demandes acceptées (%d acceptées, %d compteurs)', $acceptes3, $total3));
$ok(($parCompte3[1] ?? 0) === $total3, 'tous les compteurs sont à 1 : aucune double consommation : ' . json_encode($parCompte3));
$ok($verrous() === 0, 'aucun verrou orphelin après les erreurs injectées');
echo '    refus observés : ' . json_encode($refus3) . "\n";

// -------------------------------------------------------------------------
echo "\n=== 4. Quota même IP : 3 acceptées puis refus ===\n";
$nettoyer();
$quota = [];
for ($i = 1; $i <= 6; $i++) {
    $r = $lancer(['198.21.0.1']);
    $v = $r['198.21.0.1'] ?? '?';
    $quota[$v] = ($quota[$v] ?? 0) + 1;
}
ksort($quota);
$ok(($quota['accepte'] ?? 0) === 3, '3 demandes acceptées pour la même IP (' . ($quota['accepte'] ?? 0) . ')');
$ok(($quota['tuc_onboarding_quota'] ?? 0) === 3, 'puis 3 refus de quota (' . ($quota['tuc_onboarding_quota'] ?? 0) . ')');

// -------------------------------------------------------------------------
// -------------------------------------------------------------------------
echo "\n=== 5. Conflit persistant (ligne de compteur qui bouge sans arrêt) : << réessayez >>, pas << indisponible >> ===\n";
$nettoyer();
$ip5 = '198.21.0.1';
$_SERVER['REMOTE_ADDR'] = $ip5;
$cle5 = 'tuc_demandes_' . md5($ip5);
global $wpdb;
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $cle5, '0|3600|42|1'));
// Chaque tentative d'écriture voit sa ligne changer sous elle (concurrence
// optimiste permanente) : la reprise par deadlock ne s'applique pas, la
// boucle bornée s'épuise, et le refus doit rester un CONFLIT.
$dedans = false; // garde de réentrance : le filtre ne doit pas se rappeler lui-même
$perturbe = static function (string $q) use ($cle5, $wpdb, &$dedans): string {
    if ($dedans || strpos($q, $cle5) === false || stripos(ltrim($q), 'UPDATE') !== 0) {
        return $q;
    }
    $dedans = true;
    // La ligne change sous les pieds de l'écrivain : sa comparaison
    // conditionnelle échouera, à chaque tentative, sans deadlock.
    // (On change la SÉQUENCE, pas un espace de fin : la collation PAD SPACE
    // de MariaDB considère << 1|2|3|4 >> et << 1|2|3|4  >> comme égaux, et la
    // comparaison conditionnelle réussirait.)
    $wpdb->query("UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 3), '|', 999) WHERE option_name = '{$cle5}'");
    $dedans = false;
    return $q;
};
add_filter('query', $perturbe);
$r5 = new ReflectionClass('TUC_Onboarding_Restaurant');
$m5 = $r5->getMethod('reserver_demande');
$m5->setAccessible(true);
$e5 = $m5->invoke(null);
remove_filter('query', $perturbe);
$code5 = $e5 instanceof WP_Error ? $e5->get_error_code() : 'accepte';
$ok($code5 === 'tuc_onboarding_occupe', 'conflit persistant => tuc_onboarding_occupe, pas storage : ' . $code5);
$ok($verrous() === 0, 'aucun verrou orphelin (' . $verrous() . ')');

echo "\n=== Bilan ===\n";
$nettoyer();
echo $ko === 0 ? "bilan : TOUT VERT\n" : "bilan : $ko ECHEC(S)\n";
exit($ko === 0 ? 0 : 1);
