<?php
/**
 * Régressions d’intégration WordPress pour le runner Docker éphémère.
 * Exécution : wp eval-file /opt/tuc-tests/wp-docker-regression.php
 */

if (!class_exists('WP_CLI')) {
    fwrite(STDERR, "Ce script doit être exécuté avec WP-CLI.\n");
    exit(1);
}

$progressPath = ABSPATH . 'wp-content/tuc-regression-progress.log';
$trace = static function (string $message) use ($progressPath): void {
    file_put_contents($progressPath, gmdate('c') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
};
file_put_contents($progressPath, gmdate('c') . " Début de la suite\n");

// WP-CLI peut masquer certains fatals WordPress derrière l’écran générique
// « critical error »; conserver l’erreur PHP d’origine dans le log du runner.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        fwrite(STDERR, sprintf(
            "PHP_FATAL_DEBUG: %s in %s:%d\n",
            $error['message'],
            $error['file'],
            $error['line']
        ));
    }
});

/** @var Closure(bool, string): void $assert */
$assert = static function (bool $condition, string $message) use ($trace): void {
    if (!$condition) {
        $trace('ASSERTION ÉCHOUÉE : ' . $message);
        throw new RuntimeException('ÉCHEC : ' . $message);
    }
    $trace('OK : ' . $message);
    WP_CLI::log('OK : ' . $message);
};

/** @var Closure(string, array<string, mixed>): WP_REST_Response $rest */
$rest = static function (string $route, array $params = []) use ($trace): WP_REST_Response {
    // Invoke the registered REST callbacks with real WP_REST_Request objects.
    // Separate WP-CLI processes cannot reliably dispatch an in-process REST
    // request; the HTTP health route is independently exercised by curl below.
    $trace('Callback REST : ' . $route);
    $method = in_array($route, ['/tuc/v1/sante', '/tuc/v1/wa-statut'], true) ? 'GET' : 'POST';
    $request = new WP_REST_Request($method, $route);
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    if ($route === '/tuc/v1/sante') {
        $result = TUC_Health::rapport();
    } elseif ($route === '/tuc/v1/commande') {
        $result = TUC_Commande::creer($request);
    } elseif ($route === '/tuc/v1/wa-statut') {
        $result = TUC_Compte::wa_statut($request);
    } elseif ($route === '/tuc/v1/wa-lien') {
        $result = TUC_Compte::wa_lien($request);
    } elseif ($route === '/tuc/v1/wa-panier') {
        $result = TUC_Compte::wa_panier($request);
    } elseif (preg_match('~^/tuc/v1/commande/(\d+)/statut$~', $route, $matches)) {
        $request->set_param('id', (int) $matches[1]);
        $result = TUC_Commande::changer_statut($request);
    } else {
        throw new RuntimeException('Route de test inconnue : ' . $route);
    }

    if (is_wp_error($result)) {
        $errorData = $result->get_error_data();
        $status = is_array($errorData) ? (int) ($errorData['status'] ?? 500) : 500;
        $result = new WP_REST_Response([
            'code' => $result->get_error_code(),
            'message' => $result->get_error_message(),
            'data' => $errorData,
        ], $status);
    }
    if (!$result instanceof WP_REST_Response) {
        throw new RuntimeException('Le callback REST n’a pas retourné une réponse REST.');
    }
    $trace('Callback terminé : HTTP ' . $result->get_status());
    return $result;
};

$created = ['restaurants' => [], 'items' => [], 'orders' => [], 'other' => [], 'users' => []];
$ratePhones = [];
$pollCodes = [];
$waCodes = [];
$oldRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
$hadWaNumber = get_option('tuc_wa_numero', false) !== false;
$oldWaNumber = get_option('tuc_wa_numero', false);
$hadWaSecret = get_option('tuc_wa_secret', false) !== false;
$oldWaSecret = get_option('tuc_wa_secret', false);

try {
    // Activation, thème, CPT et route de santé.
    $activePlugins = (array) get_option('active_plugins', []);
    $assert(in_array('tuc-market-core/tuc-market-core.php', $activePlugins, true), 'plugin TUC activé');
    $assert(wp_get_theme()->get('Name') === 'TUC Market', 'thème TUC actif');
    $assert(post_type_exists('tuc_resto'), 'CPT restaurant enregistré');
    $assert(post_type_exists('tuc_menu_item'), 'CPT article/menu enregistré');
    $assert(post_type_exists('tuc_commande'), 'CPT commande enregistré');
    $assert(has_action('rest_api_init', [TUC_Health::class, 'routes']) !== false, 'route de santé branchée sur rest_api_init');
    $assert(has_action('rest_api_init', [TUC_Commande::class, 'routes']) !== false, 'routes de commande branchées sur rest_api_init');
    $registeredRoutes = rest_get_server()->get_routes();
    $cashierRoute = $registeredRoutes['/tuc/v1/caisse/commandes'][0] ?? [];
    $legacyStatusRoute = $registeredRoutes['/tuc/v1/commande/(?P<id>\d+)/statut'][0] ?? [];
    $assert(is_callable($cashierRoute['permission_callback'] ?? null)
        && is_callable($legacyStatusRoute['permission_callback'] ?? null), 'les endpoints caisse/statut sont effectivement enregistrés avec permission_callback');
    $assert(TUC_Caisse::CAP === 'manage_options', 'la caisse exige la capacité administrateur en attendant les rôles métier');
    $assert(!TUC_Push::endpoint_valide('http://127.0.0.1/internal')
        && !TUC_Push::endpoint_valide('https://localhost/push')
        && !TUC_Push::endpoint_valide('https://127.0.0.1:8443/push'), 'les endpoints Web Push locaux/non HTTPS sont rejetés');

    delete_option('tuc_wa_secret');
    $hookRequest = new WP_REST_Request('POST', '/tuc/v1/wa-hook');
    $hookRequest->set_header('x-tuc-secret', 'guess');
    $assert(!TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp est fermé si aucun secret n’est configuré');
    update_option('tuc_wa_secret', 'test-only-secret-9f7bdc');
    $hookRequest->set_header('x-tuc-secret', 'wrong-secret');
    $assert(!TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp refuse un secret incorrect');
    $hookRequest->set_header('x-tuc-secret', 'test-only-secret-9f7bdc');
    $assert(TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp accepte le secret exact configuré');

    $health = $rest('/tuc/v1/sante');
    $healthData = $health->get_data();
    $assert($health->get_status() === 200 && ($healthData['ok'] ?? false) === true, 'endpoint public de santé retourne ok=true');
    $assert(($healthData['modules']['cpt_resto'] ?? false) === true
        && ($healthData['modules']['cpt_menu'] ?? false) === true
        && ($healthData['modules']['cpt_commande'] ?? false) === true, 'les trois modules CPT sont signalés actifs');

    // Données jetables : un commerce et un plat avec un supplément tarifé.
    $suffix = wp_generate_uuid4();
    $restaurantId = wp_insert_post([
        'post_type'   => 'tuc_resto',
        'post_status' => 'publish',
        'post_title'  => 'TUC Docker Smoke ' . $suffix,
    ], true);
    $assert(!is_wp_error($restaurantId), 'fixture restaurant créée');
    $created['restaurants'][] = (int) $restaurantId;
    update_post_meta($restaurantId, 'tuc_ferme', '0');
    update_post_meta($restaurantId, 'tuc_frais', 5);
    update_post_meta($restaurantId, 'tuc_minimum', 20);

    $itemId = wp_insert_post([
        'post_type'   => 'tuc_menu_item',
        'post_status' => 'publish',
        'post_title'  => 'Plat Docker Smoke ' . $suffix,
    ], true);
    $assert(!is_wp_error($itemId), 'fixture article créée');
    $created['items'][] = (int) $itemId;
    update_post_meta($itemId, 'tuc_resto_id', (int) $restaurantId);
    update_post_meta($itemId, 'tuc_prix', 10);
    update_post_meta($itemId, 'tuc_options', [
        ['id' => 'extra-fromage', 'nom' => 'Extra fromage', 'prix' => 3],
    ]);

    $restaurant = get_post($restaurantId);
    $slug = (string) $restaurant->post_name;
    $idempotenceKey = 'docker-' . $suffix;
    $validOrder = [
        'resto' => $slug,
        'table' => 0,
        'tel'   => '+212600000001',
        'total' => 31,
        'cle'   => $idempotenceKey,
        'items' => [[
            'id'      => (int) $itemId,
            'q'       => 2,
            'options' => [['id' => 'extra-fromage']],
        ]],
    ];
    $ratePhones[] = $validOrder['tel'];

    $invalidPhoneOrder = $validOrder;
    $invalidPhoneOrder['tel'] = '123';
    $invalidPhoneOrder['cle'] = 'invalid-phone-' . $suffix;
    $assert($rest('/tuc/v1/commande', $invalidPhoneOrder)->get_status() === 422, 'un numéro non E.164 ne peut pas commander');
    $invalidKeyOrder = $validOrder;
    $invalidKeyOrder['cle'] = str_repeat('x', 65);
    $assert($rest('/tuc/v1/commande', $invalidKeyOrder)->get_status() === 422, 'une clé d’idempotence surdimensionnée est refusée');

    // Le total serveur = (10 + 3) × 2 + 5 frais = 31 MAD.
    $orderResponse = $rest('/tuc/v1/commande', $validOrder);
    $orderData = $orderResponse->get_data();
    $assert($orderResponse->get_status() === 201 && ($orderData['ok'] ?? false) === true, 'commande valide créée par la route REST');
    $assert(abs((float) ($orderData['total'] ?? 0) - 31.0) < 0.001, 'le serveur recalcule plat, supplément, quantité et frais');
    $orderId = (int) ($orderData['commande'] ?? 0);
    $assert($orderId > 0, 'la réponse retourne un identifiant de commande');
    $created['orders'][] = $orderId;

    $storedItems = (array) get_post_meta($orderId, 'tuc_items', true);
    $assert(($storedItems[0]['opts'][0] ?? '') === 'Extra fromage +3', 'le libellé et le supplément sont stockés lisiblement');

    // Un total client falsifié est refusé avec le total serveur.
    $tampered = $validOrder;
    $tampered['tel'] = '+212600000002';
    $tampered['total'] = 1;
    $tampered['cle'] = 'tampered-' . $suffix;
    $tamperedResponse = $rest('/tuc/v1/commande', $tampered);
    $tamperedData = $tamperedResponse->get_data();
    $assert($tamperedResponse->get_status() === 409
        && abs((float) ($tamperedData['data']['total_serveur'] ?? 0) - 31.0) < 0.001, 'un total client falsifié est refusé avec le total correct');

    // Les tentatives invalides ne doivent pas épuiser le quota d’un téléphone tiers.
    $unverifiedPhone = '+212600000011';
    $ratePhones[] = $unverifiedPhone;
    for ($i = 1; $i <= 6; $i++) {
        $invalidTotal = $validOrder;
        $invalidTotal['tel'] = $unverifiedPhone;
        $invalidTotal['total'] = 1;
        $invalidTotal['cle'] = 'invalid-total-' . $suffix . '-' . $i;
        $assert($rest('/tuc/v1/commande', $invalidTotal)->get_status() === 409, 'un total invalide est rejeté sans consommer le quota');
    }
    $validAfterInvalid = $validOrder;
    $validAfterInvalid['tel'] = $unverifiedPhone;
    $validAfterInvalid['cle'] = 'valid-after-invalid-' . $suffix;
    $validAfterInvalidResponse = $rest('/tuc/v1/commande', $validAfterInvalid);
    $assert($validAfterInvalidResponse->get_status() === 201, 'une commande valide reste possible après des échecs de validation');
    $created['orders'][] = (int) ($validAfterInvalidResponse->get_data()['commande'] ?? 0);

    // Le même panier et la même clé retournent la commande initiale sans doublon.
    $duplicateResponse = $rest('/tuc/v1/commande', $validOrder);
    $duplicateData = $duplicateResponse->get_data();
    $assert($duplicateResponse->get_status() === 200
        && (int) ($duplicateData['commande'] ?? 0) === $orderId
        && ($duplicateData['doublon'] ?? false) === true, 'la clé d’idempotence empêche une commande en double');

    // Les erreurs métier ne doivent pas créer de commande.
    $empty = $validOrder;
    $empty['tel'] = '+212600000003';
    $empty['items'] = [];
    $emptyResponse = $rest('/tuc/v1/commande', $empty);
    $assert($emptyResponse->get_status() === 400, 'un panier vide est refusé');

    $unknown = $validOrder;
    $unknown['resto'] = 'inconnu-' . $suffix;
    $unknown['tel'] = '+212600000004';
    $assert($rest('/tuc/v1/commande', $unknown)->get_status() === 404, 'un commerce inconnu est refusé');

    $unknownItem = $validOrder;
    $unknownItem['tel'] = '+212600000005';
    $unknownItem['items'][0]['id'] = 99999999;
    $unknownItem['cle'] = 'unknown-item-' . $suffix;
    $assert($rest('/tuc/v1/commande', $unknownItem)->get_status() === 422, 'un article inconnu est refusé');

    wp_update_post(['ID' => (int) $itemId, 'post_status' => 'draft']);
    $draftItem = $validOrder;
    $draftItem['tel'] = '+212600000009';
    $draftItem['cle'] = 'draft-item-' . $suffix;
    $assert($rest('/tuc/v1/commande', $draftItem)->get_status() === 422, 'un article non publié ne peut pas être commandé');
    wp_update_post(['ID' => (int) $itemId, 'post_status' => 'publish']);

    update_post_meta($itemId, 'tuc_dispo', '0');
    $unavailable = $validOrder;
    $unavailable['tel'] = '+212600000010';
    $unavailable['cle'] = 'unavailable-' . $suffix;
    $assert($rest('/tuc/v1/commande', $unavailable)->get_status() === 422, 'un article marqué indisponible est refusé');
    update_post_meta($itemId, 'tuc_dispo', '1');

    $otherRestaurantId = wp_insert_post([
        'post_type'   => 'tuc_resto',
        'post_status' => 'publish',
        'post_title'  => 'Autre commerce Docker Smoke ' . $suffix,
    ], true);
    $assert(!is_wp_error($otherRestaurantId), 'fixture second restaurant créée');
    $created['restaurants'][] = (int) $otherRestaurantId;
    $crossStore = $validOrder;
    $crossStore['resto'] = (string) get_post($otherRestaurantId)->post_name;
    $crossStore['tel'] = '+212600000006';
    $crossStore['cle'] = 'cross-store-' . $suffix;
    $assert($rest('/tuc/v1/commande', $crossStore)->get_status() === 422, 'un article d’un autre commerce est refusé');

    update_post_meta($restaurantId, 'tuc_minimum', 40);
    $belowMinimum = $validOrder;
    $belowMinimum['tel'] = '+212600000007';
    $belowMinimum['cle'] = 'minimum-' . $suffix;
    $minimumResponse = $rest('/tuc/v1/commande', $belowMinimum);
    $assert($minimumResponse->get_status() === 422, 'un panier sous le minimum est refusé');
    update_post_meta($restaurantId, 'tuc_minimum', 20);

    update_post_meta($restaurantId, 'tuc_ferme', '1');
    $closed = $validOrder;
    $closed['tel'] = '+212600000008';
    $closed['cle'] = 'closed-' . $suffix;
    $assert($rest('/tuc/v1/commande', $closed)->get_status() === 423, 'un commerce fermé ne prend pas la commande');
    update_post_meta($restaurantId, 'tuc_ferme', '0');

    // Les endpoints WhatsApp publics appliquent un quota par IP.
    update_option('tuc_wa_numero', '212600000099'); // numéro fictif, réservé à ce runner
    $_SERVER['REMOTE_ADDR'] = '198.51.100.77'; // plage de documentation, réservée à ce test
    for ($i = 1; $i <= 20; $i++) {
        $waLink = $rest('/tuc/v1/wa-lien', ['but' => 'compte']);
        $waLinkData = $waLink->get_data();
        $assert($waLink->get_status() === 200, 'demande de lien WhatsApp ' . $i . ' dans la limite');
        if (!empty($waLinkData['code'])) {
            $waCodes[] = (string) $waLinkData['code'];
        }
    }
    $assert($rest('/tuc/v1/wa-lien', ['but' => 'compte'])->get_status() === 429, 'la 21e demande de lien WhatsApp par IP est limitée');

    // L’aperçu WhatsApp recalcule prix et frais depuis les données WordPress.
    $waCart = $rest('/tuc/v1/wa-panier', [
        'resto' => $slug,
        'mode' => 'livraison',
        'table' => 0,
        'frais' => 9999,
        'items' => [[
            'id' => (int) $itemId,
            'q' => 2,
            'options' => [['id' => 'extra-fromage']],
            'nom' => 'Prix falsifié',
            'pu' => 0.01,
        ]],
    ]);
    $waCartData = $waCart->get_data();
    $assert($waCart->get_status() === 200 && abs((float) ($waCartData['total'] ?? 0) - 31.0) < 0.001,
        'l’aperçu WhatsApp ignore prix et frais fournis par le client');
    $assert(str_contains((string) ($waCartData['message'] ?? ''), $restaurant->post_title)
        && !str_contains((string) ($waCartData['message'] ?? ''), 'Prix falsifié'), 'l’aperçu WhatsApp utilise les libellés WordPress, pas ceux du client');

    // La référence WhatsApp seule ne suffit pas à récupérer un lien magique.
    $pollCode = strtoupper(bin2hex(random_bytes(4)));
    $pollSecret = wp_generate_password(40, false, false);
    $fakeMagic = 'https://tuc.invalid/connexion?tuc_magie=fixture-only';
    $pollCodes[] = $pollCode;
    set_transient('tuc_wa_ok_' . $pollCode, [
        'poll_hash' => hash('sha256', $pollSecret),
        'magie' => $fakeMagic,
        'cree' => true,
    ], 5 * MINUTE_IN_SECONDS);
    $pollWithoutSecret = $rest('/tuc/v1/wa-statut', ['code' => $pollCode]);
    $pollWrongSecret = $rest('/tuc/v1/wa-statut', ['code' => $pollCode, 'poll' => 'wrong-secret']);
    $pollWithSecret = $rest('/tuc/v1/wa-statut', ['code' => $pollCode, 'poll' => $pollSecret]);
    $pollHeaders = array_change_key_case((array) $pollWithSecret->get_headers(), CASE_LOWER);
    $assert(($pollWithoutSecret->get_data()['pret'] ?? true) === false
        && ($pollWrongSecret->get_data()['pret'] ?? true) === false, 'sans secret navigateur, le polling ne révèle pas le lien magique');
    $assert(($pollWithSecret->get_data()['magie'] ?? '') === $fakeMagic
        && str_contains((string) ($pollHeaders['cache-control'] ?? ''), 'no-store'), 'le polling autorisé retourne le lien sans mise en cache');

    // L’état d’une commande ne peut pas être modifié anonymement.
    wp_set_current_user(0);
    $statusPath = '/tuc/v1/commande/' . $orderId . '/statut';
    $anonymousCanEdit = current_user_can('edit_posts');
    $assert(!$anonymousCanEdit, 'le callback de permission refuse edit_posts à un visiteur anonyme');
    $anonymousStatus = $anonymousCanEdit
        ? $rest($statusPath, ['statut' => 'acceptee'])
        : new WP_REST_Response(['code' => 'rest_forbidden'], 403);
    $assert(in_array($anonymousStatus->get_status(), [401, 403], true), 'un visiteur anonyme ne peut pas modifier le statut');
    $assert(get_post_meta($orderId, 'tuc_statut', true) === 'nouvelle', 'le statut reste inchangé après la requête anonyme');

    // Un administrateur habilité peut faire une transition valide; un statut libre est refusé.
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $assert(!empty($admins), 'un administrateur de test existe');
    wp_set_current_user((int) $admins[0]);
    $assert(current_user_can('manage_options') && current_user_can(TUC_Caisse::CAP), 'l’administrateur satisfait les permissions de la caisse et de la route historique');
    $assert(call_user_func($cashierRoute['permission_callback']) === true
        && call_user_func($legacyStatusRoute['permission_callback']) === true, 'les permission_callback enregistrés autorisent un administrateur');
    $validStatus = $rest($statusPath, ['statut' => 'acceptee']);
    $assert($validStatus->get_status() === 200
        && get_post_meta($orderId, 'tuc_statut', true) === 'acceptee', 'un administrateur peut accepter la commande');
    $invalidStatus = $rest($statusPath, ['statut' => 'publiee']);
    $assert($invalidStatus->get_status() === 400, 'un statut non autorisé est refusé');
    $missingStatus = $rest('/tuc/v1/commande/99999999/statut', ['statut' => 'acceptee']);
    $assert($missingStatus->get_status() === 404, 'la route historique refuse un identifiant inexistant');
    $ordinaryPostId = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Cible non-commande ' . $suffix,
    ], true);
    $assert(!is_wp_error($ordinaryPostId), 'fixture contenu WordPress ordinaire créée');
    $created['other'][] = (int) $ordinaryPostId;
    $wrongTypeStatus = $rest('/tuc/v1/commande/' . (int) $ordinaryPostId . '/statut', ['statut' => 'acceptee']);
    $assert($wrongTypeStatus->get_status() === 404
        && get_post_meta($ordinaryPostId, 'tuc_statut', true) === '', 'la route historique refuse de modifier un contenu qui n’est pas une commande');

    $editorId = wp_insert_user([
        'user_login' => 'tuc-security-editor-' . substr($suffix, 0, 8),
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => 'tuc-security-editor-' . substr($suffix, 0, 8) . '@example.invalid',
        'role' => 'editor',
    ]);
    $assert(!is_wp_error($editorId), 'fixture éditeur créée');
    $created['users'][] = (int) $editorId;
    wp_set_current_user((int) $editorId);
    $assert(current_user_can('edit_posts') && !current_user_can('manage_options')
        && !current_user_can(TUC_Caisse::CAP), 'un éditeur WordPress ne peut ni ouvrir la caisse ni utiliser la route de statut admin');
    $cashierDenied = call_user_func($cashierRoute['permission_callback']);
    $legacyStatusDenied = call_user_func($legacyStatusRoute['permission_callback']);
    $assert($cashierDenied === false && $legacyStatusDenied === false, 'les permission_callback enregistrés refusent un éditeur');
    wp_set_current_user(0);

    // Le limiteur accepte cinq commandes par téléphone et bloque la sixième.
    $ratePhone = '+2126' . (string) wp_rand(10000000, 99999999);
    $ratePhones[] = $ratePhone;
    for ($i = 1; $i <= 5; $i++) {
        $rateOrder = $validOrder;
        $rateOrder['tel'] = $ratePhone;
        $rateOrder['cle'] = 'rate-' . $suffix . '-' . $i;
        $rateResponse = $rest('/tuc/v1/commande', $rateOrder);
        $assert($rateResponse->get_status() === 201, 'commande ' . $i . ' acceptée dans la limite');
        $created['orders'][] = (int) ($rateResponse->get_data()['commande'] ?? 0);
    }
    $sixth = $validOrder;
    $sixth['tel'] = $ratePhone;
    $sixth['cle'] = 'rate-' . $suffix . '-6';
    $assert($rest('/tuc/v1/commande', $sixth)->get_status() === 429, 'la sixième commande en dix minutes est limitée');

    WP_CLI::success('Tous les tests de régression WordPress/TUC sont passés.');
} finally {
    wp_set_current_user(0);
    foreach (array_unique($created['orders']) as $id) {
        if ($id > 0) {
            wp_delete_post($id, true);
        }
    }
    foreach (array_unique($created['items']) as $id) {
        wp_delete_post($id, true);
    }
    foreach (array_unique($created['restaurants']) as $id) {
        wp_delete_post($id, true);
    }
    foreach (array_unique($created['other']) as $id) {
        wp_delete_post($id, true);
    }
    if ($created['users'] !== [] && !function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    foreach (array_unique($created['users']) as $id) {
        wp_delete_user($id);
    }
    foreach ($ratePhones as $phone) {
        delete_transient('tuc_rl_' . md5(TUC_Compte::normaliser($phone)));
    }
    $ip = filter_var((string) ($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);
    if ($ip !== false) {
        delete_transient('tuc_rl_ip_' . md5($ip));
    }
    foreach ($pollCodes as $code) {
        delete_transient('tuc_wa_ok_' . $code);
    }
    foreach ($waCodes as $code) {
        delete_transient('tuc_wa_' . $code);
    }
    $testIp = '198.51.100.77';
    delete_transient('tuc_public_wa_lien_' . md5($testIp));
    delete_transient('tuc_rl_ip_' . md5($testIp));
    if ($oldRemoteAddr === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $oldRemoteAddr;
    }
    if ($hadWaNumber) {
        update_option('tuc_wa_numero', $oldWaNumber);
    } else {
        delete_option('tuc_wa_numero');
    }
    if ($hadWaSecret) {
        update_option('tuc_wa_secret', $oldWaSecret);
    } else {
        delete_option('tuc_wa_secret');
    }
}
