<?php
/**
 * REVUE N°13 — RÉGRESSION : la conversion HEIC dépend de wp_tempnam().
 *
 * Défaut mesuré le 2026-11-03 : `convertir_en_jpeg()` appelait `wp_tempnam()`
 * alors que `wp-admin/includes/file.php` n'est PAS chargé en requête web. En
 * CLI (wp-cli), il l'est — c'est pourquoi le contrat à 131 cas ne voyait rien.
 *
 * Ce test se place dans les conditions du FRONT-END : il charge WordPress par
 * `wp-load.php` sans passer par wp-admin. Il échoue si la dépendance n'est pas
 * chargée par la conversion elle-même, et il échoue aussi si l'erreur rendue
 * est le message générique « n'a pas pu être convertie » (symptôme du défaut).
 *
 * Ce qu'il ne prouve pas : la conversion réelle exige le décodeur HEIC
 * (Imagick + libheif). Sans lui, le test le dit explicitement — il ne
 * transforme pas l'absence de décodeur en succès.
 *
 * Usage : php heic-dependance-http.php <chemin-wordpress> <fichier.heic>
 */
$wpRoot = $argv[1] ?? '';
$heic = $argv[2] ?? '';
// Mode CI : la fixture est à côté du script (tests/fixtures), et le WordPress
// est celui où WP-CLI est déjà chargé. « required » = l'absence de décodeur
// HEIC fait ÉCHOUER le test au lieu d'annoncer un mode dégradé.
$exigerDecodeur = in_array('required', $argv, true);
if ($heic !== '' && !is_file($heic) && is_file(__DIR__ . '/' . $heic)) {
    $heic = __DIR__ . '/' . $heic;
}
if ($heic === '' && is_file(__DIR__ . '/fixtures/heic-mire-1600x1200.heic')) {
    $heic = __DIR__ . '/fixtures/heic-mire-1600x1200.heic';
}
if ($wpRoot === '' || $heic === '' || !is_file($wpRoot . '/wp-load.php') || !is_file($heic)) {
    fwrite(STDERR, "usage : php heic-dependance-http.php <wordpress> <fichier.heic>\n");
    exit(2);
}

// Conditions du front-end : wp-load.php seulement, JAMAIS wp-admin.
// ATTENTION : WordPress utilise la variable globale `$wp` pour l'objet de
// réécriture. La garder sous ce nom casse le démarrage
// (Call to a member function add_query_var() on string). D'où $wpRoot.
unset($wp);
require $wpRoot . '/wp-load.php';

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? "  OK    " : "  ECHEC ") . "— $m\n";
    if (!$c) { $ko++; }
};

echo "=== Contexte ===\n";
echo '  SAPI                     : ' . PHP_SAPI . "\n";
echo '  wp_tempnam avant appel   : ' . (function_exists('wp_tempnam') ? 'déjà chargé' : 'ABSENT') . "\n";
echo '  Imagick                  : ' . (class_exists('Imagick') ? 'présent' : 'absent') . "\n";
$decodeur = false;
if (class_exists('Imagick')) {
    $im = new Imagick();
    $decodeur = count($im->queryFormats('HEIC')) > 0;
}
echo '  décodeur HEIC            : ' . ($decodeur ? 'oui' : 'non') . "\n";

if (!$decodeur && $exigerDecodeur) {
    echo "=== Décodeur HEIC ===\n";
    echo "  ECHEC — mode CI : aucun décodeur HEIC (Imagick/libheif). Un skip\n";
    echo "          n'est pas un succès, on refuse de conclure.\n";
    exit(1);
}

$ref = new ReflectionMethod('TUC_Onboarding_Restaurant', 'convertir_en_jpeg');
$ref->setAccessible(true);
$resultat = $ref->invoke(null, $heic, 'photo-iphone.heic');

$code = is_wp_error($resultat) ? $resultat->get_error_code() : '';
$message = is_wp_error($resultat) ? $resultat->get_error_message() : '';

echo "=== Résultat de la conversion ===\n";
echo '  code    : ' . ($code === '' ? '(aucun)' : $code) . "\n";
echo '  message : ' . ($message === '' ? '(aucun)' : $message) . "\n";

// 1. La dépendance doit être chargée PENDANT la conversion, quel que soit le
//    chemin pris (avec ou sans décodeur).
$ok(function_exists('wp_tempnam'), 'wp_tempnam() est chargé après l’appel (dépendance prise en charge par la fonction)');

// 2. L'erreur « n'a pas pu être convertie » est le symptôme exact du défaut :
//    elle ne doit JAMAIS apparaître quand le décodeur est là, ni quand il
//    manque (dans ce cas le message doit dire que le site ne sait pas convertir).
$generique = stripos($message, 'pas pu être convertie') !== false;
$ok(!$generique, 'le message n’est pas le générique « pas pu être convertie » (symptôme du défaut)');

// 3. Issue attendue selon la présence du décodeur.
if ($decodeur) {
    $ok(is_array($resultat), 'un vrai HEIC est converti (tableau rendu, pas une erreur)');
    $ok(is_array($resultat) && $resultat['temporaire'] !== '' && is_file($resultat['temporaire']),
        'un fichier temporaire non vide est produit');
    $ok(is_array($resultat) && str_ends_with((string) $resultat['nom'], '.jpg'),
        'le nom rendu est en .jpg (' . (is_array($resultat) ? $resultat['nom'] : '-') . ')');
} else {
    $ok($code === 'tuc_onboarding_photo_heic' && stripos($message, 'ne sait pas encore convertir') !== false,
        'sans décodeur : refus explicite « ce site ne sait pas encore convertir » (et non un échec de conversion)');
    echo "  NOTE : le décodeur HEIC est absent de cet environnement — la conversion\n"
       . "         réelle n'est donc PAS prouvée ici, seulement le chemin d'erreur.\n";
}

echo "\nbilan : " . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
exit($ko === 0 ? 0 : 1);
