<?php
/**
 * Test dédié : les TROIS MONDES de `prochaine_generation()`.
 *
 * Pourquoi un fichier séparé : `tests/wp-docker-regression.php` est le contrat
 * figé (429 assertions, rejoué tel quel en CI) et `tests/limiteur-resilience.php`
 * est le harnais de contention. Ni l'un ni l'autre ne distingue, sur le chemin du
 * compteur global `tuc_purge_generation`, une ligne introuvable au moment du
 * SELECT, un conflit SQL que le moteur invite à rejouer, et un SELECT REFUSÉ par
 * le serveur. C'est exactement le point soulevé par la revue n° 20.
 *
 * Ce que le module garantit ici (et que ce fichier mesure) :
 *
 *   `wpdb::get_var()` rend `null` pour « 0 ligne » COMME pour « erreur ». La
 *   valeur de retour ne peut donc pas servir de critère. `prochaine_generation()`
 *   lit `trim($wpdb->last_error)` AU MÊME INSTANT (avant tout autre appel SQL :
 *   `wpdb::query()` appelle `flush()` en tête de chaque requête et remet
 *   `last_error` à '' — mesuré dans class-wpdb.php), et classe :
 *     - message vide ................ conflit passager (course avec la purge) ;
 *     - motif « try restarting », « Lock wait timeout », « Record has changed »
 *                               ...... conflit RÉCUPÉRABLE, à rejouer ;
 *     - tout autre message ........... PANNE RÉELLE ; le borneur de reprises ne
 *                                      doit PAS la transformer en attente.
 *
 * Comment chaque monde est produit — le test n'écrit jamais un message d'erreur à
 * la main, c'est le serveur qui le rend :
 *   A « ligne introuvable » : le SELECT du module est redirigé vers une clé
 *     absente de wp_options — le moteur renvoie 0 ligne, sans erreur ;
 *   B « conflit réel » : une SECONDE CONNEXION RÉELLE détient la ligne du
 *     compteur sous `SELECT … FOR UPDATE`, et l'on abaisse le timeout de verrou :
 *     c'est le moteur qui interrompt l'INSERT IGNORE et qui rend « Lock wait
 *     timeout exceeded; try restarting transaction » ;
 *   C « panne réelle » : le SELECT est redirigé vers une table qui n'existe pas
 *     (1146, refus du serveur, aucun motif récupérable).
 * L'injection passe par le seul filtre `query` du cœur — même mécanisme que le
 * harnais de résilience, aucun code de test dans la production. Les erreurs étant
 * voulues, `suppress_errors(true)` + `hide_errors()` évitent que `print_error()`
 * n'envoie du HTML sur stdout.
 *
 * Bloc D : une REQUÊTE COMPLÈTE par sous-processus. Les compteurs de la classe
 * sont des statiques PHP, remis à zéro au début d'une requête seulement — le
 * harnais existant procède de même (« un processus par IP »). Chaque enfant rend
 * le VERDICT VU PAR LE CLIENT (code, cause, `retry`) et le NOMBRE de passes
 * réellement consommées, ce qui répond au second point de la revue : les reprises
 * bornées ne doivent pas masquer une vraie panne.
 *
 * Usage (bac jetable UNIQUEMENT) :
 *   php tests/generation-classification.php <dossier-wordpress> --bac-jetable
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');
mysqli_report(MYSQLI_REPORT_OFF);

$opts = [];
$libres = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--bac-jetable') { $opts['bac'] = true; continue; }
    if (preg_match('/^--(mode|monde)=(.*)$/', $a, $m)) { $opts[$m[1]] = $m[2]; continue; }
    $libres[] = $a;
}
$dossierWp = rtrim((string) ($libres[0] ?? ''), '/');
if ($dossierWp === '') {
    fwrite(STDERR, "usage : php tests/generation-classification.php <dossier-wordpress> [--bac-jetable]\n");
    exit(2);
}
if (is_file($dossierWp . '/wp-load.php')) {
    $dossierWp .= '';
} elseif (basename($dossierWp) === 'wp-load.php') {
    $dossierWp = dirname($dossierWp);
} else {
    fwrite(STDERR, "chemin non reconnu : $dossierWp\n");
    exit(2);
}
$charge = $dossierWp . '/wp-includes/class-wpdb.php';
$module = $dossierWp . '/wp-content/plugins/tuc-market-core/includes/modules/class-tuc-onboarding-restaurant.php';
if (!is_file($charge) || !is_file($module)) {
    fwrite(STDERR, "ce test exige une installation WordPress chargeant le module TUC.\n");
    exit(2);
}
// ---- gardes : ce test touche l'état du site --------------------------------
$accord = isset($opts['bac']) || getenv('TUC_BAC_JETABLE') === '1';
if (!$accord) {
    fwrite(STDERR, "refuse : ce test supprime/Restaure la ligne du compteur global et abaisse le timeout de verrou du serveur.\n");
    fwrite(STDERR, "relancez avec --bac-jetable si vous ciblez une installation non productive.\n");
    exit(3);
}
// Marqueur cherché à deux endroits (mesuré en conteneur : wp-content appartient
// à un autre uid, seul uploads/ est ouvert en 1777).
$marquebac = $dossierWp . '/.tuc-bac-jetable';
if (!is_file($marquebac)) { $marquebac = $dossierWp . '/wp-content/uploads/.tuc-bac-jetable'; }
if (!is_file($marquebac)) {
    fwrite(STDERR, "refuse : aucun marqueur d'installation jetable (.tuc-bac-jetable). Le bac doit être déclaré.\n");
    exit(3);
}
unset($wpdb);
require_once $dossierWp . '/wp-load.php';
global $wpdb;
$urlSite = strtolower((string) get_option('home', ''));
$hostSite = (string) parse_url($urlSite, PHP_URL_HOST);
if (!in_array($hostSite, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "refuse : le site n'est pas local ($urlSite).\n");
    exit(3);
}
if (!class_exists('TUC_Onboarding_Restaurant')) {
    fwrite(STDERR, "refuse : classe TUC_Onboarding_Restaurant absente.\n");
    exit(2);
}

// ---- accès aux membres privés : le module n'expose pas d'API de test -------
$rc = new ReflectionClass('TUC_Onboarding_Restaurant');
$constante = static function (string $nom) use ($rc): int {
    $c = $rc->getReflectionConstant($nom);
    return $c === false ? 0 : (int) $c->getValue();
};
$lireProp = static function (string $nom) use ($rc) {
    if (!$rc->hasProperty($nom)) { return null; }
    $p = $rc->getProperty($nom);
    $p->setAccessible(true);
    return $p->getValue();
};
$appeler = static function (string $methode, array $args = []) use ($rc) {
    $m = $rc->getMethod($methode);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};
$reserver = static function () use ($rc) {
    $m = $rc->getMethod('reserver_demande');
    $m->setAccessible(true);
    return $m->invoke(null);
};

$cleGen = (string) ($constante('OPTION_GENERATION') ?: 'tuc_purge_generation');
$genTentatives = $constante('GENERATION_TENTATIVES') ?: 20;
$compteurTentatives = $constante('COMPTEUR_TENTATIVES') ?: 3;
$critiqueTentatives = $constante('CRITIQUE_TENTATIVES') ?: 5;
$ipTeste = '198.51.100.85'; // bloc RFC 5737 : jamais une adresse réelle
$cleQuota = 'tuc_demandes_' . md5($ipTeste);
$cleVerrou = $cleQuota . '_verrou';

/** [lignePresente, valeur] — `get_var` seul ne distingue pas absent de NULL. */
$etat = static function () use ($wpdb, $cleGen): array {
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT option_id FROM `{$wpdb->options}` WHERE option_name = %s", $cleGen));
    $v = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $cleGen));
    return [$id !== null, $v === null ? null : (string) $v];
};
/** Restaure l'etat exact : ligne supprimee si elle etait absente, sinon valeur
    (NULL compris) — ne jamais remplacer une absence par un '0' fabrique. */
$poser = static function (array $etat, bool $forcerValeur = false) use ($wpdb, $cleGen): void {
    [$presente, $valeur] = $etat;
    if (!$presente && !$forcerValeur) {
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $cleGen));
        return;
    }
    if ($forcerValeur) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, '0', 'no')
             ON DUPLICATE KEY UPDATE option_value = '0'", $cleGen));
        return;
    }
    $sql = $valeur === null
        ? $wpdb->prepare("INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, NULL, 'no')
             ON DUPLICATE KEY UPDATE option_value = NULL", $cleGen)
        : $wpdb->prepare("INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $cleGen, $valeur);
    $wpdb->query($sql);
};
$effacerCle = static function (string $cle) use ($wpdb): void {
    $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $cle));
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete($cle, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
};

$etatAvant = $etat();
$valeurAvant = $etatAvant[1];
$tempsVerrou = max(1, min(2, (int) $wpdb->get_var("SELECT @@innodb_lock_wait_timeout")));
$wpdb->suppress_errors(true);
$wpdb->hide_errors();

// ---- la seule porte d'entrée : le filtre `query` du cœur -------------------
$GLOBALS['tuc_gen'] = ['monde' => 'absent', 'lues' => 0, 'creations' => 0, 'frappe' => 0];
add_filter('query', static function ($req) {
    if (!is_string($req) || is_numeric($req)) { return $req; }
    static $cle;
    $cle = $cle ?? (string) $GLOBALS['tuc_gen_cle'];
    $s = preg_replace('/\s+/', ' ', $req);
    $estLecture = stripos($s, "SELECT option_value FROM") !== false && strpos($s, "option_name = '$cle'") !== false
        && stripos($s, 'FOR UPDATE') === false;
    $estCreation = stripos($s, 'INSERT IGNORE') !== false && strpos($s, "'$cle'") !== false;
    if ($estLecture) { $GLOBALS['tuc_gen']['lues']++; }
    if ($estCreation) { $GLOBALS['tuc_gen']['creations']++; }
    $monde = (string) $GLOBALS['tuc_gen']['monde'];
    $plafond = (int) ($GLOBALS['tuc_gen']['frappe_max'] ?? 1);
    if ($monde === 'absent' || $GLOBALS['tuc_gen']['frappe'] >= $plafond) { return $req; }
    if ($monde === 'ligne' && $estLecture) {
        // Le SELECT part VRAIMENT au serveur, sur une clé absente : 0 ligne,
        // aucun message. C'est le monde 1 de `prochaine_generation()`.
        $GLOBALS['tuc_gen']['frappe']++;
        return "SELECT option_value FROM `{$GLOBALS['tuc_gen_table']}` WHERE option_name = 'tuc_cle_absente_du_test_a'";
    }
    if ($monde === 'panne' && $estLecture) {
        // Table inexistante : c'est le SERVEUR qui refuse (1146) et qui signe le
        // message — rien n'est fabriqué par le test.
        $GLOBALS['tuc_gen']['frappe']++;
        return "SELECT option_value FROM `tuc_table_absente_du_test_c` WHERE option_name = 'x'";
    }
    return $req;
}, 20);
$GLOBALS['tuc_gen_cle'] = $cleGen;
$GLOBALS['tuc_gen_table'] = $wpdb->options;

function etatClasse(): string
{
    global $lireProp;
    return 'conflit=' . var_export((bool) $lireProp('generationConflit'), true)
        . ' panne_reelle=' . var_export((bool) $lireProp('generationPanneReelle'), true)
        . " message='" . preg_replace('/\s+/', ' ', (string) $lireProp('generationErreur')) . "'";
}
function mesurer(string $monde, callable $appel): array
{
    global $lireProp, $etat;
    $GLOBALS['tuc_gen']['monde'] = $monde;
    $GLOBALS['tuc_gen']['frappe'] = 0;
    $luesAvant = $GLOBALS['tuc_gen']['lues'];
    $creationsAvant = $GLOBALS['tuc_gen']['creations'];
    $t0 = microtime(true);
    $rendu = null; $erreur = null;
    try { $rendu = $appel(); } catch (Throwable $e) { $erreur = get_class($e) . ': ' . $e->getMessage(); }
    $duree = round(microtime(true) - $t0, 2);
    $GLOBALS['tuc_gen']['monde'] = 'absent';
    return [
        'rendu' => $rendu, 'erreur' => $erreur, 'duree' => $duree,
        'lues' => $GLOBALS['tuc_gen']['lues'] - $luesAvant,
        'creations' => $GLOBALS['tuc_gen']['creations'] - $creationsAvant,
        'conflit' => $lireProp('generationConflit'),
        'panne' => $lireProp('generationPanneReelle'),
        'message' => (string) $lireProp('generationErreur'),
        'transactionPerdue' => $lireProp('transactionPerdue'),
        'cause' => (string) $lireProp('causeRefus'),
        'ligne' => $etat()[1],
    ];
}

$OK = 0; $KO = 0; $ecarts = [];
function ok(bool $cond, string $label, string $detail = ''): void
{
    global $OK, $KO, $ecarts;
    if ($cond) { $OK++; printf("  OK    — %s\n", $label); }
    else {
        $KO++; $ecarts[] = $label . ($detail !== '' ? " — $detail" : '');
        printf("  ECHEC — %s%s\n", $label, $detail !== '' ? " : $detail" : '');
    }
}

/* -------------------------------------------------------------------------- */
/* Enfant du bloc D : UNE requête complète, un monde, état de classe neuf.    */
/* -------------------------------------------------------------------------- */
function jouer_enfant(): void
{
    global $dossierWp;
    $monde = (string) ($GLOBALS['tuc_opts']['monde'] ?? 'ligne');
    $ip = (string) ($GLOBALS['tuc_opts']['ip'] ?? '198.51.100.85');
    $GLOBALS['tuc_gen']['monde'] = $monde;
    $GLOBALS['tuc_gen']['frappe_max'] = 1000000; // sur TOUTE la requête : la section est rejouée
    $GLOBALS['tuc_gen']['lues'] = 0;
    $GLOBALS['tuc_gen']['creations'] = 0;
    $GLOBALS['tuc_gen']['frappe'] = 0;
    $_SERVER['REMOTE_ADDR'] = $ip; // le module lit l'adresse vue par le serveur
    $r = $GLOBALS['reserver']();
    $data = is_wp_error($r) ? (array) $r->get_error_data() : [];
    $code = is_wp_error($r) ? (int) ($data['status'] ?? 0) : (is_wp_error($r) ? 0 : 201);
    $champs = [
        'code' => $code,
        'cause' => is_wp_error($r) ? (string) $r->get_error_code() : 'reserve',
        'retry' => var_export($data['retry'] ?? null, true),
        'lues' => (int) $GLOBALS['tuc_gen']['lues'],
        'creations' => (int) $GLOBALS['tuc_gen']['creations'],
        'conflit' => var_export((bool) $GLOBALS['lireProp']('generationConflit'), true),
        'panne' => var_export((bool) $GLOBALS['lireProp']('generationPanneReelle'), true),
        'message' => preg_replace('/\s+/', ' ', (string) $GLOBALS['lireProp']('generationErreur')),
        'cause_interne' => (string) $GLOBALS['lireProp']('causeRefus'),
        'panne_flag' => var_export((bool) $GLOBALS['lireProp']('refusParPanne'), true),
        'monde' => $monde,
    ];
    foreach ($champs as $k => $v) { echo "$k=$v\n"; }
    exit(0);
}
$GLOBALS['tuc_opts'] = $opts;
if (($opts['mode'] ?? '') === 'enfant') { jouer_enfant(); }

echo "=== Contexte ===\n";
echo "  IP de test        : $ipTeste (RFC 5737)\n";
echo "  compteur global   : $cleGen = " . var_export($valeurAvant, true) . " (état d'origine, restauré à la fin)\n";
echo "  budgets           : GENERATION_TENTATIVES=$genTentatives COMPTEUR_TENTATIVES=$compteurTentatives CRITIQUE_TENTATIVES=$critiqueTentatives\n";
echo "  timeout de verrou : {$tempsVerrou}s (plancher accepté par le serveur)\n";
echo "  injection         : filtre `query` du cœur ; aucun code de test dans la production\n";

$poser($etatAvant, true); // on a BESOIN d'une ligne pour travailler ; restaurée à la fin
if ($etat()[1] === null) {
    fwrite(STDERR, "refuse : impossible de poser la ligne du compteur dans ce bac.\n");
    exit(2);
}
$ligneAttendue = '0';
echo "\n=== A. Ligne introuvable au moment du SELECT, AUCUNE erreur -> conflit passager ===\n";
$a = mesurer('ligne', static fn () => $GLOBALS['appeler']('prochaine_generation'));
ok($a['erreur'] === null, "A : l'appel n'a pas jeté", (string) $a['erreur']);
ok($a['lues'] === 1, "A : le SELECT du module est bien passé au serveur une fois", "lues={$a['lues']}");
ok($a['rendu'] === null, "A : aucune valeur de génération fabriquée", var_export($a['rendu'], true));
ok($a['conflit'] === true && $a['panne'] === false, "A : classé CONFLIT réessayable, et non panne", etatClasse());
ok($a['message'] === '', "A : le serveur n'a rien signalé — c'est bien le monde « 0 ligne »", "message='" . $a['message'] . "'");
ok($a['ligne'] === $ligneAttendue, "A : AUCUNE écriture partielle sur le compteur pendant la décision", "ligne={$a['ligne']} attendue=$ligneAttendue");

echo "\n=== B. INSERT IGNORE INTERROMPU par le moteur -> conflit RÉCUPÉRABLE, pas une panne ===\n";
$ho = (string) DB_HOST; $po = null; $so = (defined('DB_SOCKET') && DB_SOCKET !== '') ? (string) DB_SOCKET : null;
if (strpos($ho, ':') !== false) {
    [$ho, $apres] = explode(':', $ho, 2);
    if (ctype_digit($apres)) { $po = (int) $apres; } else { $so = $apres; }
}
if ($ho === 'localhost' && $so === null) { $ho = '127.0.0.1'; }
$seconde = mysqli_init();
$connectee = $seconde !== false && @mysqli_real_connect($seconde, $ho, (string) DB_USER, (string) DB_PASSWORD,
    (string) DB_NAME, ($po !== null && $po > 0) ? $po : null, $so);
$verrouPose = false;
if ($connectee) {
    // X sur la ligne du compteur : l'INSERT IGNORE (contrôle de doublon) doit
    // attendre, et le sera par le SERVEUR — pas par le test.
    mysqli_query($seconde, "SET SESSION innodb_lock_wait_timeout = $tempsVerrou");
    mysqli_query($seconde, "SET autocommit = 0");
    mysqli_query($seconde, "START TRANSACTION");
    mysqli_query($seconde, $wpdb->prepare(
        "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s FOR UPDATE", $cleGen));
    $verrouPose = mysqli_affected_rows($seconde) > 0;
}
$b = mesurer('absent', static fn () => $GLOBALS['appeler']('prochaine_generation'));
if ($connectee) { @mysqli_query($seconde, "COMMIT"); @mysqli_close($seconde); }
ok($verrouPose, "B : le verrou réel a bien été posé sur la ligne (sinon ce bloc ne prouve rien)");
ok($b['duree'] >= 1.0, "B : la requête a RÉELLEMENT attendu le verrou (durée mesurée)", "duree={$b['duree']}s");
ok(str_contains($b['message'], 'Lock wait timeout') || str_contains($b['message'], 'try restarting'),
    "B : le message vient du SERVEUR", "message='" . $b['message'] . "'");
ok($b['rendu'] === null, "B : rien d'inventé pendant l'attente du verrou", var_export($b['rendu'], true));
ok($b['conflit'] === true && $b['panne'] === false,
    "B : classé CONFLIT récupérable (branche \$creation === false)", etatClasse());
ok($b['creations'] >= 1, "B : le test a bien atteint la création du compteur (branche \$creation === false)", "creations={$b['creations']}");
ok($b['ligne'] === $ligneAttendue, "B : aucune écriture partielle sur le compteur", "ligne={$b['ligne']} attendue=$ligneAttendue");

echo "\n=== C. SELECT REFUSÉ par le moteur -> VRAIE PANNE, et court-circuit des reprises ===\n";
$c = mesurer('panne', static fn () => $GLOBALS['appeler']('prochaine_generation'));
ok($c['rendu'] === null, "C : le refus reste un refus", var_export($c['rendu'], true));
ok(str_contains($c['message'], "doesn't exist") || str_contains($c['message'], "n'existe pas"),
    "C : message de refus RÉEL du serveur (1146)", "message='" . $c['message'] . "'");
ok($c['conflit'] === false, "C : NON classé conflit — le point exact du relecteur", etatClasse());
ok($c['panne'] === true, "C : classé PANNE RÉELLE", etatClasse());
ok($c['lues'] === 1, "C : SORT de la boucle à la première passe : une panne n'use pas les $genTentatives tentatives", "lues={$c['lues']}");
ok($c['ligne'] === $ligneAttendue, "C : aucune écriture partielle sur le compteur", "ligne={$c['ligne']} attendue=$ligneAttendue");

echo "\n=== D. Verdict rendu au CLIENT et budget réellement consommé (un processus par requête) ===\n";
$plafond = $compteurTentatives * $critiqueTentatives;
$sc = [];
foreach (['ligne' => 'A', 'panne' => 'C'] as $monde => $nom) {
    $cmd = sprintf('%s %s %s --bac-jetable --mode=enfant --monde=%s --ip=%s 2>/dev/null',
        escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($dossierWp), $monde, $ipTeste);
    $sortie = (string) shell_exec($cmd);
    $lus = [];
    foreach (preg_split('/\R/', trim($sortie)) ?: [] as $ligne) {
        if (preg_match('/^([a-z_]+)=(.*)$/', trim($ligne), $mm)) { $lus[$mm[1]] = $mm[2]; }
    }
    $sc[$nom] = $lus;
    echo "  requête $nom : " . ($lus ? json_encode($lus, JSON_UNESCAPED_UNICODE) : '(aucune sortie enfant)') . "\n";
}
$ra = $sc['A'] ?? []; $rc2 = $sc['C'] ?? [];
ok(($ra['code'] ?? '') !== '' && ($rc2['code'] ?? '') !== '', "D : les deux enfants ont répondu");
ok(($ra['cause'] ?? '') === 'tuc_onboarding_occupe' && (int) ($ra['code'] ?? 0) === 503 && ($ra['retry'] ?? '') === 'true',
    "D : file d'attente -> tuc_onboarding_occupe / 503 / retry=true (le client est prévenu)",
    "cause=" . ($ra['cause'] ?? '?') . " code=" . ($ra['code'] ?? '?') . " retry=" . ($ra['retry'] ?? '?'));
ok(($rc2['cause'] ?? '') === 'tuc_onboarding_storage' && (int) ($rc2['code'] ?? 0) === 503 && ($rc2['retry'] ?? '') === 'NULL',
    "D : panne sur le compteur -> tuc_onboarding_storage / 503 / AUCUNE invitation à réessayer",
    "cause=" . ($rc2['cause'] ?? '?') . " code=" . ($rc2['code'] ?? '?') . " retry=" . ($rc2['retry'] ?? '?'));
ok(($rc2['panne_flag'] ?? '') === 'true', "D : le fanion « refus sur panne » est posé côté requête", ($rc2['panne_flag'] ?? '?'));
ok(($ra['panne_flag'] ?? '') === 'false', "D : il ne l'est PAS sur une simple attente", ($ra['panne_flag'] ?? '?'));
ok((int) ($rc2['lues'] ?? -1) === 1, "D : sur la panne, 1 seule lecture du compteur sur toute la requête (plafond de section : $plafond)",
    "lues=" . ($rc2['lues'] ?? '?'));
ok((int) ($ra['lues'] ?? 0) >= 1 && (int) ($ra['lues'] ?? 0) <= $plafond * $genTentatives,
    "D : l'attente est bien rejouée, et reste BORNÉE (aucune boucle infinie)", "lues=" . ($ra['lues'] ?? '?'));
ok((int) ($ra['creations'] ?? 0) >= 1 && (int) ($rc2['creations'] ?? 0) >= 1,
    "D : les deux requêtes sont bien passées par la création du compteur (chemin réel, pas court-circuité)",
    "creations A=" . ($ra['creations'] ?? '?') . " C=" . ($rc2['creations'] ?? '?'));

echo "\n=== E. Nettoyage et limites de ce qui est prouvé ===\n";
$effacerCle($cleQuota);
$effacerCle($cleVerrou);
$poser($etatAvant);
[$presenteApres, $valeurApres] = $etat();
ok($presenteApres === $etatAvant[0] && $valeurApres === $etatAvant[1],
    "E : le bac est rendu à son état d'origine (ligne " . var_export($etatAvant[0], true) . ", valeur " . var_export($etatAvant[1], true) . ")",
    "ligne=" . var_export($presenteApres, true) . " valeur=" . var_export($valeurApres, true));
echo "  • le deadlock 1213 n'est PAS produit ici : il est produit et rejoué par le contrat\n";
echo "    (cas « 1213 Deadlock found ») et par le harnais de résilience. Ce fichier couvre les\n";
echo "    trois mondes que rien d'autre ne distingue sur ce SELECT ;\n";
echo "  • un SELECT simple de `prochaine_generation()` ne peut PAS être interrompu par un\n";
echo "    verrou (lecture non verrouillante, MVCC) : le conflit réel ne peut frapper que\n";
echo "    l'INSERT IGNORE ou l'UPDATE — c'est pourquoi le monde B bloque la CRÉATION, et que\n";
echo "    cette nuance est écrite ici plutôt que simuler un 1205 introuvable en pratique ;\n";
echo "  • chiffres de bac, pas de CI : 1205 dépend du serveur et de son timeout ;\n";
echo "  • le monde B dépend d'une seconde connexion réelle : si le verrou n'a pas pu être\n";
echo "    posé, l'assertion correspondante rougit au lieu de passer en douce.\n";

echo "\n=== Bilan ===\n";
echo "bilan : $KO ECHEC(S) / $OK OK\n";
foreach ($ecarts as $e) { echo "  - $e\n"; }
exit($KO === 0 ? 0 : 1);
