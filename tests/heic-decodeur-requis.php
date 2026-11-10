<?php
/**
 * HEIC — JOB À DÉCODEUR PRÉSENT, CONVERSION OBLIGATOIRE (revue n°18).
 *
 * Distinction stricte, imposée par la revue :
 *   - ce job exige un décodeur HEIC. Décodeur absent  => ÉCHEC (code 1).
 *   - décodeur présent + conversion en échec          => ÉCHEC (code 1).
 *   - décodeur présent + conversion réussie           => succès (code 0).
 * Le cas « pas de décodeur » garde son propre test (heic-dependance-http.php
 * sans `required`), exécuté séparément.
 *
 * Usage : php heic-decodeur-requis.php <wordpress> <fixture.heic>
 */
$wpRoot = $argv[1] ?? '';
$fixture = $argv[2] ?? '';
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "usage : php heic-decodeur-requis.php <wordpress> <fixture.heic>\n");
    exit(2);
}

$decodeur = false;
if (class_exists('Imagick')) {
    try {
        $decodeur = count((new Imagick())->queryFormats('HEIC')) > 0;
    } catch (Throwable $e) {
        $decodeur = false;
    }
}
echo 'décodeur HEIC : ' . ($decodeur ? 'oui' : 'non') . "\n";
if (!$decodeur) {
    echo "ECHEC — ce job exige un décodeur HEIC et n'en a pas.\n";
    echo "        La conversion n'a donc pas pu être vérifiée.\n";
    exit(1);
}
if ($fixture === '' || !is_file($fixture)) {
    echo "ECHEC — fixture HEIC introuvable : $fixture\n";
    exit(1);
}

unset($wp);
require $wpRoot . '/wp-load.php';

$ref = new ReflectionMethod('TUC_Onboarding_Restaurant', 'convertir_en_jpeg');
$ref->setAccessible(true);
$resultat = $ref->invoke(null, $fixture, 'photo-iphone.heic');

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? '  OK    ' : '  ECHEC ') . "— $m\n";
    if (!$c) {
        $ko++;
    }
};
$ok(is_array($resultat), 'un vrai HEIC est converti (tableau rendu, pas une erreur) : '
    . (is_wp_error($resultat) ? $resultat->get_error_code() : 'ok'));
$ok(is_array($resultat) && !empty($resultat['temporaire']) && is_file($resultat['temporaire']),
    'un fichier temporaire non vide est produit');
$ok(is_array($resultat) && filesize((string) $resultat['temporaire']) > 0,
    'le fichier temporaire a une taille > 0');
$ok(is_array($resultat) && str_ends_with((string) $resultat['nom'], '.jpg'),
    'le nom rendu est en .jpg (' . (is_array($resultat) ? $resultat['nom'] : '-') . ')');
if (is_array($resultat) && is_file((string) $resultat['temporaire'])) {
    $info = @getimagesize((string) $resultat['temporaire']);
    $ok(is_array($info) && ($info['mime'] ?? '') === 'image/jpeg',
        'le contenu produit est réellement un JPEG (' . ($info['mime'] ?? 'inconnu') . ')');
    @unlink((string) $resultat['temporaire']);
}

echo "\nbilan : " . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
exit($ko === 0 ? 0 : 1);
