<?php
/**
 * REVUE N°18 — RÉSILIENCE DU LIMITEUR, MESURÉE ET EXIGÉE.
 *
 * Deux objets distincts, nommés exactement :
 *   - « réservation » : appel au compteur de quota (reserver_demande()).
 *   - « demande »     : POST multipart HTTP RÉEL sur /tuc/v1/restaurant-applications,
 *                       à travers le serveur web, avec une photo.
 * Le test « dispatch interne » (rest_do_request) est un TROISIÈME objet,
 * volontairement séparé : il ne prouve rien sur l'upload HTTP.
 *
 * ISOLATION. Ce script ne devine aucune clé : il crée lui-même ses adresses de
 * test (bloc réservé 198.51.100.0/24 + suffixe aléatoire par exécution), vérifie
 * qu'AUCUNE clé de ce bloc n'existe déjà, et REFUSE de continuer en cas de
 * collision. Une clé témoin préexistante est posée hors de son périmètre : elle
 * ne doit jamais être touchée.
 *
 * GARDE. Refus (code 3) sans : marqueur `.tuc-bac-jetable`, accord explicite
 * (--bac-jetable ou TUC_BAC_JETABLE=1), et URL de site en localhost/127.0.0.1.
 * Note honnête : wp-load.php est chargé AVANT le refus (il faut lire l'URL et
 * les options). Ses effets se limitent à des lectures et au cache d'options en
 * mémoire ; aucune écriture de ce script n'a lieu avant l'accord. Les effets
 * propres à WordPress au chargement (cron opportuniste, transients) ne sont pas
 * annulables : d'où l'exigence d'un environnement réellement jetable.
 *
 * Usage :
 *   php limiteur-resilience.php <wordpress> <url-http> [--bac-jetable]
 */
$wpRoot = $argv[1] ?? '';
$urlHttp = rtrim((string) ($argv[2] ?? ''), '/');
if ($wpRoot === '' || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "usage : php limiteur-resilience.php <wordpress> <url-http> [--bac-jetable]\n");
    exit(2);
}

// Analyse par DRAPEAUX, jamais par position : l'ordre des options ne doit pas
// pouvoir décaler les arguments (défaut constaté : le sous-processus recevait
// le mode à la place de l'IP).
$opts = [];
$argLibres = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--bac-jetable') {
        $opts['bac'] = true;
    } elseif (preg_match('/^--(mode|ip|scenario|url|journal)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        $argLibres[] = $a;
    }
}
$wpRoot = $argLibres[0] ?? $wpRoot;
$urlHttp = rtrim((string) ($argLibres[1] ?? $urlHttp), '/');
$accord = isset($opts['bac']) || getenv('TUC_BAC_JETABLE') === '1';
// Le marqueur est cherché à DEUX endroits : la racine, puis wp-content/uploads.
// Motif mesuré en CI : dans le conteneur, wp-content appartient à l'uid 33 et
// seul uploads/ est ouvert en 1777 ; écrire à la racine est refusé (droits), ce
// qui faisait refuser l'exécution alors que le bac EST jetable.
$candidatsMarqueur = [$wpRoot . '/.tuc-bac-jetable', $wpRoot . '/wp-content/uploads/.tuc-bac-jetable'];
$marqueur = $candidatsMarqueur[0];
$marque = false;
foreach ($candidatsMarqueur as $candidat) {
    if (is_file($candidat)) {
        $marqueur = $candidat;
        $marque = true;
        break;
    }
    if (is_writable(dirname($candidat))) {
        $marqueur = $candidat; // emplacement retenu pour le test de garde
        break;
    }
}

unset($wp);
require $wpRoot . '/wp-load.php';
global $wpdb;

$urlSite = strtolower((string) get_option('home', ''));
$hostSite = strtolower((string) parse_url($urlSite, PHP_URL_HOST));
$local = in_array($hostSite, ['127.0.0.1', 'localhost', '::1'], true);
$envType = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

if (!$accord || !$marque || !$local) {
    fwrite(STDERR, "REFUS D'EXÉCUTION — script destructif, bac jetable uniquement.\n");
    fwrite(STDERR, '  site          : ' . ($urlSite !== '' ? $urlSite : '(inconnu)') . "\n");
    fwrite(STDERR, '  hôte local    : ' . ($local ? 'oui' : 'NON') . "\n");
    fwrite(STDERR, '  environnement : ' . $envType . "\n");
    fwrite(STDERR, '  marqueur      : ' . $marqueur . ' => ' . ($marque ? 'présent' : 'ABSENT') . "\n");
    fwrite(STDERR, '  accord        : ' . ($accord ? 'oui' : 'NON (--bac-jetable ou TUC_BAC_JETABLE=1)') . "\n");
    fwrite(STDERR, "Aucune écriture effectuée par ce script.\n");
    exit(3);
}

// -------------------------------------------------------------------------
// Identifiants ISOLÉS : bloc réservé aux tests + suffixe tiré au hasard.
// Aucune clé n'est calculée « au hasard de l'IP » : elles sont toutes dérivées
// de ce suffixe, donc aucune ne peut appartenir à un site réel.
// -------------------------------------------------------------------------
$suffixe = substr(bin2hex(random_bytes(6)), 0, 10);
$plage = '198.51.100.'; // RFC 5737, réservée à la documentation
$crees = [];
$ecrireIdentifiants = static function (string $ip): array {
    return [
        'tuc_demandes_' . md5($ip),
        'tuc_demandes_' . md5($ip) . '_verrou',
        'tuc_tentatives_' . md5($ip),
        'tuc_tentatives_' . md5($ip) . '_verrou',
    ];
};
// Trois blocs RFC 5737 (documentation) : aucun trafic réel ne peut en venir.
$blocs = ['198.51.100.', '192.0.2.', '203.0.113.'];
// Compteur GLOBAL strictement croissant : deux appels ne peuvent pas produire
// la même adresse (défaut constaté : ipDe(400) et ipDe(1000) tombaient sur
// 203.0.113.20 tous les deux, d'où une « collision » en section 3).
$compteurIp = 0;
$ipDe = static function (int $n = 0) use ($blocs, &$compteurIp): string {
    $compteurIp++;
    $bloc = $blocs[$compteurIp % 3];
    $position = intdiv($compteurIp, 3) % 200;
    return $bloc . ($position + 20); // jamais .0/.255
};

/**
 * Déclare un lot d'IP : vérifie d'abord qu'AUCUNE clé n'existe déjà, et refuse
 * en cas de collision. Aucune clé préexistante n'est jamais supprimée.
 */
$declarerLot = static function (array $ips) use ($wpdb, $ecrireIdentifiants, &$crees): bool {
    $toutes = [];
    foreach ($ips as $ip) {
        foreach ($ecrireIdentifiants($ip) as $cle) {
            $toutes[] = $cle;
        }
    }
    if ($toutes === []) {
        return true;
    }
    $existantes = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name IN (" . implode(',', array_fill(0, count($toutes), '%s')) . ')',
        ...$toutes
    ));
    if ($existantes > 0) {
        return false; // collision : on ne touche à rien
    }
    foreach ($toutes as $cle) {
        $crees[] = $cle;
    }
    return true;
};

$ko = 0;
$ok = static function (bool $c, string $m) use (&$ko): void {
    echo ($c ? '  OK    ' : '  ECHEC ') . "— $m\n";
    if (!$c) {
        $ko++;
    }
};

// Nettoyage : uniquement les clés déclarées, jamais un motif LIKE.
register_shutdown_function(static function () use ($wpdb, &$crees): void {
    foreach ($crees as $cle) {
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $cle));
    }
});

$mode = (string) ($opts['mode'] ?? 'orchestrateur');
$sousProcessus = in_array($mode, ['reservation', 'demande-http'], true);

// L'ADRESSE VUE PAR LE MODULE EST LE CŒUR DU TEST. Le module limite sur
// `REMOTE_ADDR` ; sans cette ligne, chaque sous-processus n'avait AUCUNE adresse
// (bac : aucune limitation, 144/144 acceptées) ou héritait de 127.0.0.1 pour
// tous (CI : 144 réservations partageant un quota de 3, 24 refus). Les deux
// rendaient les assertions « par IP » fausses. On la fixe donc explicitement.
// En mode demande-http, c'est l'adresse du CLIENT qui compte : curl l'envoie au
// serveur, et c'est le serveur qui l'applique. Écraser REMOTE_ADDR ici n'aurait
// aucun effet sur la requête HTTP et masquerait la vraie valeur.
if ($mode !== 'demande-http') {
    $ipSimulee = ($opts['ip'] ?? '') !== '' && strpos((string) $opts['ip'], '::') === false ? $opts['ip'] : '127.0.0.1';
    if (filter_var($ipSimulee, FILTER_VALIDATE_IP) !== false) {
        $_SERVER['REMOTE_ADDR'] = (string) $ipSimulee;
    }
}
$ipSous = (string) ($opts['ip'] ?? '');
$scenarioSous = (string) ($opts['scenario'] ?? 'aucun');
$urlSous = rtrim((string) ($opts['url'] ?? $urlHttp), '/');

// -------------------------------------------------------------------------
// Effets à bord : journalisés dans un fichier pour être COMPTÉS, pas supposés.
// -------------------------------------------------------------------------
// Le sous-processus a son PROPRE suffixe aléatoire : le journal doit donc lui
// être transmis explicitement, sinon il écrit ailleurs et les compteurs
// d'événements tombent à zéro (défaut constaté).
$journal = (string) ($opts['journal'] ?? (sys_get_temp_dir() . '/tuc-res-' . $suffixe . '.log'));

if ($sousProcessus) {
    $ip = $ipSous;
    $scenario = $scenarioSous;
    $urlBase = $urlSous;
    // Le mode reservation tourne EN PROCESSUS : c'est ici que l'adresse simulée
    // prend effet. Sans elle, le module ne voyait aucune adresse et toutes les
    // « IP distinctes » partageaient le même compteur.
    if ($mode === 'reservation') {
        $_SERVER['REMOTE_ADDR'] = $ip;
    }

    // Le témoin est TOUJOURS posé (même sans injection) : sans lui, un deadlock
    // réel survenu pendant une rafale passe inaperçu et le compteur tombe à zéro.
    // Défaut constaté : 16 deadlocks côté serveur, 0 dans mon journal.
    {
        $deadlockVu = false;
        add_filter('query', static function (string $q) use (&$deadlockVu, $scenario, $journal): string {
            $err = (string) ($GLOBALS['wpdb']->last_error ?? '');
            if (stripos($err, 'Deadlock') !== false || stripos($err, 'try restarting') !== false) {
                $deadlockVu = true;
                @file_put_contents($journal, "deadlock\n", FILE_APPEND);
            }
            $ecriture = (stripos(ltrim($q), 'UPDATE') === 0 || stripos(ltrim($q), 'INSERT') === 0)
                && strpos($q, 'tuc_demandes_') !== false
                && strpos($q, '_verrou') === false;
            if (!$ecriture) {
                return $q;
            }
            // 'panne' : une seule injection par processus.
            // 'panne-apres-reprise' : injection UNIQUEMENT après un deadlock vu.
            if ($scenario === 'panne' || ($scenario === 'panne-apres-reprise' && $deadlockVu)) {
                $deadlockVu = false;
                @file_put_contents($journal, "injection\n", FILE_APPEND);
                return "UPDATE `{$GLOBALS['wpdb']->prefix}table_absente_du_test` SET option_value = 1";
            }
            return $q;
        }, 10, 1);
    }

    if ($mode === 'reservation') {
        $ref = new ReflectionMethod('TUC_Onboarding_Restaurant', 'reserver_demande');
        $ref->setAccessible(true);
        $r = $ref->invoke(null);
        if (!is_wp_error($r)) {
            echo "reserve\n";
            exit(0);
        }
        echo $r->get_error_code(), "\n";
        // DEUXIÈME LIGNE = la classification interne du refus. Un refus 503
        // `tuc_onboarding_storage` n'a de sens que comme PANNE ; s'il est produit
        // par un conflit transitoire, ces trois champs le disent (cause,
        // transaction perdue, dernier message SQL). C'est ce détail qui a permis
        // de trouver, en trois itérations CI, les chemins qui déclaraient une
        // panne là où il fallait rejouer.
        $rc = new ReflectionClass('TUC_Onboarding_Restaurant');
        $lire = static function (string $nom) use ($rc) {
            $p = $rc->getProperty($nom);
            $p->setAccessible(true);
            return $p->getValue();
        };
        echo 'cause=' . (string) $lire('causeRefus')
            . ' perdue=' . ($lire('transactionPerdue') ? '1' : '0')
            . ' panne=' . ($lire('refusParPanne') ? '1' : '0')
            . ' sql=' . substr(preg_replace('/\s+/', ' ', (string) $lire('derniereErreurSql')), 0, 90), "\n";
        exit(0);
    }

    // ---- demande par HTTP MULTIPART RÉEL (pas rest_do_request) ------------
    // La panne est injectée CÔTÉ SERVEUR par un mu-plugin de bac piloté par le
    // fichier /tmp/tuc-injection.flag : un filtre posé dans ce processus CLI
    // n'atteindrait pas le processus qui sert la requête.
    $drapeauInjection = getenv('TUC_INJECTION_FLAG') ?: '/tmp/tuc-injection.flag';
    if ($scenario !== 'aucun' && is_writable(dirname($drapeauInjection))) {
        @file_put_contents($drapeauInjection, $scenario);
    } else {
        @unlink($drapeauInjection);
    }
    $jpg = sys_get_temp_dir() . '/tuc-photo-' . $suffixe . '.jpg';
    if (!is_file($jpg)) {
        $im = imagecreatetruecolor(320, 240);
        imagejpeg($im, $jpg, 80);
        imagedestroy($im);
    }
    // Repérage : requête VOLONTAIREMENT invalide (sans photo). Elle ne crée
    // aucune demande, donc ne consomme aucun quota ; elle ne touche que le
    // compteur de TENTATIVES, qui porte la même empreinte d'adresse.
    $champs = ['restaurant_name' => 'Bac ' . $ip . ' ' . $suffixe];
    if ($scenario !== 'reperage') {
        $champs['menu_photo'] = new CURLFile($jpg, 'image/jpeg', 'menu.jpg');
    }
    $ch = curl_init($urlBase . '/wp-json/tuc/v1/restaurant-applications');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $champs,
    ]);
    $corps = (string) curl_exec($ch);
    $statut = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreurCurl = (string) curl_error($ch);
    curl_close($ch);
    $json = json_decode($corps, true);
    $code = is_array($json) && isset($json['code']) ? (string) $json['code'] : '-';
    // Le corps est joint : sans lui, « 0 - » ne dit pas si curl a échoué, si le
    // site a rendu une page d'erreur, ou si la route a répondu autre chose.
    $extrait = trim(preg_replace('/\s+/', ' ', substr($corps, 0, 160)));
    $erreur = $erreurCurl !== '' ? ' curl=' . $erreurCurl : '';
    echo $statut . ' ' . $code . ' |' . $extrait . '|' . $erreur . "\n";
    exit(0);
}

// -------------------------------------------------------------------------
// Orchestrateur
// -------------------------------------------------------------------------
@unlink($journal);
$detailsRefus = [];
$lancer = static function (array $ips, string $mode, string $scenario = 'aucun') use ($wpRoot, &$urlHttp, $journal, &$detailsRefus): array {
    $procs = [];
    foreach ($ips as $ip) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
            . escapeshellarg($wpRoot) . ' ' . escapeshellarg($urlHttp) . ' --bac-jetable '
            . escapeshellarg('--mode=' . $mode) . ' ' . escapeshellarg('--ip=' . $ip) . ' '
            . escapeshellarg('--scenario=' . $scenario) . ' ' . escapeshellarg('--url=' . $urlHttp)
            . ' ' . escapeshellarg('--journal=' . $journal)
            . ' 2>/dev/null';
        $procs[$ip] = popen($cmd, 'r');
    }
    $sortie = [];
    foreach ($procs as $ip => $h) {
        $brut = trim((string) stream_get_contents($h));
        pclose($h);
        $lignes = preg_split('/\R+/', $brut) ?: [];
        $sortie[$ip] = trim((string) ($lignes[0] ?? ''));
        // Le verdict reste la PREMIÈRE ligne (les assertions comparent ce
        // verdict) ; les lignes suivantes sont des détails, comptés à part.
        foreach (array_slice($lignes, 1) as $detail) {
            $detail = trim($detail);
            if ($detail !== '') {
                $detailsRefus[$detail] = ($detailsRefus[$detail] ?? 0) + 1;
            }
        }
    }
    return $sortie;
};

/** État PAR IP : quota consommé, verrou résiduel, demande créée. */
$etatParIp = static function (array $ips) use ($wpdb, $ecrireIdentifiants): array {
    $etat = [];
    foreach ($ips as $ip) {
        $cles = $ecrireIdentifiants($ip);
        $lignes = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM `{$wpdb->options}` WHERE option_name IN (%s,%s,%s,%s)",
            ...$cles
        ), ARRAY_A);
        $quota = 0;
        $verrou = 0;
        foreach ((array) $lignes as $l) {
            if (substr((string) $l['option_name'], -7) === '_verrou') {
                $verrou++;
            } elseif (strpos((string) $l['option_name'], 'tuc_demandes_') === 0) {
                $quota = (int) explode('|', (string) $l['option_value'])[0];
            }
        }
        $etat[$ip] = ['quota' => $quota, 'verrou' => $verrou];
    }
    return $etat;
};

$compteEvenements = static function (string $motif) use ($journal): int {
    $total = 0;
    foreach ([$journal, '/tmp/tuc-injection.log'] as $f) {
        if (is_file($f)) {
            $total += substr_count((string) file_get_contents($f), $motif);
        }
    }
    return $total;
};
$compteDemandes = static function (): int {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tuc_demande_resto'");
};
$comptePieces = static function (): int {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
};

// =========================================================================
echo "=== 0. Gardes d'environnement : chaque condition est testée séparément ===\n";
$testerGarde = static function (array $args, ?string $env = null): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $sortie = [];
    $code = 0;
    // La variable d'environnement est RETIRÉE explicitement : si le lanceur
    // (CI, runner de régression) exporte TUC_BAC_JETABLE=1, le sous-processus
    // hériterait de l'accord et le test de garde passerait à tort.
    $prefixe = $env === null
        ? 'env -u TUC_BAC_JETABLE '
        : ('TUC_BAC_JETABLE=' . escapeshellarg($env) . ' ');
    exec($prefixe . $cmd, $sortie, $code);
    return [$code, implode(' ', $sortie)];
};
[$c0, $t0] = $testerGarde([$wpRoot, $urlHttp]);            // ni accord ni marqueur
$ok($c0 === 3 && strpos($t0, "REFUS D'EXÉCUTION") !== false, 'sans accord : refus code 3 (' . $c0 . ')');
[$c1, $t1] = $testerGarde([$wpRoot, $urlHttp], '0');       // accord explicite absent
$ok($c1 === 3, 'accord à 0 : refus (' . $c1 . ')');
// marqueurabsent : on le retire temporairement
$sauvegarde = is_file($marqueur) ? (string) file_get_contents($marqueur) : null;
if (is_file($marqueur)) {
    unlink($marqueur);
}
[$c2, $t2] = $testerGarde([$wpRoot, $urlHttp, '--bac-jetable']);
$ok($c2 === 3 && strpos($t2, 'marqueur') !== false, 'sans marqueur : refus malgré l’accord (' . $c2 . ')');
if ($sauvegarde !== null) {
    file_put_contents($marqueur, $sauvegarde);
    $ok(true, 'marqueur restauré après le test de garde');
}

// =========================================================================
echo "\n=== 0b. Clé TÉMOIN préexistante : elle doit survivre à tout le test ===\n";
$temoinCle = 'tuc_demandes_' . md5('tuc-temoin-preexistant');
$wpdb->query($wpdb->prepare(
    "INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
    $temoinCle,
    '7|' . (time() + 1800) . '|123456|9'
));
$crees[] = $temoinCle; // autorisé : c'est nous qui l'avons créée
$valeurTemoinAvant = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $temoinCle));

// Collision : on pose une clé du lot d'IP du test, hors déclaration.
$ipCollision = $ipDe(0);
$cleCollision = $ecrireIdentifiants($ipCollision)[0];
$wpdb->query($wpdb->prepare(
    "INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
    $cleCollision,
    '5|' . (time() + 1800) . '|999|9'
));
$collisionDetectee = !$declarerLot([$ipCollision]);
$ok($collisionDetectee, 'une clé préexistante dans le lot est DÉTECTÉE et fait refuser le lot');
$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $cleCollision));

// =========================================================================
$detailsRefus = [];
echo "\n=== 1. RÉSERVATIONS isolées : 96 IP distinctes (aucune contention attendue) ===\n";
$acceptes = 0;
$refus = [];
$toutesIps = [];
$NB_RAFALES_1 = 8; // mesuré : à 4 rafales, le deadlock n'est pas systématique
for ($rafale = 1; $rafale <= $NB_RAFALES_1; $rafale++) {
    $ips = [];
    for ($i = 1; $i <= 12; $i++) {
        $ips[] = $ipDe(($rafale - 1) * 12 + $i);
    }
    if (!$declarerLot($ips)) {
        $ok(false, 'collision inattendue sur le lot ' . $rafale);
        break;
    }
    $toutesIps = array_merge($toutesIps, $ips);
    foreach ($lancer($ips, 'reservation') as $r) {
        if ($r === 'reserve') {
            $acceptes++;
        } else {
            $refus[$r] = ($refus[$r] ?? 0) + 1;
        }
    }
}
$etat1 = $etatParIp($toutesIps);
$attendu1 = $NB_RAFALES_1 * 12;
// Deux refus à ne JAMAIS confondre :
//   - tuc_onboarding_storage (503) : le service se déclare EN PANNE. Sous simple
//     contention, une seule occurrence est un défaut — c'est exactement ce que la
//     CI avait mesuré (23 et 27 refus sur 96/144) quand ecrire_compteur() marquait
//     l'état « conflit » comme une panne.
//   - tuc_onboarding_occupe (503 + retry) : la base demande de rejouer. Filt
//     légitime, aucune réservation perdue, aucune unité consommée deux fois.
$refusPanne1 = (int) ($refus['tuc_onboarding_storage'] ?? 0);
$refusFile1 = (int) ($refus['tuc_onboarding_occupe'] ?? 0);
$ok($refusPanne1 === 0, 'aucun refus « panne » (tuc_onboarding_storage) sous contention : ' . $refusPanne1);
$ok($acceptes + $refusFile1 === $attendu1,
    'aucune réservation perdue : ' . $acceptes . ' acceptées + ' . $refusFile1 . ' à rejouer = ' . $attendu1);
$ok($acceptes >= (int) round($attendu1 * 0.9),
    'taux d\'acceptation en cas d\'affluence : ' . $acceptes . '/' . $attendu1);
echo '    detail 1 : ' . $acceptes . ' acceptees, refus ' . json_encode($refus) . "\n";
if ($detailsRefus !== []) {
    echo '    classification des refus : ' . json_encode(array_slice($detailsRefus, 0, 4, true), JSON_UNESCAPED_UNICODE) . "\n";
}
$ok(max(array_map(static fn ($e) => $e['quota'], $etat1)) <= 1, 'aucune IP au-dessus de 1 unité de quota');
$ok(count(array_filter($etat1, static fn ($e) => $e['verrou'] > 0)) === 0, 'aucun verrou résiduel, par IP');

// =========================================================================
$detailsRefus = [];
echo "\n=== 1b. CONTENTION RÉELLE : 144 réservations concurrentes — deadlock EXIGÉ ===\n";
// Constat mesuré : des IP DISTINCTES lancées par petits lots ne se croisent pas
// (96 réservations, 0 deadlock). La contention vient du NOMBRE de processus
// simultanés sur la même table, pas du partage d'une clé — et une même IP est de
// toute façon plafonnée à 3 par heure, ce qui coupe la contention.
// Ici : 12 rafales de 12 IP distinctes, sans injection, exactement la
// configuration qui a produit 21 deadlocks réels.
@unlink($journal);
@unlink('/tmp/tuc-injection.log');
$avantDeadlocks = (int) $wpdb->get_var("SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'INNODB_DEADLOCKS'");
$ipsContention = [];
$acceptesContention = 0;
$refusContention = [];
for ($rafale = 1; $rafale <= 12; $rafale++) {
    $lot = [];
    for ($i = 1; $i <= 12; $i++) {
        $lot[] = $ipDe(1000 + ($rafale - 1) * 12 + $i);
    }
    if (!$declarerLot($lot)) {
        $ok(false, 'collision inattendue sur le lot ' . $rafale);
        break;
    }
    $ipsContention = array_merge($ipsContention, $lot);
    foreach ($lancer($lot, 'reservation') as $r) {
        if ($r === 'reserve') {
            $acceptesContention++;
        } else {
            $refusContention[$r] = ($refusContention[$r] ?? 0) + 1;
        }
    }
}
// La variable globale n'est pas exposée par cette MariaDB : on retombe sur le
// témoin du journal, rempli par le filtre quand une erreur « deadlock » est vue.
$deadlocksContention = $compteEvenements('deadlock');
$etatContention = $etatParIp($ipsContention);
$auDessusContention = array_filter($etatContention, static fn ($e) => $e['quota'] > 1);
$ok($deadlocksContention > 0, 'au moins un DEADLOCK RÉEL observé : ' . $deadlocksContention . ' (échoue si 0)');
$ok((int) ($refusContention['tuc_onboarding_storage'] ?? 0) === 0,
    'aucun refus « panne » (tuc_onboarding_storage) en rafale : ' . (int) ($refusContention['tuc_onboarding_storage'] ?? 0));
$ok($acceptesContention + (int) ($refusContention['tuc_onboarding_occupe'] ?? 0) === 144,
    'aucune réservation perdue : ' . $acceptesContention . ' acceptées + '
    . (int) ($refusContention['tuc_onboarding_occupe'] ?? 0) . ' à rejouer = 144');
$ok($auDessusContention === [], 'AUCUNE IP au-dessus de 1 unité (aucune double consommation) : ' . count($auDessusContention) . ' anomalie(s)');
$ok(count(array_filter($etatContention, static fn ($e) => $e['verrou'] > 0)) === 0, 'aucun verrou résiduel');
echo '    refus observés : ' . json_encode($refusContention) . "\n";
if ($detailsRefus !== []) {
    echo '    classification des refus : ' . json_encode(array_slice($detailsRefus, 0, 4, true), JSON_UNESCAPED_UNICODE) . "\n";
}

// =========================================================================
echo "\n=== 2. RÉSERVATION, panne non récupérable : refus, rien de consommé ===\n";
@unlink($journal);
$ips2 = [$ipDe(300)];
$declarerLot($ips2);
$r2 = $lancer($ips2, 'reservation', 'panne')[$ips2[0]] ?? '?';
$ok($r2 === 'tuc_onboarding_storage', 'vraie panne => tuc_onboarding_storage : ' . $r2);
$ok($compteEvenements('injection') >= 1, 'au moins une injection a bien eu lieu (' . $compteEvenements('injection') . ')');
$etat2 = $etatParIp($ips2);
$ok(($etat2[$ips2[0]]['quota'] ?? 0) === 0, 'aucune unité de quota consommée par ce refus');
$ok(($etat2[$ips2[0]]['verrou'] ?? 0) === 0, 'aucun verrou résiduel');

// =========================================================================
$detailsRefus = [];
echo "\n=== 3. RÉSERVATION, injection APRÈS reprise : événements EXIGÉS ===\n";
@unlink($journal);
$acceptes3 = 0;
$refus3 = [];
$ips3 = [];
for ($rafale = 1; $rafale <= 12; $rafale++) {
    $lot = [];
    for ($i = 1; $i <= 12; $i++) {
        $lot[] = $ipDe(400 + ($rafale - 1) * 12 + $i);
    }
    if (!$declarerLot($lot)) {
        $ok(false, 'collision inattendue sur le lot ' . $rafale);
        break;
    }
    $ips3 = array_merge($ips3, $lot);
    foreach ($lancer($lot, 'reservation', 'panne-apres-reprise') as $r) {
        if ($r === 'reserve') {
            $acceptes3++;
        } else {
            $refus3[$r] = ($refus3[$r] ?? 0) + 1;
        }
    }
}
$deadlocks3 = $compteEvenements('deadlock');
$injections3 = $compteEvenements('injection');
$ok($deadlocks3 > 0, 'deadlocks RÉELS observés : ' . $deadlocks3 . ' (échoue si 0)');
$ok($injections3 > 0, 'injections APRÈS reprise réellement déclenchées : ' . $injections3 . ' (échoue si 0)');
$etat3 = $etatParIp($ips3);
$auDessus = array_filter($etat3, static fn ($e) => $e['quota'] > 1);
$ok($auDessus === [], 'AUCUNE IP au-dessus de 1 unité de quota (' . count($auDessus) . ' anomalie(s))');
$somme3 = array_sum(array_map(static fn ($e) => $e['quota'], $etat3));
$ok($somme3 === $acceptes3, "unités de quota = réservations acceptées ($somme3 = $acceptes3)");
$ok(count(array_filter($etat3, static fn ($e) => $e['verrou'] > 0)) === 0, 'aucun verrou résiduel, par IP');
echo '    refus observés : ' . json_encode($refus3) . "\n";

// =========================================================================
echo "\n=== 4. RÉSERVATION, quota même IP : 3 acceptées puis refus ===\n";
$ips4 = [$ipDe(700)];
$declarerLot($ips4);
$t4 = [];
for ($k = 0; $k < 6; $k++) {
    $t4[] = $lancer($ips4, 'reservation')[$ips4[0]] ?? '?';
}
$ok(count(array_keys($t4, 'reserve', true)) === 3, '3 acceptées (' . count(array_keys($t4, 'reserve', true)) . ')');
$ok(count(array_keys($t4, 'tuc_onboarding_quota', true)) === 3, 'puis 3 refus de quota (' . count(array_keys($t4, 'tuc_onboarding_quota', true)) . ')');
$ok(($etatParIp($ips4)[$ips4[0]]['quota'] ?? 0) === 3, 'quota final de cette IP = 3');

// =========================================================================
// =========================================================================
// SERVEUR HTTP : cette section exige un VRAI POST multipart traversant un
// serveur web. Mesure en CI (run 42912776264, sha 660aa2c) : dans le conteneur
// `wpcli`, rien n'écoute sur 127.0.0.1:8080 — Apache vit dans l'AUTRE
// conteneur — et le repérage retombait sur « 0 - | curl=Failed to connect ».
// Le test ne se contente plus de déclarer la section non exécutable : il
// démarre lui-même un serveur (php -S) sur le document-root de l'installation,
// le pilote, puis l'arrête. Ce serveur est un MOYEN jetable, pas une donnée de
// l'environnement ; son usage est dit dans la sortie pour que le lecteur sache
// contre quoi les requêtes ont réellement été jouées.
$sondeHttp = static function (string $base): bool {
    if ($base === '') {
        return false;
    }
    $ch = curl_init(rtrim($base, '/') . '/wp-json/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
    curl_exec($ch);
    $statut = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $statut > 0;
};
$urlHttpAvant = $urlHttp;
$tenir = (object) ['proc' => null, 'routeur' => '', 'log' => '', 'port' => 0, 'ombre' => '', 'muPlugin' => ''];
register_shutdown_function(static function () use ($tenir): void {
    if (is_resource($tenir->proc)) {
        proc_terminate($tenir->proc);
        proc_close($tenir->proc);
    }
    if ($tenir->routeur !== '') {
        @unlink($tenir->routeur);
    }
    // La racine ombre est jetable : liens, mu-plugin de test et dossiers sont
    // démontés, l'installation réelle n'a jamais été touchée.
    if ($tenir->muPlugin !== '') {
        @unlink($tenir->muPlugin);
    }
    if ($tenir->ombre !== '') {
        foreach (['plugins', 'themes', 'uploads', 'languages'] as $dossier) {
            $lien = $tenir->ombre . '/wp-content/' . $dossier;
            if (is_link($lien)) {
                @unlink($lien);
            }
        }
        @rmdir($tenir->ombre . '/wp-content/mu-plugins');
        @rmdir($tenir->ombre . '/wp-content');
        @rmdir($tenir->ombre);
    }
    if ($tenir->log !== '') {
        @unlink($tenir->log);
    }
});
if (!$sondeHttp($urlHttp)) {
    // --- RACINE OMBRE, pour pouvoir injecter CÔTÉ SERVEUR -------------------
    // La panne de l'étape (b) doit frapper le processus qui sert la requête :
    // un filtre posé dans ce processus CLI ne suffit pas. Le moyen usuel est un
    // mu-plugin dans `wp-content` — mais écrire dans le `wp-content` d'une
    // installation qui n'est pas la nôtre est exclu (mesuré en CI : il
    // appartient à uid 33, le test tourne en uid 82). On monte donc une racine
    // ombre dans /tmp : notre `wp-content`, avec `plugins`, `themes`, `uploads`
    // et `languages` RELIÉS par symlink à l'installation réelle, plus un
    // `mu-plugins` à nous. Rien n'est écrit dans l'installation : les photos
    // passent par le lien et atterrissent dans le vrai `wp-content/uploads`,
    // exactement là où les assertions vont les chercher.
    $ombre = sys_get_temp_dir() . '/tuc-ombre-' . getmypid();
    $tenir->ombre = $ombre;
    @mkdir($ombre . '/wp-content/mu-plugins', 0777, true);
    foreach (['plugins', 'themes', 'uploads', 'languages'] as $dossier) {
        if (is_dir($wpRoot . '/wp-content/' . $dossier) && !file_exists($ombre . '/wp-content/' . $dossier)) {
            @symlink($wpRoot . '/wp-content/' . $dossier, $ombre . '/wp-content/' . $dossier);
        }
    }
    $tenir->muPlugin = $ombre . '/wp-content/mu-plugins/tuc-injection-bac.php';
    file_put_contents($tenir->muPlugin, "<?php\n"
        . "// INSTRUMENTATION DE TEST — générée par " . basename(__FILE__) . ", détruite à la fin de la section.\n"
        . "// Lit un drapeau et, selon sa valeur, remplace l'écriture du compteur\n"
        . "// par une requête vouée à l'échec. Elle ne touche AUCUNE donnée existante.\n"
        . "\$drapeau = getenv('TUC_INJECTION_FLAG') ?: '/tmp/tuc-injection.flag';\n"
        . "\$journal = " . var_export($journal, true) . ";\n"
        . "if (!is_file(\$drapeau)) { return; }\n"
        . "\$mode = trim((string) file_get_contents(\$drapeau));\n"
        . "if (\$mode === '') { return; }\n"
        . "\$deadlockVu = false;\n"
        . "add_filter('query', static function (string \$q) use (&\$deadlockVu, \$mode, \$journal): string {\n"
        . "    \$err = (string) (\$GLOBALS['wpdb']->last_error ?? '');\n"
        . "    if (stripos(\$err, 'Deadlock') !== false || stripos(\$err, 'try restarting') !== false) {\n"
        . "        \$deadlockVu = true;\n"
        . "        @file_put_contents(\$journal, \"deadlock\\n\", FILE_APPEND);\n"
        . "    }\n"
        . "    \$ecriture = (stripos(ltrim(\$q), 'UPDATE') === 0 || stripos(ltrim(\$q), 'INSERT') === 0)\n"
        . "        && strpos(\$q, 'tuc_demandes_') !== false && strpos(\$q, '_verrou') === false;\n"
        . "    if (!\$ecriture) { return \$q; }\n"
        . "    if (\$mode === 'panne' || (\$mode === 'panne-apres-reprise' && \$deadlockVu)) {\n"
        . "        \$deadlockVu = false;\n"
        . "        @file_put_contents(\$journal, \"injection\\n\", FILE_APPEND);\n"
        . "        return \"UPDATE \\`{\" . \$GLOBALS['wpdb']->prefix . \"}table_absente_du_test\\` SET option_value = 1\";\n"
        . "    }\n"
        . "    return \$q;\n"
        . "}, 10, 1);\n");
    $tenir->routeur = sys_get_temp_dir() . '/tuc-routeur-' . getmypid() . '.php';
    // Routeur : servir le fichier s'il existe, sinon laisser WordPress répondre
    // (le serveur intégré de PHP ne lit pas .htaccess), en pointant le contenu
    // vers la racine ombre.
    file_put_contents($tenir->routeur, "<?php\n\$racine = " . var_export($wpRoot, true) . ";\n\$ombre = " . var_export($ombre, true) . ";\nif (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', \$ombre . '/wp-content'); }\n\$chemin = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';\nif (\$chemin !== '/' && is_file(\$racine . \$chemin)) { return false; }\nrequire \$racine . '/index.php';\n");
    $tenir->log = sys_get_temp_dir() . '/tuc-serveur-' . getmypid() . '.log';
    for ($essai = 0; $essai < 8 && !is_resource($tenir->proc); $essai++) {
        $ecoute = @stream_socket_server('tcp://127.0.0.1:0', $errNo, $errStr);
        if ($ecoute === false) {
            break;
        }
        $nomSocket = (string) stream_socket_get_name($ecoute, false);
        $port = (int) substr($nomSocket, strrpos($nomSocket, ':') + 1);
        fclose($ecoute);
        $proc = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $wpRoot, $tenir->routeur],
            [1 => ['file', $tenir->log, 'a'], 2 => ['file', $tenir->log, 'a']],
            $pipes
        );
        if (!is_resource($proc)) {
            continue;
        }
        $pret = false;
        for ($t = 0; $t < 60; $t++) {
            usleep(250000); // 15 s maximum
            if ($sondeHttp('http://127.0.0.1:' . $port)) {
                $pret = true;
                break;
            }
            $etat = proc_get_status($proc);
            if (!$etat['running']) {
                break;
            }
        }
        if ($pret) {
            $tenir->proc = $proc;
            $tenir->port = $port;
            $urlHttp = 'http://127.0.0.1:' . $port;
        } else {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
    $ok(is_resource($tenir->proc), 'serveur HTTP pour le multipart réel : '
        . (is_resource($tenir->proc)
            ? 'auto-démarré par le test sur 127.0.0.1:' . $tenir->port . ' (php -S, document-root ' . $wpRoot . '), l\'URL fournie (' . $urlHttpAvant . ') ne répondait pas'
            : 'INDISPONABLE — ni ' . $urlHttpAvant . ' ni un serveur auto ne répond : la section 5 ne peut pas conclure'));
} else {
    echo '    serveur HTTP : celui de l\'environnement (' . $urlHttp . "), sondé sur /wp-json/\n";
}

echo "\n=== 5. DEMANDE par HTTP MULTIPART RÉEL (serveur web, pas rest_do_request) ===\n";
// PORTÉE EXACTE, et ses limites :
//   - ce qui est traversé : curl -> serveur web -> WordPress -> route REST -> module,
//     avec un vrai corps multipart et une vraie photo ;
//   - ce qui n'est PAS prouvé ici : le deadlock. Les requêtes HTTP séquentielles
//     ne se croisent pas, donc aucun verrou ne s'affronte. La preuve du deadlock
//     est faite au scénario 3, sur la MÊME section critique (c'est le même code).
// L'IP vue par le serveur est celle du client (127.0.0.1 en bac local, autre
// chose dans le conteneur) : le compteur est DÉCOUVERT par une requête de
// repérage, jamais supposé. Aucune clé existante n'est supprimée : si le bac a
// déjà servi pour cette adresse, le scénario est SAUTÉ et déclaré non concluant.
@unlink($journal);
@unlink('/tmp/tuc-injection.log');
global $wpdb;
$ipHttp = $ipDe(800);
$declarerLot([$ipHttp]);
// Le serveur web local voit le client comme 127.0.0.1 : c'est CETTE adresse que
// le module limite (5 tentatives par heure). Le script ne supprime AUCUNE de ces
// clés — ce serait exactement le défaut reproché. Il vérifie à la place que le
// compteur est vierge et refuse de conclure sur un bac déjà utilisé.
$cleDemandesLoopback = 'tuc_demandes_' . md5('127.0.0.1');
$cleTentativesLoopback = 'tuc_tentatives_' . md5('127.0.0.1');
$dejaUtilise = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name IN (%s,%s,%s,%s)",
    $cleDemandesLoopback,
    $cleDemandesLoopback . '_verrou',
    $cleTentativesLoopback,
    $cleTentativesLoopback . '_verrou'
));
$ok($dejaUtilise === 0, 'bac frais : compteurs (quota + anti-abus) de 127.0.0.1 vierges (' . $dejaUtilise . ' clé(s))');
if ($dejaUtilise > 0) {
    echo "    -> le scénario HTTP est SAUTÉ, pas contourné : un bac neuf est requis.\n";
}
// DÉCOUVERTE, PAS SUPPOSITION : le module limite sur `REMOTE_ADDR` vu par le
// serveur. L'adresse du client dans le conteneur n'est pas forcément 127.0.0.1
// (mesuré : série verte en bac local, §5 à « 0 » en CI). On envoie UNE requête
// de repérage, puis on retient le compteur de demandes qui a réellement bougé.
$requete = static function (string $scenario) use ($lancer, $ipHttp): string {
    return $lancer([$ipHttp], 'demande-http', $scenario)[$ipHttp] ?? '?';
};

$cleDemandes = null;
$scenarioHttpPossible = ($dejaUtilise === 0);
if (!$scenarioHttpPossible) {
    echo "    -> compteurs de 127.0.0.1 déjà présents : le scénario HTTP est SAUTÉ, pas contourné.\n";
}
if ($scenarioHttpPossible) {
    // Découverte par le compteur de TENTATIVES : la requête de repérage n'a pas
    // de photo, donc elle ne crée aucune demande (quota intact) mais elle
    // incrémente les tentatives de CETTE adresse. L'empreinte md5 est la même
    // dans les deux noms : elle nous donne la clé de quota sans la supposer.
    $tentativesAvant = [];
    foreach ((array) $wpdb->get_results("SELECT option_name FROM `{$wpdb->options}` WHERE option_name LIKE 'tuc_tentatives_%'") as $r) {
        $tentativesAvant[(string) $r->option_name] = true;
    }
    $reperage = $requete('reperage');
    $nouvelles = [];
    foreach ((array) $wpdb->get_col("SELECT option_name FROM `{$wpdb->options}` WHERE option_name LIKE 'tuc_tentatives_%'") as $nom) {
        if (!isset($tentativesAvant[(string) $nom])) {
            $nouvelles[] = (string) $nom;
        }
    }
    if (count($nouvelles) === 1 && preg_match('/^tuc_tentatives_([a-f0-9]{32})$/', $nouvelles[0], $m)) {
        $cleDemandes = 'tuc_demandes_' . $m[1];
        echo '    adresse vue par le serveur : empreinte ' . $m[1] . ' (repérage : ' . $reperage . ")\n";
        echo '    compteur de demandes correspondant : ' . $cleDemandes . "\n";
    } else {
        echo '    repérage non concluant (' . count($nouvelles) . " nouvelle(s) empreinte(s)) : " . $reperage . "\n";
        $scenarioHttpPossible = false;
    }
    // Le repérage n'a créé AUCUNE demande : le compteur de quota doit être absent
    // ou vide. S'il porte déjà quelque chose, le bac a servi pour cette adresse.
    $dejaDemande = 0;
    if ($cleDemandes !== null) {
        $valeurQuota = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            $cleDemandes
        ));
        if ($valeurQuota !== '') {
            $dejaDemande = (int) explode('|', $valeurQuota)[0];
            if ($dejaDemande > 0) {
                echo '    -> compteur de quota déjà utilisé (' . $dejaDemande . ') : scénario HTTP SAUTÉ, pas contourné' . "\n";
                $scenarioHttpPossible = false;
            }
        }
    }
    $cleDemandesUtilisee = $cleDemandes;
} else {
    $dejaDemande = 0;
    $cleDemandesUtilisee = null; // non exécuté : aucune conclusion tirée
}
$idMaxAvant = (int) $wpdb->get_var("SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts}");
$demandesAvant = $compteDemandes();

if ($scenarioHttpPossible) {
    // a) deux demandes valides : 201, quota +1 chacune, photo réelle.
    $r1 = $requete('aucun');
    $r2 = $requete('aucun');
    $ok(strpos($r1, '201') === 0, 'demande HTTP réelle acceptée : ' . $r1);
    $ok(strpos($r2, '201') === 0, 'deuxième demande HTTP réelle acceptée : ' . $r2);
    $lireQuota = static function () use ($wpdb, $cleDemandesUtilisee): int {
        if ($cleDemandesUtilisee === null) {
            return -1;
        }
        return (int) explode('|', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            $cleDemandesUtilisee
        )))[0];
    };
    $quotaLoopbackApres2 = $lireQuota();
    $ok($quotaLoopbackApres2 === 2 + $dejaDemande,
        'quota vu par le serveur = ' . (2 + $dejaDemande) . ' après deux demandes (' . $quotaLoopbackApres2 . ')');
    $ok($compteDemandes() - $demandesAvant === 2, 'deux demandes réellement créées (' . ($compteDemandes() - $demandesAvant) . ')');

    // b) panne SQL injectée sur le chemin HTTP : refus propre, rien d'orphelin.
    @unlink('/tmp/tuc-injection.log');
    $rPanne = $requete('panne');
    $ok(strpos($rPanne, '503') === 0, 'panne injectée sur le chemin HTTP => 503 : ' . $rPanne);
    $ok($compteEvenements('injection') >= 1, 'l’injection a réellement eu lieu (' . $compteEvenements('injection') . ')');
    $ok($compteDemandes() - $demandesAvant === 2, 'AUCUNE demande créée par la panne (' . ($compteDemandes() - $demandesAvant) . ')');
    $quotaApresPanne = $lireQuota();
    $ok($quotaApresPanne === 2 + $dejaDemande, 'le quota N’A PAS bougé après la panne (' . $quotaApresPanne . ')');
    $verrouLoopback = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name = %s",
        $cleDemandesLoopback . '_verrou'
    ));
    $ok($verrouLoopback === 0, 'aucun verrou résiduel après la panne');

    // c) le service repart, et la photo est réellement stockée.
    $r3 = $requete('aucun');
    $ok(strpos($r3, '201') === 0, 'après l’incident, une demande HTTP valide repart : ' . $r3);
    $ok($compteDemandes() - $demandesAvant === 3, 'trois demandes créées au total (' . ($compteDemandes() - $demandesAvant) . ')');
    // Le module limite par IP RÉELLE vue par le serveur (127.0.0.1), pas par l'IP
    // symbolique passée au sous-processus : c'est donc la clé loopback qui porte le
    // quota. Vérifié explicitement plutôt que supposé.
    $quotaLoopback = $lireQuota();
    $ok($quotaLoopback === 3 + $dejaDemande,
        'quota réellement consommé côté serveur (' . $cleDemandesUtilisee . ') = ' . (3 + $dejaDemande) . ' (' . $quotaLoopback . ')');

    $ids = (array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d",
        $idMaxAvant
    ));
    $manquants = 0;
    $mauvaisType = 0;
    foreach ($ids as $idPiece) {
        $chemin = get_attached_file((int) $idPiece);
        if (!$chemin || !is_file($chemin) || filesize($chemin) === 0) {
            $manquants++;
        } elseif (get_post_mime_type((int) $idPiece) !== 'image/jpeg') {
            $mauvaisType++;
        }
    }
    $ok(count($ids) === 3, 'trois photos réellement stockées (' . count($ids) . '), aucune pour la panne');
    $ok($manquants === 0, 'chaque photo a un fichier non vide sur le disque (' . $manquants . ' manquant(s))');
    $ok($mauvaisType === 0, 'chaque photo stockée est un image/jpeg (' . $mauvaisType . ' anomalie(s))');

    // =========================================================================
    @unlink('/tmp/tuc-injection.flag');
} else {
    // Rien n'est conclu : on ne transforme pas un bac déjà utilisé en vert.
    $ok(false, 'scénario HTTP NON EXÉCUTÉ (bac déjà servi pour cette adresse) — non concluant, donc pas vert');
}

// Le serveur auto-démarré n'a rien à faire ici une fois la section jouée.
if (is_resource($tenir->proc)) {
    proc_terminate($tenir->proc);
    proc_close($tenir->proc);
    $tenir->proc = null;
    @unlink($tenir->routeur);
    @unlink($tenir->log);
    @unlink($tenir->muPlugin);
    foreach (['plugins', 'themes', 'uploads', 'languages'] as $dossier) {
        $lien = $tenir->ombre . '/wp-content/' . $dossier;
        if (is_link($lien)) {
            @unlink($lien);
        }
    }
    @rmdir($tenir->ombre . '/wp-content/mu-plugins');
    @rmdir($tenir->ombre . '/wp-content');
    @rmdir($tenir->ombre);
    echo "    serveur HTTP auto-démarré : ARRÊTÉ (port " . $tenir->port . "), racine ombre démontée\n";
}
echo "\n=== 6. Clé témoin préexistante : intacte après tout le test ===\n";
$valeurTemoinApres = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $temoinCle));
$ok($valeurTemoinApres === $valeurTemoinAvant, 'la clé témoin hors périmètre est INCHANGÉE (' . $valeurTemoinAvant . ')');

echo "\n=== Bilan ===\n";
echo 'bilan : ' . ($ko === 0 ? 'TOUT VERT' : $ko . ' ECHEC(S)') . "\n";
@unlink($journal);
exit($ko === 0 ? 0 : 1);
