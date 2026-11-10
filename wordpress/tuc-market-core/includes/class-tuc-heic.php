<?php
/**
 * REVUE N°13/14 — AIGUILLAGE DE LA CONVERSION HEIC, testable sans décodeur.
 *
 * Défaut mesuré (2026-11-03, puis reproduit en CI le 2026-11-10) : la conversion
 * d'une photo iPhone appelait `wp_tempnam()`, qui vit dans
 * `wp-admin/includes/file.php`. En requête web, ce fichier n'est PAS chargé :
 * la photo était refusée (415) alors qu'Imagick savait convertir. En WP-CLI il
 * l'est, donc le contrat à 133 cas ne voyait rien.
 *
 * Ce module sépare deux décisions, pour que la CI puisse vérifier la première
 * même sur une image Docker sans décodeur HEIC :
 *
 *   1. convertir_en_jpeg() : aiguillage (est-ce un HEIF ? puis-je convertir ?)
 *      et préparation des dépendances — vérifiable PARTOUT ;
 *   2. convertir_heif_en_jpeg() : le travail Imagick proprement dit, qui exige
 *      un décodeur (libheif) présent dans l'image.
 *
 * Voir tests/heic-aiguillage.php et tests/heic-dependance-http.php.
 */
/**
 * Aiguillage de la conversion HEIC → JPEG.
 *
 * Ne touche à aucun fichier lui-même : décide, charge la dépendance de
 * `wp_tempnam()`, et délègue. Ne rend jamais un tableau « convertible » si la
 * dépendance manque : c'est ce silence qui a produit le 415 trompeur.
 *
 * @param string $chemin Chemin du fichier reçu.
 * @param string $nom    Nom d'origine (extension conservée pour les non-HEIF).
 * @return array|WP_Error Tableau avec les clés chemin/nom/temporaire, ou erreur.
 */
function tuc_heic_aiguillage(string $chemin, string $nom): array|WP_Error
{
    $entete = (string) @file_get_contents($chemin, false, null, 0, 32);
    $estHeif = strpos($entete, 'ftyp') !== false
        && preg_match('/ftyp(heic|heix|hevc|heim|heis|hevm|hevs|mif1|msf1)/', $entete) === 1;

    if (!$estHeif) {
        // Ce n'est pas un HEIF : on rend le fichier tel quel, l'appelant le
        // valide comme avant (un PDF renommé part vers la validation et sera
        // refusé là-bas).
        return ['chemin' => $chemin, 'nom' => $nom, 'temporaire' => ''];
    }

    // DÉPENDANCE : `wp_tempnam()` vit dans wp-admin/includes/file.php, que le
    // front-end REST ne charge PAS. Mesuré en HTTP réel : sans ce require, la
    // conversion échouait en 415 « n'a pas pu être convertie » alors qu'Imagick
    // convertissait sans problème. On le charge ici, dans la fonction qui en a
    // besoin, et pas au chargement du plugin.
    if (!function_exists('wp_tempnam')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!function_exists('wp_tempnam')) {
        return new WP_Error(
            'tuc_onboarding_photo_heic',
            __('Cette photo n’a pas pu être convertie.', 'tuc-core'),
            ['status' => 415]
        );
    }

    // Le refus « pas de décodeur » vient APRÈS le chargement de la dépendance :
    // dans l'ordre inverse (mesuré en CI le 2026-11-10), la fonction s'arrêtait
    // avant de charger `wp_tempnam()`, et le test de dépendance ne pouvait plus
    // être vérifié sur les images sans libheif.
    if (!class_exists('Imagick') || !tuc_heic_decodeur_disponible()) {
        return new WP_Error(
            'tuc_onboarding_photo_heic',
            __('Cette photo est au format HEIC (iPhone), que ce site ne sait pas encore convertir. Sur iPhone : Réglages → Appareil photo → Formats → « Le plus compatible », ou partagez la photo en JPEG.', 'tuc-core'),
            ['status' => 415]
        );
    }

    return ['chemin' => $chemin, 'nom' => $nom, 'temporaire' => '', 'heif' => true];
}

/**
 * Le décodeur HEIC est-il réellement disponible ?
 *
 * `class_exists('Imagick')` ne suffit pas : l'image Docker `wordpress:cli` a
 * Imagick SANS libheif (mesuré en CI le 2026-11-10 : « Imagick : présent /
 * décodeur HEIC : non »). On interroge donc la liste des formats connus.
 */
function tuc_heic_decodeur_disponible(): bool
{
    static $disponible = null;
    if ($disponible !== null) {
        return $disponible;
    }
    if (!class_exists('Imagick')) {
        return $disponible = false;
    }
    try {
        $im = new Imagick();
        $formats = $im->queryFormats('HEIC');
        $im->clear();
        $disponible = count($formats) > 0;
    } catch (Throwable $e) {
        $disponible = false;
    }
    return $disponible;
}
