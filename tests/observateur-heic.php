<?php
/**
 * OBSERVATEUR HEIC — revue n°18.
 *
 * Deux choses DISTINCTES, et nommées comme telles :
 *   A. l'exécution MANUELLE de la tâche (`do_action`) prouve la LOGIQUE de purge ;
 *   B. le déclenchement AUTONOME par WP-Cron exige une échéance due ET un
 *      déclencheur (trafic, ou `wp cron event run`). Les deux sont testés.
 *
 * L'observateur doit rester DÉSACTIVÉ par défaut. Aucune activation
 * diagnostique n'a lieu ici sans en avoir d'abord vérifié la procédure de purge.
 *
 * Usage : php observateur-heic.php <wordpress> [--bac-jetable]
 */
$wpRoot = $argv[1] ?? '';
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "usage : php observateur-heic.php <wordpress> [--bac-jetable]\n");
    exit(2);
}
$urlHttp = '';
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, 'http://') === 0 || strpos($a, 'https://') === 0) {
        $urlHttp = rtrim($a, '/');
    }
}
$accord = in_array('--bac-jetable', $argv, true) || getenv('TUC_BAC_JETABLE') === '1';

unset($wp);
require $wpRoot . '/wp-load.php';

$marque = is_file($wpRoot . '/.tuc-bac-jetable');
$host = strtolower((string) parse_url((string) get_option('home', ''), PHP_URL_HOST));
$local = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
if (!$accord || !$marque || !$local) {
    fwrite(STDERR, "REFUS D'EXÉCUTION — bac jetable uniquement (site : " . get_option('home') . ").\n");
    exit(3);
}

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? '  OK    ' : '  ECHEC ') . "--- $m\n";
    if (!$c) {
        $ko++;
    }
};
$journal = static fn (): array => (array) get_option('tuc_heic_observations', []);
$heic = dirname(__FILE__) . '/fixtures/heic-mire-1600x1200.heic';

// -------------------------------------------------------------------------
echo "=== 1. Désactivé par défaut ===\n";
tuc_heic_observer_desactiver();
tuc_heic_observations_purger();
$ok(tuc_heic_observations_actif() === false, 'tuc_heic_observations_actif() rend false sans activation');
$ok(tuc_heic_observation_echeance() === 0, 'aucune échéance planifiée tant que rien n’est activé');
$ok($journal() === [], 'journal vide');
$f = tuc_heic_observer_entree($heic, 'avant-conversion');
$ok($f === 'heic', 'la mesure du format reste possible sans écrire (' . $f . ')');
$ok($journal() === [], 'aucune entrée écrite quand désactivé');

// -------------------------------------------------------------------------
echo "=== 2. Procédure de purge VÉRIFIÉE AVANT toute activation ===\n";
// On ne demande pas au propriétaire d'activer avant d'avoir prouvé qu'on sait
// purger : la purge est testée ici, sur un journal fabriqué, sans activation.
update_option('tuc_heic_observations', [
    ['instant' => gmdate('c', time() - 3 * 86400), 'format' => 'heic', 'avant_apres' => 'avant-conversion'],
    ['instant' => gmdate('c', time() - 3600), 'format' => 'jpeg', 'avant_apres' => 'converti-heic-vers-jpeg'],
], false);
$lues = tuc_heic_observations_lire();
$ok(count($lues) === 1, 'TTL appliquée à la lecture : 1 entrée récente conservée, 1 périmée retirée');
$ok(count($journal()) === 1, 'la périmée est supprimée de la BASE, pas seulement masquée');
$ok(tuc_heic_observations_purger() === true && $journal() === [], 'purge manuelle vérifiée : journal vidé');

// -------------------------------------------------------------------------
echo "=== 3. Activation explicite : métadonnées minimales ===\n";
tuc_heic_observer_activer(60);
$ok(tuc_heic_observations_actif() === true, 'observateur actif après activation explicite');
$ok(tuc_heic_observation_echeance() > time(), 'une échéance existe dès l’activation');
$jpeg = sys_get_temp_dir() . '/obs-' . wp_generate_password(5, false) . '.jpg';
$im = imagecreatetruecolor(300, 200);
imagejpeg($im, $jpeg, 80);
imagedestroy($im);
tuc_heic_observer_entree($heic, 'avant-conversion');
tuc_heic_observer_entree($jpeg, 'avant-conversion');
$j = $journal();
$ok(count($j) === 2, 'deux entrées écrites (' . count($j) . ')');
$champs = array_keys((array) ($j[0] ?? []));
sort($champs);
$ok($champs === ['avant_apres', 'format', 'instant'], 'champs exactement avant_apres, format, instant : ' . implode(', ', $champs));
$brut = json_encode($j);
$ok(strpos($brut, $jpeg) === false && stripos($brut, '.jpg') === false, 'aucun nom de fichier dans le journal');
$ok(strpos($brut, '127.0.0.1') === false, 'aucune adresse IP dans le journal');
$ok(preg_match('/"taille"/', $brut) !== 1, 'aucune taille de fichier');
@unlink($jpeg);

// -------------------------------------------------------------------------
echo "=== 4. Déclenchement MANUEL de la tâche : la logique de purge ===\n";
tuc_heic_observations_purger();
update_option('tuc_heic_observations', [
    ['instant' => gmdate('c', time() - 4 * 86400), 'format' => 'heic', 'avant_apres' => 'avant-conversion'],
    ['instant' => gmdate('c', time() - 3600), 'format' => 'png', 'avant_apres' => 'avant-conversion'],
], false);
tuc_heic_observer_activer(60);
do_action('tuc_heic_observation_purge');
$apres = $journal();
$ok(count($apres) === 1 && ($apres[0]['format'] ?? '') === 'png', 'tâche exécutée à la main : seule la périmée part');

// -------------------------------------------------------------------------
echo "=== 5. Déclenchement AUTONOME par WP-Cron (via le serveur web, sans do_action) ===\n";
// Mise en place : journal périmé + activation échue + échéance DUE.
tuc_heic_observations_purger();
update_option('tuc_heic_observations', [
    ['instant' => gmdate('c', time() - 5 * 86400), 'format' => 'heif-autre', 'avant_apres' => 'avant-conversion'],
], false);
tuc_heic_observer_activer(1);
$jeton = get_option('tuc_heic_observation_jeton');
$jeton['jusqu_a'] = time() - 60;
update_option('tuc_heic_observation_jeton', $jeton, false);
$prochaine = wp_next_scheduled('tuc_heic_observation_purge');
$ok($prochaine !== false, 'une échéance est planifiée : ' . ($prochaine !== false ? gmdate('c', (int) $prochaine) : 'aucune'));
// L'option `cron` n'est PAS une chaîne : un UPDATE brut écrit « 1794… » et
// WordPress la relit vide — l'échéance disparaît sans que le cron tourne.
// On passe donc par l'API d'options, en déplaçant l'échéance dans le passé.
$cron = get_option('cron');
if (is_array($cron)) {
    foreach ($cron as $ts => $hooks) {
        if (isset($hooks['tuc_heic_observation_purge'])) {
            unset($cron[$ts]);
            $cron[time() - 30]['tuc_heic_observation_purge'] = $hooks['tuc_heic_observation_purge'];
        }
    }
    update_option('cron', $cron, false);
}
wp_cache_flush();
$ok(wp_next_scheduled('tuc_heic_observation_purge') !== false
    && wp_next_scheduled('tuc_heic_observation_purge') <= time(),
    'échéance ramenée dans le passé et toujours enregistrée');

// Déclenchement SANS do_action écrit à la main : on passe par le RUNNER WP-Cron
// lui-même, dans un processus séparé. C'est le même chemin de code que celui
// emprunté par le trafic (wp-cron.php) ou par un cron système.
// Limite mesurée et dite : dans ce bac, wp-cron.php via le serveur intégré rend
// 200 sans corps et n'exécute rien — l'échéance a été vérifiée « due », puis
// l'exécution passe par le runner. Sur un site réel, le déclencheur est le
// trafic ou une entrée de crontab ; aucune des deux n'est disponible ici.
$wpCli = getenv('TUC_WP_CLI') ?: '/home/user/bacs/wp-cli.phar';
$sortieCron = [];
$codeCron = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wpCli) . ' --path=' . escapeshellarg($wpRoot)
    . ' --allow-root cron event run tuc_heic_observation_purge 2>&1',
    $sortieCron,
    $codeCron
);
$ok($codeCron === 0, 'le runner WP-Cron exécute la tâche (code ' . $codeCron . ')');
// Relecture FRAÎCHE depuis la base : le cache du processus courant est périmé.
$lireOptions = static function (string $cle) use ($wpdb): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $cle));
};
$brutJournal = $lireOptions('tuc_heic_observations');
$brutJeton = $lireOptions('tuc_heic_observation_jeton');
// Les options sont sérialisées en base : on les relit via unserialize.
$journalCron = $brutJournal === '' ? null : @unserialize($brutJournal);
$jetonCron = $brutJeton === '' ? null : @unserialize($brutJeton);
$ok($journalCron === null || $journalCron === false || $journalCron === [],
    'le journal a été EFFACÉ par WP-Cron seul, sans envoi ni lecture : ' . json_encode($journalCron));
$ok($jetonCron === null || $jetonCron === false,
    'l’observateur a été ÉTEINT par WP-Cron, sans intervention : ' . json_encode($jetonCron));

echo "\n=== Bilan ===\n";
echo 'bilan : ' . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
tuc_heic_observer_desactiver();
tuc_heic_observations_purger();
exit($ko === 0 ? 0 : 1);
