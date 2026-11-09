<?php
/**
 * Convertisseur d'images TUC — JPEG → AVIF + WebP.
 *
 * Usage :  php tools/convert-images.php [--force]
 *
 * Pourquoi pas un plugin ? Un plugin de conversion tourne DANS WordPress : il ajoute
 * des requêtes à chaque upload, pèse sur l'admin et consomme du CPU à la volée.
 * Ici la conversion se fait une fois, dans le dépôt, et le résultat est versionné.
 *
 * Mesures sur les 28 visuels (680×440) :
 *   JPEG 1 218 Ko · WebP q78 1 188 Ko (−2 %) · AVIF q42 509 Ko (−58 %)
 */

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

$dir   = __DIR__ . '/../assets/img';
$force = in_array('--force', $argv, true);

if (function_exists('imageavif')) {
    $encoder = 'gd';
} elseif (class_exists('Imagick')) {
    $encoder = 'imagick';
} else {
    exit("AVIF non supporté par ce PHP. Il faut PHP >= 8.1 avec GD compilé AVIF, ou Imagick >= 3.7.\n");
}

$files = glob($dir . '/*.jpg') ?: [];
printf("%d fichiers JPEG · encodeur : %s\n", count($files), $encoder);

$totIn = $totAvif = $totWebp = 0;

foreach ($files as $file) {
    $name = pathinfo($file, PATHINFO_FILENAME);
    $avif = "$dir/avif/$name.avif";
    $webp = "$dir/webp/$name.webp";

    @mkdir("$dir/avif", 0755, true);
    @mkdir("$dir/webp", 0755, true);

    $in = filesize($file);
    $totIn += $in;

    if (!$force && file_exists($avif) && file_exists($webp)) {
        $totAvif += filesize($avif);
        $totWebp += filesize($webp);
        continue;
    }

    if ($encoder === 'gd') {
        $img = imagecreatefromjpeg($file);
        imageavif($img, $avif, 42, 60);
        imagewebp($img, $webp, 78);
        imagedestroy($img);
    } else {
        $img = new Imagick($file);
        $img->setImageFormat('avif');
        $img->setImageCompressionQuality(42);
        $img->writeImage($avif);
        $img->setImageFormat('webp');
        $img->setImageCompressionQuality(78);
        $img->writeImage($webp);
        $img->clear();
    }

    $totAvif += filesize($avif);
    $totWebp += filesize($webp);
    printf(
        "  %-24s %5.0fK -> avif %4.0fK - webp %4.0fK\n",
        $name,
        $in / 1024,
        filesize($avif) / 1024,
        filesize($webp) / 1024
    );
}

printf(
    "\nTotal : JPEG %.0fK - AVIF %.0fK (-%d%%) - WebP %.0fK (-%d%%)\n",
    $totIn / 1024,
    $totAvif / 1024,
    $totIn ? (int) (100 - 100 * $totAvif / $totIn) : 0,
    $totWebp / 1024,
    $totIn ? (int) (100 - 100 * $totWebp / $totIn) : 0
);
