<?php
/**
 * Régressions d’intégration WordPress pour le runner Docker éphémère.
 * Exécution : wp eval-file /opt/tuc-tests/wp-docker-regression.php
 */

declare(strict_types=1);

if (!class_exists('WP_CLI')) {
    fwrite(STDERR, "Ce script doit être exécuté avec WP-CLI.\n");
    exit(1);
}

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
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('ÉCHEC : ' . $message);
    }
    WP_CLI::log('OK : ' . $message);
};

/** @var Closure(string, array<string, mixed>): WP_REST_Response $rest */
$rest = static function (string $route, array $params = []): WP_REST_Response {
    $request = new WP_REST_Request($route === '/tuc/v1/sante' ? 'GET' : 'POST', $route);
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }
    return rest_do_request($request);
};

$created = ['restaurants' => [], 'items' => [], 'orders' => []];
$ratePhones = [];

try {
    // Activation, thème, CPT et route de santé.
    $activePlugins = (array) get_option('active_plugins', []);
    $assert(in_array('tuc-market-core/tuc-market-core.php', $activePlugins, true), 'plugin TUC activé');
    $assert(wp_get_theme()->get('Name') === 'TUC Market', 'thème TUC actif');
    $assert(post_type_exists('tuc_resto'), 'CPT restaurant enregistré');
    $assert(post_type_exists('tuc_menu_item'), 'CPT article/menu enregistré');
    $assert(post_type_exists('tuc_commande'), 'CPT commande enregistré');

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

    // L’état d’une commande ne peut pas être modifié anonymement.
    wp_set_current_user(0);
    $statusPath = '/tuc/v1/commande/' . $orderId . '/statut';
    $anonymousStatus = $rest($statusPath, ['statut' => 'acceptee']);
    $assert(in_array($anonymousStatus->get_status(), [401, 403], true), 'un visiteur anonyme ne peut pas modifier le statut');
    $assert(get_post_meta($orderId, 'tuc_statut', true) === 'nouvelle', 'le statut reste inchangé après la requête anonyme');

    // Un administrateur habilité peut faire une transition valide; un statut libre est refusé.
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $assert(!empty($admins), 'un administrateur de test existe');
    wp_set_current_user((int) $admins[0]);
    $validStatus = $rest($statusPath, ['statut' => 'acceptee']);
    $assert($validStatus->get_status() === 200
        && get_post_meta($orderId, 'tuc_statut', true) === 'acceptee', 'un administrateur peut accepter la commande');
    $invalidStatus = $rest($statusPath, ['statut' => 'publiee']);
    $assert($invalidStatus->get_status() === 400, 'un statut non autorisé est refusé');
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
    foreach ($ratePhones as $phone) {
        delete_transient('tuc_rl_' . md5($phone));
    }
}
