<?php
/**
 * Prépare ou vérifie un parcours WhatsApp simulé via le workflow n8n de CI.
 * Exécution : TUC_N8N_E2E_PHASE=setup|verify wp eval-file ...
 */

if (!class_exists('WP_CLI')) {
    fwrite(STDERR, "Ce script doit être exécuté avec WP-CLI.\n");
    exit(1);
}

$phase = (string) getenv('TUC_N8N_E2E_PHASE');
$secret = (string) getenv('TUC_WA_SECRET');
$pollSecret = (string) getenv('TUC_N8N_POLL_SECRET');
$codePhones = [
    'N8NTEST1' => '+212600000099',
    'N8NTEST2' => '+212600000098',
    'N8NTEST3' => '+212600000097',
    'N8NTEST4' => '+212600000096',
];
$pollCode = 'N8NTEST1';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'N8N_E2E_ASSERTION_FAILURE: ' . $message . "\n");
        throw new RuntimeException($message);
    }
    WP_CLI::log('OK n8n E2E : ' . $message);
};

if ($phase === 'setup') {
    if (strlen($secret) < 32 || strlen($pollSecret) < 32) {
        throw new RuntimeException('Les secrets éphémères du test doivent faire au moins 32 caractères.');
    }
    $hasOldSecret = get_option('tuc_wa_secret', false) !== false;
    update_option('tuc_n8n_e2e_old_secret', [
        'exists' => $hasOldSecret,
        'value' => get_option('tuc_wa_secret', false),
    ], false);
    update_option('tuc_wa_secret', $secret, false);
    foreach ($codePhones as $code => $phone) {
        delete_transient('tuc_wa_' . $code);
        delete_transient('tuc_wa_ok_' . $code);
        set_transient('tuc_wa_' . $code, [
            'but' => 'compte',
            'resto' => '',
            'table' => 0,
            'poll_hash' => hash('sha256', $pollSecret),
        ], 10 * MINUTE_IN_SECONDS);
    }
    WP_CLI::success('Fixtures d’intégration n8n/WhatsApp préparées.');
    return;
}

if ($phase !== 'verify') {
    throw new RuntimeException('Définir TUC_N8N_E2E_PHASE à setup ou verify.');
}

$user = TUC_Compte::par_telephone($codePhones[$pollCode]);
try {
    $assert(get_transient('tuc_wa_' . $pollCode) === false, 'n8n a transmis le message valide et le code à usage unique a été consommé');
    foreach (['N8NTEST2', 'N8NTEST3', 'N8NTEST4'] as $code) {
        $assert(get_transient('tuc_wa_' . $code) !== false, 'le code ' . $code . ' est intact après rejeu/signature invalide/horodatage expiré');
    }
    $assert($user instanceof WP_User, 'le hook n8n a créé le compte associé au téléphone vérifié');

    $deniedPoll = new WP_REST_Request('GET', '/tuc/v1/wa-statut');
    $deniedPoll->set_param('code', $pollCode);
    $deniedPoll->set_param('poll', 'wrong-secret');
    $deniedResponse = TUC_Compte::wa_statut($deniedPoll);
    $assert(($deniedResponse->get_data()['pret'] ?? true) === false, 'le lien magique n’est pas accessible sans le secret navigateur');

    $poll = new WP_REST_Request('GET', '/tuc/v1/wa-statut');
    $poll->set_param('code', $pollCode);
    $poll->set_param('poll', $pollSecret);
    $pollResponse = TUC_Compte::wa_statut($poll);
    $magicUrl = (string) ($pollResponse->get_data()['magie'] ?? '');
    $assert(($pollResponse->get_data()['pret'] ?? false) === true && $magicUrl !== '', 'le polling autorisé récupère le lien magique');

    $query = [];
    wp_parse_str((string) parse_url($magicUrl, PHP_URL_QUERY), $query);
    $token = (string) ($query['tuc_magie'] ?? '');
    $assert($token !== '', 'le lien retourné contient un jeton de connexion');

    $consume = new WP_REST_Request('POST', '/tuc/v1/magie');
    $consume->set_param('jeton', $token);
    $loginResponse = TUC_Compte::consommer_magie($consume);
    $assert($loginResponse instanceof WP_REST_Response
        && $loginResponse->get_status() === 200
        && get_current_user_id() === $user->ID, 'le lien magique connecte le compte WordPress attendu');

    $replay = TUC_Compte::consommer_magie($consume);
    $assert(is_wp_error($replay) && (int) ($replay->get_error_data()['status'] ?? 0) === 410,
        'le lien magique ne peut pas être réutilisé');

    WP_CLI::success('Le parcours n8n → webhook WordPress → polling → lien magique à usage unique est passé.');
} finally {
    wp_set_current_user(0);
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    if ($user instanceof WP_User) {
        wp_delete_user($user->ID);
    }
    foreach ($codePhones as $code => $phone) {
        delete_transient('tuc_wa_' . $code);
        delete_transient('tuc_wa_ok_' . $code);
        delete_transient('tuc_wa_event_' . hash('sha256', 'e2e-event-success-01'));
    }
    $oldSecret = get_option('tuc_n8n_e2e_old_secret', false);
    if (is_array($oldSecret) && !empty($oldSecret['exists'])) {
        update_option('tuc_wa_secret', $oldSecret['value'], false);
    } else {
        delete_option('tuc_wa_secret');
    }
    delete_option('tuc_n8n_e2e_old_secret');
}
