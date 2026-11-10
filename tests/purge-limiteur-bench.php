<?php
/**
 * REVUE N°14 — PURGE DES CLÉS DU LIMITEUR (options SQL directes, pas des transients).
 *
 * Reproche fait à la revue n°13 : le banc sous charge purgeait des TRANSIENTS
 * (`_transient_*`, deux lignes SQL par transient), alors que le limiteur écrit
 * des LIGNES D'OPTIONS DIRECTES :
 *
 *     tuc_demandes_<md5(ip)>            valeur  n|échéance|génération|séquence
 *     tuc_demandes_<md5(ip)>_verrou     jeton|horodatage
 *     tuc_tentatives_<md5(ip)>          idem
 *
 * Ce fichier purge CES clés-là, avec le vrai balayeur du module
 * (balayer_lignes_perimees), et vérifie :
 *   1. les clés expirées du limiteur sont supprimées ;
 *   2. les clés vivantes sont CONSERVÉES (aucune perte) ;
 *   3. une ligne du limiteur n'est JAMAIS prise pour un transient ;
 *   4. les lignes qui ne nous appartiennent pas sont intactes ;
 *   5. le balayage suivant ne supprime plus rien (idempotence).
 *
 * Usage : php purge-limiteur-bench.php <chemin-wordpress> [nb_lignes]
 * Tout se passe dans un bac jetable : ce fichier supprime des lignes.
 */

$wpRoot = $argv[1] ?? '';
$nb = (int) ($argv[2] ?? 20000);
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "usage : php purge-limiteur-bench.php <wordpress> [nb_lignes]\n");
    exit(2);
}

unset($wp);
require $wpRoot . '/wp-load.php';

global $wpdb;
$options = $wpdb->options;
$maintenant = time();
$ko = 0;

// ---------------------------------------------------------------------------
// 1. Jeu de données : EXACTEMENT les clés du limiteur.
//    Une paire sur trois est expirée (échéance dans le passé).
// ---------------------------------------------------------------------------
// Le motif doit respecter la forme RÉELLE des clés du limiteur :
//   tuc_demandes_<md5(ip)>  et  tuc_demandes_<md5(ip)>_verrou
// Le balayeur filtre par /^tuc_(tentatives|demandes)_[a-z0-9]+(_verrou)?$/ :
// une clé qui ne correspond pas est laissée intacte (c'est voulu). Mesuré ici :
// une première version du banc avec un motif contenant des MAJUSCULES et des « _ »
// donnait 0 suppression — non pas un défaut du module, mais un jeu de données
// qui ne ressemblait pas aux vraies clés.
$motif = strtolower(preg_replace('/[^a-z0-9]/', '', wp_generate_password(6, false)));
$prefixes = ['tuc_demandes_', 'tuc_tentatives_'];

$attenduesExpirees = 0;
$attenduesVivantes = 0;
$verrous = 0;
$valeurs = [];
$statut = []; // nom => 'expiree' | 'vivante' | 'verrou'
$lot = [];
$total = 0;

for ($i = 0; $i < $nb; $i++) {
    $prefixe = $prefixes[$i % 2];
    $ip = md5((string) $i . $motif);
    $nom = $prefixe . $ip;

    $statut[$nom] = 'vivante';
    if ($i % 10 === 0) {
        // Verrou (option directe, valeur jeton|horodatage)
        $nom .= '_verrou';
        // Verrou FRAIS : il ne doit PAS être supprimé (un verrou ancien, lui,
        // est un verrou abandonné, donc supprimable — vérifié séparément).
        $valeur = wp_generate_password(12, false) . '|' . $maintenant;
        $verrous++;
        $statut[$nom] = 'verrou';
    } elseif ($i % 3 === 0) {
        // Expirée AU-DELÀ de la rétention : le module conserve 24 h après
        // l'échéance (RETENTION_COMPTEUR = 86 400 s), mesuré ici : des clés
        // échues depuis 10 minutes n'étaient PAS supprimées — ce n'est pas un
        // défaut, c'est la rétention. On dépasse donc 24 h + 10 min.
        $valeur = '3|' . ($maintenant - 90000) . '|' . (1000 + $i) . '|3';
        $attenduesExpirees++;
        $statut[$nom] = 'expiree';
    } else {
        // Vivante : échéance dans le futur
        $valeur = '1|' . ($maintenant + 1800) . '|' . (1000 + $i) . '|1';
        $attenduesVivantes++;
    }

    $lot[] = $wpdb->prepare('(%s, %s, %s)', $nom, $valeur, 'no');
    $valeurs[$nom] = $valeur;
    $total++;

    if (count($lot) >= 500) {
        $wpdb->query("INSERT IGNORE INTO `{$options}` (option_name, option_value, autoload) VALUES " . implode(',', $lot));
        $lot = [];
    }
}
if ($lot !== []) {
    $wpdb->query("INSERT IGNORE INTO `{$options}` (option_name, option_value, autoload) VALUES " . implode(',', $lot));
}

// Trois lignes qui ne sont PAS au limiteur : elles doivent survivre.
$temoin = [
    'tuc_autre_' . $motif => 'je-ne-suis-pas-au-limiteur',
    'option_perso_' . $motif => 'valeur',
];
foreach ($temoin as $n => $v) {
    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $n, $v));
}

// On compte NOS noms, un par un, par lots : un filtre LIKE ne peut pas servir
// ici, le motif étant noyé dans un hash md5 (leçon mesurée : un LIKE '%motif%'
// rendait 0 ligne alors que les lignes existaient).
$existent = static function (array $noms) use ($wpdb, $options): array {
    $trouves = [];
    foreach (array_chunk($noms, 400) as $chunk) {
        $places = implode(',', array_fill(0, count($chunk), '%s'));
        $lignes = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM `{$options}` WHERE option_name IN ($places)",
            ...$chunk
        ));
        foreach ((array) $lignes as $l) {
            $trouves[$l] = true;
        }
    }
    return $trouves;
};

printf("=== Jeu de données (clés du LIMITEUR, pas des transients) ===\n");
printf("  lignes insérées           : %d\n", $total);
printf("  dont verrous (…_verrou)   : %d\n", $verrous);
printf("  dont expirées (>24 h)     : %d\n", $attenduesExpirees);
printf("  dont vivantes             : %d\n", $attenduesVivantes);
printf("  lignes hors limiteur      : %d (témoins)\n", count($temoin));

// ---------------------------------------------------------------------------
// 2. Purge : on appelle le VRAI balayeur du module.
// ---------------------------------------------------------------------------
$ref = new ReflectionMethod('TUC_Onboarding_Restaurant', 'balayer_lignes_perimees');
$ref->setAccessible(true);

// FAIT MESURÉ : un appel ne fait pas tout le travail. `balayer_lignes_perimees()`
// s'arrête au budget de 250 ms (PURGE_MS_MAX) et laisse un curseur : l'appel
// suivant reprend où on s'était arrêté, sans jamais sauter de ligne. On boucle
// donc comme le ferait le cron, en comptant les appels nécessaires.
$memoireAvant = memory_get_usage(true);
$t0 = microtime(true);
$supprimees = 0;
$appels = 0;
$fenetres = 0;
$lues = 0;
do {
    $appels++;
    $supprimees += (int) $ref->invoke(null, 500);
    $fenetres += (int) ($GLOBALS['tuc_purge_fenetres'] ?? 0);
    $lues += (int) ($GLOBALS['tuc_purge_lues'] ?? 0);
} while ($appels < 200 && (int) ($GLOBALS['tuc_purge_fenetres'] ?? 0) > 0);
$duree = microtime(true) - $t0;
$memoireApres = memory_get_usage(true);

printf("\n=== Purge (balayer_lignes_perimees, bouclée comme le cron) ===\n");
printf("  appels nécessaires        : %d (budget de 250 ms par appel)\n", $appels);
printf("  fenêtres                  : %d\n", $fenetres);
printf("  lignes lues               : %d\n", $lues);
printf("  lignes supprimées         : %d\n", $supprimees);
printf("  durée                     : %.2f s (%.0f lignes/s)\n", $duree, $duree > 0 ? $supprimees / $duree : 0);
printf("  mémoire                   : %+.1f Mo\n", ($memoireApres - $memoireAvant) / 1048576);

// ---------------------------------------------------------------------------
// 3. Vérifications
// ---------------------------------------------------------------------------
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? '  OK    ' : '  ECHEC ') . "— $m\n";
    if (!$c) {
        $ko++;
    }
};
echo "\n=== Vérifications ===\n";

$restants = $existent(array_keys($valeurs));
$resteExpirees = 0;
$resteVivantes = 0;
foreach ($statut as $nom => $type) {
    if (!isset($restants[$nom])) {
        continue;
    }
    if ($type === 'expiree') {
        $resteExpirees++;
    } else {
        $resteVivantes++;
    }
}
$resteTotal = $resteExpirees + $resteVivantes;

$ok(
    $supprimees === $attenduesExpirees,
    sprintf('toutes les clés EXPIRÉES du limiteur sont supprimées (%d attendues, %d supprimées)', $attenduesExpirees, $supprimees)
);
$ok(
    $resteExpirees === 0,
    sprintf('aucune clé EXPIRÉE ne subsiste (%d restantes)', $resteExpirees)
);
$ok(
    $resteVivantes === $attenduesVivantes + $verrous,
    sprintf('les clés VIVANTES sont conservées (%d attendues, %d restantes)', $attenduesVivantes + $verrous, $resteVivantes)
);

// Aucune ligne du limiteur ne doit exister en version « transient ».
$fauxTransients = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$options}` WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_' . $motif) . '%',
    $wpdb->esc_like('_transient_timeout_' . $motif) . '%'
));
$ok($fauxTransients === 0, 'aucune clé du limiteur n’a été écrite comme transient (0 ligne _transient_*)');

$temoinsIntacts = 0;
foreach ($temoin as $n => $v) {
    $lu = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$options}` WHERE option_name = %s", $n));
    if ($lu === $v) {
        $temoinsIntacts++;
    }
}
$ok($temoinsIntacts === count($temoin), sprintf('les lignes hors limiteur sont intactes (%d/%d)', $temoinsIntacts, count($temoin)));

// Une clé vivante précise, relue valeur par valeur : la purge ne doit pas
// « rajeunir » ni réécrire une valeur.
$vivanteNom = null;
foreach ($valeurs as $n => $v) {
    if (strpos($v, '|') !== false && (int) explode('|', $v)[1] > $maintenant) {
        $vivanteNom = $n;
        break;
    }
}
$vivanteOk = $vivanteNom !== null
    && $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$options}` WHERE option_name = %s", $vivanteNom)) === $valeurs[$vivanteNom];
$ok($vivanteOk, 'une clé vivante relue a exactement la même valeur (aucune réécriture)');

// Idempotence : un balayage de plus ne doit plus rien trouver.
$deuxieme = 0;
$boucles = 0;
do {
    $boucles++;
    $n = (int) $ref->invoke(null, 500);
    $deuxieme += $n;
} while ($boucles < 20 && (int) ($GLOBALS['tuc_purge_fenetres'] ?? 0) > 0);
$ok($deuxieme === 0, sprintf('après la file vidée, un balayage de plus ne supprime rien (%d)', $deuxieme));

// ---------------------------------------------------------------------------
// 4. Nettoyage (bac jetable uniquement)
// ---------------------------------------------------------------------------
foreach (array_chunk(array_keys($valeurs), 400) as $chunk) {
    $places = implode(',', array_fill(0, count($chunk), '%s'));
    $wpdb->query($wpdb->prepare("DELETE FROM `{$options}` WHERE option_name IN ($places)", ...$chunk));
}
foreach ($temoin as $n => $v) {
    $wpdb->query($wpdb->prepare("DELETE FROM `{$options}` WHERE option_name = %s", $n));
}
$wpdb->query($wpdb->prepare("DELETE FROM `{$options}` WHERE option_name IN (%s, %s)", 'tuc_purge_curseur', 'tuc_purge_generation'));

echo "\nbilan : " . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
exit($ko === 0 ? 0 : 1);
