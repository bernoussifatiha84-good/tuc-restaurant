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

/**
 * OBSERVATEUR DU FORMAT REÇU — désactivé par défaut.
 *
 * Pourquoi ce journal existe : un JPEG dans `uploads` ne prouve pas que l'iPhone
 * a envoyé un JPEG (iOS convertit parfois avant l'envoi), et `wp_check_filetype()`
 * lit l'extension. On mesure donc le contenu À LA RÉCEPTION.
 *
 * Il ne collecte QUE trois champs, et rien d'autre :
 *   - `instant`     : date UTC, pour corréler avec le test ;
 *   - `format`      : heic / jpeg / png / webp / heif-autre / inconnu (premiers octets) ;
 *   - `avant_apres` : 'avant-conversion' ou 'converti-heic-vers-jpeg'.
 *
 * JAMAIS : la photo, son nom, son contenu, l'adresse IP, la taille exacte, le
 * nom du restaurant, la référence de demande.
 *
 * Activation EXPLICITE et TEMPORAIRE : `tuc_heic_observer_activer(60)` (minutes).
 * Durée de vie des entrées : `TUC_HEIC_OBSERVATION_TTL` (2 jours par défaut),
 * appliquée À LA LECTURE — donc sans attendre un nouvel envoi.
 */
const TUC_HEIC_OBSERVATION_OPTION = 'tuc_heic_observations';
const TUC_HEIC_OBSERVATION_JETON = 'tuc_heic_observation_jeton';
const TUC_HEIC_OBSERVATION_TTL = 2 * 86400;
const TUC_HEIC_OBSERVATION_MAX = 50;

/** L'observateur est-il actif ? Faux par défaut, vrai seulement sur activation valide. */
function tuc_heic_observations_actif(): bool
{
    $actif = get_option(TUC_HEIC_OBSERVATION_JETON, null);
    if (!is_array($actif) || empty($actif['jeton']) || empty($actif['jusqu_a'])) {
        return false;
    }
    if ((int) $actif['jusqu_a'] < time()) {
        delete_option(TUC_HEIC_OBSERVATION_JETON); // activation expirée : on nettoie
        return false;
    }
    return true;
}

/**
 * Active l'observateur pour N minutes. Rend un jeton à conserver.
 * Sans cette activation, aucune écriture n'a lieu.
 */
function tuc_heic_observer_activer(int $minutes = 60): string
{
    $jeton = wp_generate_password(24, false, false);
    update_option(TUC_HEIC_OBSERVATION_JETON, [
        'jeton'    => $jeton,
        'jusqu_a'  => time() + max(1, $minutes) * 60,
        'active_le' => gmdate('c'),
    ], false);
    return $jeton;
}

/** Désactive l'observateur. Les entrées déjà écrites restent jusqu'à purge. */
function tuc_heic_observer_desactiver(): void
{
    delete_option(TUC_HEIC_OBSERVATION_JETON);
}

/** Vide le journal. À utiliser avec l'accord du propriétaire. */
function tuc_heic_observations_purger(): bool
{
    delete_option(TUC_HEIC_OBSERVATION_OPTION);
    return get_option(TUC_HEIC_OBSERVATION_OPTION, null) === null;
}

/**
 * Lit le journal en appliquant l'expiration — même si aucun envoi n'a eu lieu.
 * Une entrée trop vieille est retirée de la base, pas seulement ignorée.
 */
function tuc_heic_observations_lire(): array
{
    $journal = get_option(TUC_HEIC_OBSERVATION_OPTION, []);
    if (!is_array($journal)) {
        return [];
    }
    $limite = time() - TUC_HEIC_OBSERVATION_TTL;
    $gardees = [];
    foreach ($journal as $entree) {
        $instant = isset($entree['instant']) ? strtotime((string) $entree['instant']) : false;
        if ($instant === false || $instant >= $limite) {
            $gardees[] = $entree;
        }
    }
    if (count($gardees) !== count($journal)) {
        update_option(TUC_HEIC_OBSERVATION_OPTION, $gardees, false);
    }
    return $gardees;
}

/**
 * Observe le fichier REÇU (avant conversion) et note le format d'après les
 * premiers octets. N'écrit RIEN si l'observateur n'est pas activé.
 *
 * @return string le format détecté, même quand l'observateur est inactif
 */
function tuc_heic_observer_entree(string $chemin, string $avantApres = 'avant-conversion'): string
{
    $format = 'inconnu';
    if (is_file($chemin)) {
        $entete = (string) @file_get_contents($chemin, false, null, 0, 32);
        if (strlen($entete) >= 12 && substr($entete, 4, 4) === 'ftyp') {
            $format = preg_match('/ftyp(heic|heix|hevc|heim|heis|hevm|hevs|mif1|msf1)/', $entete) === 1
                ? 'heic'
                : 'heif-autre';
        } elseif (substr($entete, 0, 3) === "\xFF\xD8\xFF") {
            $format = 'jpeg';
        } elseif (substr($entete, 0, 8) === "\x89PNG\r\n\x1A\n") {
            $format = 'png';
        } elseif (substr($entete, 0, 4) === 'RIFF' && substr($entete, 8, 4) === 'WEBP') {
            $format = 'webp';
        }
    }

    if (!tuc_heic_observations_actif()) {
        return $format; // désactivé : mesure possible, aucune écriture
    }

    $journal = tuc_heic_observations_lire(); // applique l'expiration
    $journal[] = [
        'instant'     => gmdate('c'),
        'format'      => $format,
        'avant_apres' => $avantApres,
    ];
    update_option(TUC_HEIC_OBSERVATION_OPTION, array_slice($journal, -TUC_HEIC_OBSERVATION_MAX), false);

    return $format;
}
