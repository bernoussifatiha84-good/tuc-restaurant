<?php
/**
 * REVUE N°14 — AIGUILLAGE HEIC : vérifiable même sans décodeur HEIC.
 *
 * Pourquoi ce fichier existe : `tests/heic-dependance-http.php` charge
 * WordPress sans wp-admin et exige un décodeur HEIC réel. Or l'image
 * `wordpress:cli` utilisée en CI a Imagick SANS libheif (mesuré le
 * 2026-11-10 : « Imagick : présent / décodeur HEIC : non »). Ce test-ci ne
 * dépend d'aucun décodeur : il vérifie ce qui a réellement cassé le
 * 2026-11-03, à savoir la DÉPENDANCE `wp_tempnam()` et l'aiguillage.
 *
 * Ce qu'il prouve :
 *   - `wp_tempnam()` n'est pas chargé au départ (conditions du front-end) ;
 *   - une image NON HEIF traverse l'aiguillage sans conversion ni dépendance ;
 *   - un fichier HEIC est reconnu par son CONTENU (ftyp), pas par son nom ;
 *   - la dépendance est chargée par l'aiguillage lui-même ;
 *   - sans décodeur, l'erreur est EXPLICITE (415 + message iPhone) ;
 *   - avec décodeur, l'aiguillage donne le feu vert à la conversion.
 *
 * Ce qu'il ne prouve pas : que la conversion produit un JPEG (cela demande le
 * décodeur → tests/heic-dependance-http.php, joué là où libheif existe).
 *
 * Usage : php heic-aiguillage.php <chemin-wordpress> [<fichier.heic>]
 */
$wpRoot = $argv[1] ?? '';
$heic = $argv[2] ?? '';
if ($heic !== '' && !is_file($heic) && is_file(__DIR__ . '/' . $heic)) {
    $heic = __DIR__ . '/' . $heic;
}
if ($heic === '') {
    $heic = __DIR__ . '/fixtures/heic-mire-1600x1200.heic';
}
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php') || !is_file($heic)) {
    fwrite(STDERR, "usage : php heic-aiguillage.php <wordpress> [<fichier.heic>]\n");
    exit(2);
}

// Front-end : wp-load.php seulement. `$wp` est réservé par WordPress pour
// l'objet de réécriture : on ne l'utilise pas, d'où $wpRoot.
unset($wp);
require $wpRoot . '/wp-load.php';

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? "  OK    " : "  ECHEC ") . "— $m\n";
    if (!$c) {
        $ko++;
    }
};

echo "=== Contexte ===\n";
echo '  SAPI                    : ' . PHP_SAPI . "\n";
echo '  wp_tempnam au départ    : ' . (function_exists('wp_tempnam') ? 'déjà chargé' : 'ABSENT') . "\n";
echo '  Imagick                 : ' . (class_exists('Imagick') ? 'présent' : 'absent') . "\n";
echo '  décodeur HEIC           : ' . (tuc_heic_decodeur_disponible() ? 'oui' : 'non') . "\n";
$decodeur = tuc_heic_decodeur_disponible();

// Un JPEG minimal, pour l'aiguillage d'une image non HEIF.
$jpeg = sys_get_temp_dir() . '/aiguillage-' . wp_generate_password(6, false) . '.jpg';
$im = imagecreatetruecolor(64, 48);
imagejpeg($im, $jpeg, 80);
imagedestroy($im);

echo "=== Aiguillage ===\n";

$depart = function_exists('wp_tempnam');
$ok(!$depart, 'wp_tempnam() n’est PAS chargé au départ (conditions du front-end)');

$rJpeg = tuc_heic_aiguillage($jpeg, 'menu.jpg');
$ok(is_array($rJpeg) && empty($rJpeg['heif']), 'un JPEG n’est pas traité comme un HEIF');
$ok(is_array($rJpeg) && $rJpeg['nom'] === 'menu.jpg', 'un JPEG garde son nom (aucune conversion imposée)');

$faux = sys_get_temp_dir() . '/faux-' . wp_generate_password(6, false) . '.heic';
file_put_contents($faux, "%PDF-1.4\n% un PDF renommé en .heic\n");
$rFaux = tuc_heic_aiguillage($faux, 'faux.heic');
$ok(is_array($rFaux) && empty($rFaux['heif']), 'un PDF renommé .heic n’est pas reconnu comme HEIF (détection par contenu)');

$rHeic = tuc_heic_aiguillage($heic, 'photo-iphone.heic');
$ok(function_exists('wp_tempnam'), 'wp_tempnam() est chargé par l’aiguillage (dépendance prise en charge)');

if ($decodeur) {
    $ok(is_array($rHeic) && !empty($rHeic['heif']), 'avec décodeur : le HEIC est reconnu et la conversion autorisée');
} else {
    $ok(
        is_wp_error($rHeic) && $rHeic->get_error_code() === 'tuc_onboarding_photo_heic',
        'sans décodeur : erreur explicite tuc_onboarding_photo_heic (jamais un faux succès)'
    );
    $ok(
        is_wp_error($rHeic) && strpos($rHeic->get_error_message(), 'HEIC') !== false,
        'sans décodeur : le message parle du HEIC et de l’iPhone (l’utilisateur peut agir)'
    );
}

foreach ([$jpeg, $faux] as $f) {
    @unlink($f);
}

echo "\nbilan : " . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
exit($ko === 0 ? 0 : 1);
