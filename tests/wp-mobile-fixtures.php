<?php
/** Fixtures jetables pour le parcours navigateur mobile exécuté dans WordPress Docker CI. */

if (!class_exists('WP_CLI')) {
    fwrite(STDERR, "Ce script doit être exécuté avec WP-CLI.\n");
    exit(1);
}

$password = getenv('TUC_MOBILE_E2E_PASSWORD');
if (!is_string($password) || strlen($password) < 24) {
    WP_CLI::error('Mot de passe E2E absent ou trop court.');
}

TUC_Roles::synchroniser();
$userLogin = 'tuc-mobile-e2e';
$existingUser = get_user_by('login', $userLogin);
if ($existingUser instanceof WP_User) {
    wp_delete_user($existingUser->ID);
}
$userId = wp_insert_user([
    'user_login' => $userLogin,
    'user_pass' => $password,
    'user_email' => 'tuc-mobile-e2e@example.invalid',
    'display_name' => 'Restaurateur E2E (fictif)',
    'role' => 'tuc_restaurateur',
]);
if (is_wp_error($userId)) {
    WP_CLI::error('Impossible de créer le compte de test : ' . $userId->get_error_message());
}

$restaurantId = wp_insert_post([
    'post_type' => TUC_CPT::RESTO,
    'post_status' => 'publish',
    'post_title' => 'Restaurant E2E fictif',
    'post_name' => 'tuc-mobile-e2e',
], true);
if (is_wp_error($restaurantId)) {
    WP_CLI::error('Impossible de créer le restaurant fictif : ' . $restaurantId->get_error_message());
}
update_user_meta((int) $userId, 'tuc_resto_id', (int) $restaurantId);
update_post_meta((int) $restaurantId, 'tuc_ferme', '0');
update_post_meta((int) $restaurantId, 'tuc_frais', 5);
update_post_meta((int) $restaurantId, 'tuc_minimum', 10);
update_post_meta((int) $restaurantId, 'tuc_modes', ['livraison', 'emporter', 'table']);
update_post_meta((int) $restaurantId, 'tuc_tables', 7);

$options = [
    'version' => TUC_Options::VERSION,
    'groups' => [
        [
            'id' => 'format', 'name' => 'Format', 'required' => true, 'min' => 1, 'max' => 1,
            'choices' => [
                ['id' => 'regular', 'name' => 'Régulier', 'price_delta' => 0, 'available' => true],
                ['id' => 'large', 'name' => 'Grand', 'price_delta' => 10, 'available' => true],
            ],
        ],
        [
            'id' => 'sauce', 'name' => 'Sauce', 'required' => false, 'min' => 0, 'max' => 1,
            'visible_if' => ['group_id' => 'format', 'choice_ids' => ['large']],
            'choices' => [
                ['id' => 'bbq', 'name' => 'BBQ', 'price_delta' => 2, 'available' => true],
            ],
        ],
    ],
];
$itemId = wp_insert_post([
    'post_type' => TUC_CPT::ITEM,
    'post_status' => 'publish',
    'post_title' => 'Plat initial E2E',
    'post_content' => 'Plat de test synthétique; aucun restaurant ou client réel.',
    'meta_input' => [
        'tuc_resto_id' => (int) $restaurantId,
        'tuc_prix' => 20,
        'tuc_promo_pct' => 0,
        'tuc_dispo' => '1',
        'tuc_options' => $options,
        'tuc_options_schema_version' => TUC_Options::VERSION,
    ],
], true);
if (is_wp_error($itemId)) {
    WP_CLI::error('Impossible de créer le plat E2E : ' . $itemId->get_error_message());
}

$orderRequest = new WP_REST_Request('POST', '/tuc/v1/commande');
$orderRequest->set_param('resto', 'tuc-mobile-e2e');
$orderRequest->set_param('table', 0);
$orderRequest->set_param('mode', 'livraison');
$orderRequest->set_param('tel', '+212600000099');
$orderRequest->set_param('total', 25);
$orderRequest->set_param('cle', 'mobile-fixture-' . wp_generate_uuid4());
$orderRequest->set_param('items', [[
    'id' => (int) $itemId,
    'q' => 1,
    'options' => [['group_id' => 'format', 'choices' => ['regular']]],
]]);
$order = TUC_Commande::creer($orderRequest);
if (is_wp_error($order) || !$order instanceof WP_REST_Response || $order->get_status() !== 201) {
    WP_CLI::error('Impossible de créer la commande E2E : ' . (is_wp_error($order) ? $order->get_error_message() : 'réponse inattendue'));
}
WP_CLI::log('Fixtures synthétiques prêtes : restaurant=tuc-mobile-e2e, compte=tuc-mobile-e2e, commande=' . (int) $order->get_data()['commande']);
