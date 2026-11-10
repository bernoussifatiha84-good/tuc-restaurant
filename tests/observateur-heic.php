<?php
/**
 * REVUE N°16 — OBSERVATEUR HEIC : désactivé par défaut, activable, nettoyable.
 *
 * Vérifie les quatre états demandés :
 *   1. DÉSACTIVÉ (défaut) : rien n'est écrit, même après un envoi ;
 *   2. ACTIVÉ explicitement : seules les métadonnées prévues sont écrites,
 *      aucune photo, aucun nom d'origine, aucune IP, pas de taille exacte ;
 *   3. EXPIRATION : les entrées plus vieilles que la durée de vie disparaissent
 *      même si AUCUN nouvel envoi n'a lieu (purge à la lecture) ;
 *   4. NETTOYAGE : la fonction de purge efface tout, et le désactiver suffit.
 *
 * À exécuter dans un bac jetable.
 * Usage : php observateur-heic.php <chemin-wordpress>
 */
$wpRoot = $argv[1] ?? '';
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "usage : php observateur-heic.php <wordpress>\n");
    exit(2);
}
unset($wp);
require $wpRoot . '/wp-load.php';

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? '  OK    ' : '  ECHEC ') . "— $m\n";
    if (!$c) {
        $ko++;
    }
};
$journal = static function (): array {
    $j = get_option('tuc_heic_observations', []);
    return is_array($j) ? $j : [];
};
$reset = static function (): void {
    delete_option('tuc_heic_observations');
};

$heic = __DIR__ . '/fixtures/heic-mire-1600x1200.heic';
$jpeg = sys_get_temp_dir() . '/obs-' . wp_generate_password(6, false) . '.jpg';
$im = imagecreatetruecolor(200, 150);
imagejpeg($im, $jpeg, 80);
imagedestroy($im);

echo "=== 1. Désactivé par défaut ===\n";
$reset();
$ok(tuc_heic_observations_actif() === false, 'tuc_heic_observations_actif() rend false sans activations');
$format = tuc_heic_observer_entree($heic, 'avant-conversion');
$ok($format === 'heic', 'la mesure reste possible sans écrire (' . $format . ')');
$ok($journal() === [], 'aucune entrée écrite quand désactivé');

echo "\n=== 2. Activé explicitement : métadonnées minimales ===\n";
$jeton = tuc_heic_observer_activer(60);
$ok(is_string($jeton) && $jeton !== '', 'activation par jeton fourni (' . substr((string) $jeton, 0, 8) . '…)');
$ok(tuc_heic_observations_actif() === true, 'observateur actif');
tuc_heic_observer_entree($heic, 'avant-conversion');
tuc_heic_observer_entree($jpeg, 'avant-conversion');
$j = $journal();
$ok(count($j) === 2, 'deux envois, deux entrées (' . count($j) . ')');
$champs = array_keys($j[0] ?? []);
sort($champs);
$attendu = ['avant_apres', 'format', 'instant'];
sort($attendu);
$ok($champs === $attendu, 'champs exactement ' . implode(', ', $attendu) . ' — obtenu : ' . implode(', ', $champs));
$brut = wp_json_encode($j);
$ok(strpos($brut, 'heic-mire') === false, 'aucun nom de fichier dans le journal');
$ok(strpos($brut, '198.') === false && !preg_match('/\d+\.\d+\.\d+\.\d+/', $brut), 'aucune adresse IP dans le journal');
$ok(!isset($j[0]['taille']) && !isset($j[0]['taille_ko']), 'aucune taille de fichier (non indispensable)');
$ok(($j[0]['format'] ?? '') === 'heic' && ($j[1]['format'] ?? '') === 'jpeg', 'formats mesurés par contenu : ' . ($j[0]['format'] ?? '?') . ' / ' . ($j[1]['format'] ?? '?'));

echo "\n=== 3. Expiration SANS nouvel envoi ===\n";
// On force des entrées anciennes, puis on relit : la purge doit se faire à la lecture.
$ancien = [];
for ($i = 0; $i < 3; $i++) {
    $ancien[] = ['instant' => gmdate('c', time() - 3 * 86400), 'format' => 'heic', 'avant_apres' => 'avant-conversion'];
}
update_option('tuc_heic_observations', array_merge($ancien, $j), false);
$lues = tuc_heic_observations_lire();
$ok(count($lues) === 2, 'les 3 entrées de plus de 2 jours ont disparu à la lecture (' . count($lues) . ' restantes)');
$enBase = $journal();
$ok(count($enBase) === 2, 'et elles ont aussi été supprimées de la base, pas seulement masquées (' . count($enBase) . ')');

echo "\n=== 4. Désactivation et nettoyage ===\n";
tuc_heic_observer_desactiver();
$ok(tuc_heic_observations_actif() === false, 'désactivation effective');
$avant = count($journal());
tuc_heic_observer_entree($heic, 'avant-conversion');
$ok(count($journal()) === $avant, 'après désactivation, plus aucune écriture');
$ok(tuc_heic_observations_purger() === true, 'purge explicite exécutée');
$ok($journal() === [], 'journal vidé');

echo "\n=== 5. Purge SANS envoi ET SANS lecture : WP-Cron déclenché seul ===\n";
tuc_heic_observer_desactiver();
tuc_heic_observations_purger();
update_option('tuc_heic_observations', [
    ['instant' => gmdate('c', time() - 4 * 86400), 'format' => 'heic', 'avant_apres' => 'avant-conversion'],
    ['instant' => gmdate('c', time() - 3600),     'format' => 'jpeg', 'avant_apres' => 'converti-heic-vers-jpeg'],
], false);
tuc_heic_observer_activer(1);
$ok(wp_next_scheduled('tuc_heic_observation_purge') !== false, 'une échéance de purge est planifiée dès l’activation');
// On force l'échéance dans le passé : l'activation est « terminée ».
$actif = get_option('tuc_heic_observation_jeton');
$actif['jusqu_a'] = time() - 60;
update_option('tuc_heic_observation_jeton', $actif, false);
// AUCUN envoi, AUCUNE lecture : on ne déclenche que la tâche planifiée.
do_action('tuc_heic_observation_purge');
$restant = get_option('tuc_heic_observations', null);
$ok($restant === null || $restant === [], 'la tâche horaire a effacé le journal SANS envoi ni lecture : ' . json_encode($restant));
$ok(get_option('tuc_heic_observation_jeton', null) === null, 'l’observateur a été ÉTEINT à son échéance, sans intervention');

// Activation encore valide : seules les entrées périmées partent.
tuc_heic_observations_purger();
update_option('tuc_heic_observations', [
    ['instant' => gmdate('c', time() - 4 * 86400), 'format' => 'heic', 'avant_apres' => 'avant-conversion'],
    ['instant' => gmdate('c', time() - 3600),     'format' => 'png',  'avant_apres' => 'avant-conversion'],
], false);
tuc_heic_observer_activer(60);
do_action('tuc_heic_observation_purge');
$apres = get_option('tuc_heic_observations', []);
$ok(count($apres) === 1 && ($apres[0]['format'] ?? '') === 'png', 'activation valide : seule la périmée est partie, la récente reste');
tuc_heic_observer_desactiver();
tuc_heic_observations_purger();

@unlink($jpeg);
echo "\nbilan : " . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
exit($ko === 0 ? 0 : 1);
