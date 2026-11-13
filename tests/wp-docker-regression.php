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
        fwrite(STDERR, 'ASSERTION_FAILURE: ' . $message . "\n");
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
    } elseif ($route === '/tuc/v1/commande/suivi') {
        $result = TUC_Commande::suivre($request);
    } elseif ($route === '/tuc/v1/wa-statut') {
        $result = TUC_Compte::wa_statut($request);
    } elseif ($route === '/tuc/v1/wa-lien') {
        $result = TUC_Compte::wa_lien($request);
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

    // Les en-têtes de sécurité dépendent de WP_ENVIRONMENT_TYPE. Basculer un hôte
    // en « staging » (pour laisser passer le seed de démonstration) retire HSTS :
    // cet effet de bord doit être un contrat testé, pas une découverte en prod.
    $assert(has_action('send_headers', [TUC_Security_Headers::class, 'send']) !== false, 'les en-têtes de sécurité sont branchés sur send_headers');
$assert(has_filter('rest_post_dispatch', [TUC_Security_Headers::class, 'tune_menu_response']) !== false, 'la duree de cache du menu est branchee sur rest_post_dispatch');
$assert(has_action('updated_post_meta', [TUC_Security_Headers::class, 'purge_menu_on_price_change']) !== false, 'la purge du menu est declenchee a chaque ecriture de prix');
    $headerEnvironments = ['production', 'staging', 'development', 'local', ''];
    foreach ($headerEnvironments as $headerEnvironment) {
        $emitted = TUC_Security_Headers::headers_for(true, $headerEnvironment);
        $assert(($emitted['X-Content-Type-Options'] ?? '') === 'nosniff'
            && ($emitted['X-Frame-Options'] ?? '') === 'SAMEORIGIN'
            && ($emitted['Referrer-Policy'] ?? '') === 'strict-origin-when-cross-origin'
            && ($emitted['Permissions-Policy'] ?? '') === 'geolocation=(self), camera=(), microphone=()',
            'les en-têtes invariants sont émis quel que soit l’environnement (' . ($headerEnvironment ?: 'inconnu') . ')');
    }
    $assert(TUC_Security_Headers::menu_cache_headers(false)['Cache-Control'] === 'public, max-age=60',
    'le menu public est re-cadre a 60 s de cache');
$assert(TUC_Security_Headers::menu_cache_headers(true) === ['Cache-Control' => 'no-store'],
    'une erreur de menu ne se met jamais en cache');
$assert(TUC_Security_Headers::is_menu_route('/tuc/v1/menu/kfc') && !TUC_Security_Headers::is_menu_route('/tuc/v1/pro/menu'),
    'seule la route publique du menu est concernee');
$assert(null === TUC_Security_Headers::menu_purge_path('kfc', true) && null === TUC_Security_Headers::menu_purge_path("kfc'\nX"),
    'aucune purge promise depuis un contexte sans reponse, et aucun slug douteux');
$assert(TUC_Security_Headers::headers_for(true, 'production')['Strict-Transport-Security'] === 'max-age=31536000',
        'HSTS est émis en production sur HTTPS');
    $assert(!array_key_exists('Strict-Transport-Security', TUC_Security_Headers::headers_for(true, 'staging')),
        'HSTS est retiré en staging : l’UAT ne doit pas épingler les navigateurs en HTTPS');
    $assert(!array_key_exists('Strict-Transport-Security', TUC_Security_Headers::headers_for(false, 'production')),
        'HSTS n’est jamais émis sans HTTPS vérifié');
    $assert(!array_key_exists('Strict-Transport-Security', TUC_Security_Headers::headers_for(true, '')),
        'un environnement inconnu n’émet pas HSTS');

    // Indexabilite : un environnement de test n'a aucune raison d'apparaitre dans
    // Google, et l'inverse (un noindex qui partirait en production) est une
    // disparition. Les deux tables sont donc testees dans les deux sens,
    // environnement par environnement.
    foreach (['local', 'development', 'staging'] as $envTest) {
        $assert((TUC_Security_Headers::headers_for(true, $envTest)['X-Robots-Tag'] ?? '') === 'noindex'
            && (TUC_Security_Headers::headers_for(false, $envTest)['X-Robots-Tag'] ?? '') === 'noindex',
            'X-Robots-Tag: noindex est émis en ' . $envTest . ', HTTP comme en HTTPS');
        $assert(TUC_Security_Headers::robots_for($envTest) === ['noindex' => true],
            'la directive robots est posée en ' . $envTest);
    }
    foreach (['production', ''] as $envPublic) {
        $assert(!array_key_exists('X-Robots-Tag', TUC_Security_Headers::headers_for(true, $envPublic)),
            'aucun X-Robots-Tag en ' . ($envPublic ?: 'environnement inconnu') . ' : pas de noindex par accident');
        $assert(TUC_Security_Headers::robots_for($envPublic) === [],
            'aucune directive robots en ' . ($envPublic ?: 'environnement inconnu'));
    }
    $assert(has_filter('wp_robots', [TUC_Security_Headers::class, 'robots']) !== false,
        'les directives robots sont branchées sur wp_robots');
    $assert(apply_filters('xmlrpc_enabled', true) === false,
        'XML-RPC est désactivé : le produit ne s’en sert pas, la surface reste un vecteur');
    $assert(TUC_Geo::distance2(33.5900, -7.6280, 33.5900, -7.6280) === 0.0
        && is_infinite(TUC_Geo::distance2(91, 0, 0, 0)), 'la distance géographique est métrique et rejette les coordonnées impossibles');
    $geoSorted = TUC_Geo::trier([
        ['id' => 'far', 'lat' => 33.6000, 'lng' => -7.6500],
        ['id' => 'missing'],
        ['id' => 'near', 'lat' => 33.5901, 'lng' => -7.6281],
    ], 33.5900, -7.6280);
    $assert(array_column($geoSorted, 'id') === ['near', 'far', 'missing'], 'la recherche proximité trie par distance réelle et place les restaurants sans coordonnées en dernier');
    $assert(has_action('rest_api_init', [TUC_Health::class, 'routes']) !== false, 'route de santé branchée sur rest_api_init');
    $assert(has_action('rest_api_init', [TUC_Commande::class, 'routes']) !== false, 'routes de commande branchées sur rest_api_init');
    $assert(has_action('admin_menu', [TUC_Espace_Pro::class, 'admin_menu']) !== false
        && has_action('admin_post_tuc_affecter_membre', [TUC_Espace_Pro::class, 'affecter_membre']) !== false,
        'page d’affectation et action protégée des comptes sont branchées');
    $registeredRoutes = rest_get_server()->get_routes();
    $cashierRoute = $registeredRoutes['/tuc/v1/caisse/commandes'][0] ?? [];
    $openingRoute = $registeredRoutes['/tuc/v1/caisse/ouverture'][0] ?? [];
    $trackingRoute = $registeredRoutes['/tuc/v1/commande/suivi'][0] ?? [];
    $proOrdersRoute = $registeredRoutes['/tuc/v1/pro/commandes'][0] ?? [];
    $proOrderStatusRoute = $registeredRoutes['/tuc/v1/pro/commandes/(?P<id>\\d+)/statut'][0] ?? [];
    $proSettingsRoutes = $registeredRoutes['/tuc/v1/pro/restaurant'] ?? [];
    $proLocationRoute = $registeredRoutes['/tuc/v1/pro/restaurant/location'][0] ?? [];
    $legacyStatusRoute = $registeredRoutes['/tuc/v1/commande/(?P<id>\d+)/statut'][0] ?? [];
    $proMenuRoutes = $registeredRoutes['/tuc/v1/pro/menu'] ?? [];
    $proItemRoute = $registeredRoutes['/tuc/v1/pro/menu/(?P<id>\d+)'][0] ?? [];
    $proPhotoRoute = $registeredRoutes['/tuc/v1/pro/menu/(?P<id>\d+)/photo'][0] ?? [];
    $publicMenuRoute = $registeredRoutes['/tuc/v1/menu/(?P<slug>[a-z0-9-]+)'][0] ?? [];
    $assert(count($proMenuRoutes) >= 2
        && count(array_filter($proMenuRoutes, static fn(array $route): bool => is_callable($route['permission_callback'] ?? null))) === count($proMenuRoutes)
        && is_callable($proItemRoute['permission_callback'] ?? null)
        && is_callable($proPhotoRoute['permission_callback'] ?? null)
        && is_callable($publicMenuRoute['callback'] ?? null), 'API de gestion menu/médias privée et route menu public enregistrées');
    $assert(is_callable($cashierRoute['permission_callback'] ?? null)
        && is_callable($openingRoute['permission_callback'] ?? null)
        && is_callable($legacyStatusRoute['permission_callback'] ?? null), 'les endpoints caisse/statut sont effectivement enregistrés avec permission_callback');
    $assert(is_callable($trackingRoute['callback'] ?? null)
        && is_callable($proOrdersRoute['permission_callback'] ?? null)
        && is_callable($proOrderStatusRoute['permission_callback'] ?? null)
        && count($proSettingsRoutes) >= 2
        && count(array_filter($proSettingsRoutes, static fn(array $route): bool => is_callable($route['permission_callback'] ?? null))) === count($proSettingsRoutes)
        && is_callable($proLocationRoute['permission_callback'] ?? null)
        && is_callable($proLocationRoute['callback'] ?? null),
        'suivi public tokenisé et API mobile commandes/boutique sont enregistrés');
    TUC_Roles::synchroniser();
    $ownerRole = get_role('tuc_restaurateur');
    $managerRole = get_role('tuc_gestionnaire');
    $cashierRole = get_role('tuc_caissier');
    $serverRole = get_role('tuc_serveur');
    $assert($ownerRole !== null && $managerRole !== null && $cashierRole !== null && $serverRole !== null, 'les quatre rôles métier TUC sont définis');
    $assert($ownerRole->has_cap(TUC_Roles::CAP_MANAGE_STAFF)
        && !$managerRole->has_cap(TUC_Roles::CAP_MANAGE_STAFF)
        && $cashierRole->has_cap(TUC_Roles::CAP_UPDATE_ORDERS)
        && $serverRole->has_cap(TUC_Roles::CAP_ACCESS_CAISSE)
        && $serverRole->has_cap(TUC_Roles::CAP_VIEW_ORDERS)
        && $serverRole->has_cap(TUC_Roles::CAP_UPDATE_ORDERS)
        && $serverRole->has_cap(TUC_Roles::CAP_AUDIBLE_ORDER_ALERTS), 'le serveur suit les commandes et seul son rôle reçoit les alertes prioritaires');
    $assert(!$ownerRole->has_cap('manage_options') && !$managerRole->has_cap('manage_options')
        && !$cashierRole->has_cap('manage_options') && !$serverRole->has_cap('manage_options'), 'aucun rôle restaurateur ne reçoit les droits administrateur WordPress');
    $assert(!$ownerRole->has_cap('edit_tuc_menu_items') && !$managerRole->has_cap('edit_tuc_menu_items'),
        'le CPT menu natif ne contourne pas les routes tenant-scoped de l’Espace Pro');
    $administratorRole = get_role('administrator');
    $assert($administratorRole !== null && $administratorRole->has_cap('edit_tuc_menu_items'),
        'seuls les administrateurs conservent l’éditeur natif du CPT menu');
    $assert(TUC_Caisse::CAP === TUC_Roles::CAP_ACCESS_CAISSE, 'la caisse s’appuie sur une capacité métier dédiée');
    $optionDefinition = [
        'version' => 2,
        'groups' => [
            ['id' => 'format', 'name' => 'Format', 'required' => true, 'min' => 1, 'max' => 1, 'choices' => [
                ['id' => 'regular', 'name' => 'Régulier', 'price_delta' => 0],
                ['id' => 'xl', 'name' => 'XL', 'price_delta' => 10],
            ]],
            ['id' => 'extras', 'name' => 'Suppléments', 'required' => false, 'min' => 0, 'max' => 2, 'choices' => [
                ['id' => 'cheese', 'name' => 'Fromage', 'price_delta' => 3],
                ['id' => 'olives', 'name' => 'Olives', 'price_delta' => 2],
                ['id' => 'jalapeno', 'name' => 'Jalapeño', 'price_delta' => 1],
            ]],
            ['id' => 'xl_sauce', 'name' => 'Sauce XL', 'required' => true, 'min' => 1, 'max' => 1,
                'visible_if' => ['group_id' => 'format', 'choice_ids' => ['xl']], 'choices' => [
                    ['id' => 'bbq', 'name' => 'BBQ', 'price_delta' => 1],
                ]],
        ],
    ];
    $validOptionSelection = TUC_Options::valider_selection($optionDefinition, [
        ['group_id' => 'format', 'choices' => ['xl']],
        ['group_id' => 'extras', 'choices' => ['cheese', 'olives']],
        ['group_id' => 'xl_sauce', 'choices' => ['bbq']],
    ]);
    $assert(!is_wp_error($validOptionSelection) && abs($validOptionSelection['surcharge'] - 16) < 0.001,
        'les groupes, suppléments et choix conditionnels sont recalculés côté serveur');
    $assert(is_wp_error(TUC_Options::valider_selection($optionDefinition, [])), 'un groupe obligatoire sans choix est refusé');
    $assert(is_wp_error(TUC_Options::valider_selection($optionDefinition, [
        ['group_id' => 'format', 'choices' => ['regular']],
        ['group_id' => 'xl_sauce', 'choices' => ['bbq']],
    ])), 'un choix conditionnel invisible est refusé');
    $assert(is_wp_error(TUC_Options::valider_selection($optionDefinition, [
        ['group_id' => 'format', 'choices' => ['regular']],
        ['group_id' => 'extras', 'choices' => ['cheese', 'olives', 'jalapeno']],
    ])), 'le maximum de sélections d’un groupe est appliqué');
    $assert(!is_wp_error(TUC_Options::valider_selection([
        ['id' => 'extra-fromage', 'nom' => 'Extra fromage', 'prix' => 3],
    ], [['id' => 'extra-fromage']])), 'les anciennes options plates restent compatibles');
    $assert(!TUC_Push::endpoint_valide('http://127.0.0.1/internal')
        && !TUC_Push::endpoint_valide('https://localhost/push')
        && !TUC_Push::endpoint_valide('https://127.0.0.1:8443/push'), 'les endpoints Web Push locaux/non HTTPS sont rejetés');

    delete_option('tuc_wa_secret');
    $hookRequest = new WP_REST_Request('POST', '/tuc/v1/wa-hook');
    $assert(!TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp est fermé si aucun secret n’est configuré');
    update_option('tuc_wa_secret', 'weak');
    $assert(!TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp refuse un secret trop court');
    $hookSecret = 'test-only-secret-9f7bdc-329d5bbcbda410d5';
    update_option('tuc_wa_secret', $hookSecret);
    $hookCode = 'TESTCODE';
    $hookTel = '+212600000099';
    $hookTimestamp = (string) time();
    $hookEventId = 'regression-event-' . wp_generate_uuid4();
    $hookMessage = implode("\n", [$hookTimestamp, $hookEventId, $hookCode, TUC_Compte::normaliser($hookTel)]);
    $hookSignature = hash_hmac('sha256', $hookMessage, $hookSecret);
    $hookRequest->set_param('code', $hookCode);
    $hookRequest->set_param('tel', $hookTel);
    $hookRequest->set_header('x-tuc-timestamp', $hookTimestamp);
    $hookRequest->set_header('x-tuc-event-id', $hookEventId);
    $hookRequest->set_header('x-tuc-signature', str_repeat('0', 64));
    $assert(!TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp refuse une signature HMAC incorrecte');
    $hookRequest->set_header('x-tuc-signature', $hookSignature);
    $assert(TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp accepte une signature HMAC valide');
    $assert(!TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp rejette le rejeu d’un event_id déjà traité');
    $staleTimestamp = (string) (time() - 600);
    $staleEventId = 'stale-event-' . wp_generate_uuid4();
    $staleMessage = implode("\n", [$staleTimestamp, $staleEventId, $hookCode, TUC_Compte::normaliser($hookTel)]);
    $hookRequest->set_header('x-tuc-timestamp', $staleTimestamp);
    $hookRequest->set_header('x-tuc-event-id', $staleEventId);
    $hookRequest->set_header('x-tuc-signature', hash_hmac('sha256', $staleMessage, $hookSecret));
    $assert(!TUC_Compte::verif_secret($hookRequest), 'le webhook WhatsApp refuse une requête signée trop ancienne');
    delete_transient('tuc_wa_event_' . hash('sha256', $hookEventId));

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
    update_post_meta($restaurantId, 'tuc_modes', ['livraison']);

    $itemId = wp_insert_post([
        'post_type'   => 'tuc_menu_item',
        'post_status' => 'publish',
        'post_title'  => 'Plat Docker Smoke ' . $suffix,
    ], true);
    $assert(!is_wp_error($itemId), 'fixture article créée');
    $created['items'][] = (int) $itemId;
    update_post_meta($itemId, 'tuc_resto_id', (int) $restaurantId);
    update_post_meta($itemId, 'tuc_prix', 10);
    $legacyOptions = [
        ['id' => 'extra-fromage', 'nom' => 'Extra fromage', 'prix' => 3],
    ];
    update_post_meta($itemId, 'tuc_options', $legacyOptions);
    $migrationDryRun = TUC_Options::migrer_item((int) $itemId, false);
    $assert(!is_wp_error($migrationDryRun) && $migrationDryRun['state'] === 'ready'
        && get_post_meta($itemId, 'tuc_options', true) === $legacyOptions
        && !metadata_exists('post', (int) $itemId, 'tuc_options_legacy_backup'),
        'le dry-run de migration ne modifie pas les options historiques');
    $migrationApplied = TUC_Options::migrer_item((int) $itemId, true);
    $migratedOptions = get_post_meta($itemId, 'tuc_options', true);
    $assert(!is_wp_error($migrationApplied) && $migrationApplied['state'] === 'migrated'
        && get_post_meta($itemId, 'tuc_options_legacy_backup', true) === $legacyOptions
        && (int) get_post_meta($itemId, 'tuc_options_schema_version', true) === TUC_Options::VERSION
        && ($migratedOptions['groups'][0]['id'] ?? '') === 'options',
        'la migration applique v2 et préserve une copie exacte des données legacy');
    $migrationAgain = TUC_Options::migrer_item((int) $itemId, true);
    $assert(!is_wp_error($migrationAgain) && $migrationAgain['state'] === 'current'
        && get_post_meta($itemId, 'tuc_options_legacy_backup', true) === $legacyOptions,
        'la migration est idempotente et ne remplace pas la sauvegarde d’origine');
    $rollbackDryRun = TUC_Options::restaurer_legacy_item((int) $itemId, false);
    $assert(!is_wp_error($rollbackDryRun) && $rollbackDryRun['state'] === 'ready'
        && get_post_meta($itemId, 'tuc_options', true) === $migratedOptions,
        'le dry-run de restauration ne modifie pas les options v2');
    $rollbackApplied = TUC_Options::restaurer_legacy_item((int) $itemId, true);
    $assert(!is_wp_error($rollbackApplied) && $rollbackApplied['state'] === 'restored'
        && get_post_meta($itemId, 'tuc_options', true) === $legacyOptions
        && metadata_exists('post', (int) $itemId, 'tuc_options_legacy_backup')
        && !metadata_exists('post', (int) $itemId, 'tuc_options_schema_version'),
        'la restauration remet exactement l’option legacy et conserve sa sauvegarde');
    $migrationAfterRollback = TUC_Options::migrer_item((int) $itemId, true);
    $assert(!is_wp_error($migrationAfterRollback) && $migrationAfterRollback['state'] === 'migrated'
        && get_post_meta($itemId, 'tuc_options_legacy_backup', true) === $legacyOptions,
        'la migration reste relançable après une restauration');
    $emptyOptionsItemId = wp_insert_post([
        'post_type' => 'tuc_menu_item', 'post_status' => 'draft', 'post_title' => 'Plat sans métadonnée options ' . $suffix,
    ], true);
    $assert(!is_wp_error($emptyOptionsItemId), 'fixture article sans anciennes options créée');
    $created['items'][] = (int) $emptyOptionsItemId;
    $emptyOptionsMigration = TUC_Options::migrer_item((int) $emptyOptionsItemId, true);
    $emptyOptionsRollback = TUC_Options::restaurer_legacy_item((int) $emptyOptionsItemId, true);
    $assert(!is_wp_error($emptyOptionsMigration) && !is_wp_error($emptyOptionsRollback)
        && !metadata_exists('post', (int) $emptyOptionsItemId, 'tuc_options')
        && !rest_sanitize_boolean(get_post_meta((int) $emptyOptionsItemId, 'tuc_options_legacy_had_value', true)),
        'la restauration distingue une métadonnée absente d’une valeur legacy vide');
    $invalidOptionsItemId = wp_insert_post([
        'post_type' => 'tuc_menu_item', 'post_status' => 'draft', 'post_title' => 'Plat options invalides ' . $suffix,
    ], true);
    $assert(!is_wp_error($invalidOptionsItemId), 'fixture article avec options legacy invalides créée');
    $created['items'][] = (int) $invalidOptionsItemId;
    $invalidLegacyOptions = ['invalide' => 'structure'];
    update_post_meta($invalidOptionsItemId, 'tuc_options', $invalidLegacyOptions);
    $invalidMigration = TUC_Options::migrer_item((int) $invalidOptionsItemId, true);
    $assert(is_wp_error($invalidMigration)
        && get_post_meta($invalidOptionsItemId, 'tuc_options', true) === $invalidLegacyOptions
        && !metadata_exists('post', (int) $invalidOptionsItemId, 'tuc_options_legacy_backup'),
        'une définition invalide reste intacte et n’est pas migrée');

    $restaurant = get_post($restaurantId);
    $slug = (string) $restaurant->post_name;
    $idempotenceKey = 'docker-' . $suffix;
    $validOrder = [
        'resto' => $slug,
        'table' => 0,
        'tel'   => '+212600000001',
        'total' => 31,
        'lat'   => 33.589,
        'lng'   => -7.621,
        'accuracy' => 75,
        'captured_at' => time(),
        'cle'   => $idempotenceKey,
        'items' => [[
            'id'      => (int) $itemId,
            'q'       => 2,
            'options' => [['id' => 'extra-fromage']],
            // Valeurs falsifiées : le serveur doit les ignorer et retenir ses
            // propres libellés et prix. Assertion portée depuis /wa-panier.
            'nom'     => 'Libellé falsifié',
            'pu'      => 0.01,
        ]],
    ];
    $ratePhones[] = $validOrder['tel'];
    $lockedOrder = $validOrder;
    $lockedOrder['cle'] = 'parallel-submit-' . $suffix;
    $lockedOrder['tel'] = '+212600000099';
    $ratePhones[] = $lockedOrder['tel'];
    $lockOption = 'tuc_order_lock_' . md5((int) $restaurantId . '|' . $lockedOrder['cle']);
    add_option($lockOption, time(), '', false);
    $lockedResponse = $rest('/tuc/v1/commande', $lockedOrder);
    delete_option($lockOption);
    $assert($lockedResponse->get_status() === 409, 'un double envoi simultané est bloqué par le verrou idempotent atomique');

    $invalidPhoneOrder = $validOrder;
    $invalidPhoneOrder['tel'] = '123';
    $invalidPhoneOrder['cle'] = 'invalid-phone-' . $suffix;
    $assert($rest('/tuc/v1/commande', $invalidPhoneOrder)->get_status() === 422, 'un numéro non E.164 ne peut pas commander');
    $invalidKeyOrder = $validOrder;
    $invalidKeyOrder['cle'] = str_repeat('x', 65);
    $assert($rest('/tuc/v1/commande', $invalidKeyOrder)->get_status() === 422, 'une clé d’idempotence surdimensionnée est refusée');
    $weakKeyOrder = $validOrder;
    $weakKeyOrder['cle'] = 'short-key';
    $assert($rest('/tuc/v1/commande', $weakKeyOrder)->get_status() === 422, 'une clé d’idempotence trop courte est refusée');
    $missingCoordinates = $validOrder;
    unset($missingCoordinates['lat'], $missingCoordinates['lng']);
    $missingCoordinates['cle'] = 'missing-location-' . $suffix;
    $assert($rest('/tuc/v1/commande', $missingCoordinates)->get_status() === 422,
        'une commande en livraison sans position explicite est refusée');
    $invalidCoordinates = $validOrder;
    $invalidCoordinates['lat'] = 91;
    $invalidCoordinates['cle'] = 'invalid-location-' . $suffix;
    $assert($rest('/tuc/v1/commande', $invalidCoordinates)->get_status() === 422,
        'la livraison refuse des coordonnées impossibles avant de créer une commande');
    $impreciseLocation = $validOrder;
    $impreciseLocation['accuracy'] = TUC_Commande::MAX_LOCATION_ACCURACY_METERS + 1;
    $impreciseLocation['cle'] = 'imprecise-location-' . $suffix;
    $assert($rest('/tuc/v1/commande', $impreciseLocation)->get_status() === 422,
        'une position dont la précision dépasse 250 mètres est refusée');
    $staleLocation = $validOrder;
    $staleLocation['captured_at'] = time() - TUC_Commande::MAX_LOCATION_AGE_SECONDS - 1;
    $staleLocation['cle'] = 'stale-location-' . $suffix;
    $assert($rest('/tuc/v1/commande', $staleLocation)->get_status() === 422,
        'une position datant de plus de 30 jours est refusée');
    $missingAccuracy = $validOrder;
    unset($missingAccuracy['accuracy']);
    $missingAccuracy['cle'] = 'missing-accuracy-' . $suffix;
    $assert($rest('/tuc/v1/commande', $missingAccuracy)->get_status() === 422,
        'une livraison doit transmettre la précision réelle de la position');
    update_post_meta($restaurantId, 'tuc_modes', ['livraison', 'emporter']);
    $nonDeliveryLocation = $validOrder;
    $nonDeliveryLocation['mode'] = 'emporter';
    $nonDeliveryLocation['total'] = 26;
    $nonDeliveryLocation['cle'] = 'location-non-delivery-' . $suffix;
    $assert($rest('/tuc/v1/commande', $nonDeliveryLocation)->get_status() === 422,
        'les coordonnées ne sont acceptées que pour le mode livraison');
    update_post_meta($restaurantId, 'tuc_modes', ['livraison']);

    // Le total serveur = (10 + 3) × 2 + 5 frais = 31 MAD.
    $orderResponse = $rest('/tuc/v1/commande', $validOrder);
    $orderData = $orderResponse->get_data();
    $assert($orderResponse->get_status() === 201 && ($orderData['ok'] ?? false) === true, 'commande valide créée par la route REST');
    $assert(abs((float) ($orderData['total'] ?? 0) - 31.0) < 0.001, 'le serveur recalcule plat, supplément, quantité et frais');
    $orderId = (int) ($orderData['commande'] ?? 0);
    $assert($orderId > 0, 'la réponse retourne un identifiant de commande');
    $assert(abs((float) get_post_meta($orderId, 'tuc_location_lat', true) - 33.589) < 0.000001
        && abs((float) get_post_meta($orderId, 'tuc_location_lng', true) + 7.621) < 0.000001
        && abs((float) get_post_meta($orderId, 'tuc_location_accuracy', true) - 75.0) < 0.001,
        'la commande conserve uniquement la position consentie, sa précision et l’heure de capture');
    $created['orders'][] = $orderId;
    $trackingToken = (string) ($orderData['suivi'] ?? '');
    $assert((bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $trackingToken)
        && hash_equals((string) get_post_meta($orderId, 'tuc_suivi_hash', true), hash('sha256', $trackingToken))
        && !metadata_exists('post', $orderId, 'tuc_suivi_token'),
        'le client reçoit un jeton opaque et seul son hash est conservé');
    $trackingResponse = $rest('/tuc/v1/commande/suivi', ['jeton' => $trackingToken]);
    $trackingData = $trackingResponse->get_data();
    $assert($trackingResponse->get_status() === 200 && ($trackingData['status'] ?? '') === 'nouvelle'
        && ($trackingData['label'] ?? '') === 'Reçue', 'un jeton valide retourne le statut de la commande');
    $assert(array_intersect(['tel', 'phone', 'items', 'total', 'tuc_tel'], array_keys($trackingData)) === [],
        'le suivi client ne révèle ni téléphone, ni contenu de commande, ni total');
    $assert($rest('/tuc/v1/commande/suivi', ['jeton' => str_repeat('x', 43)])->get_status() === 404,
        'un jeton inconnu ne révèle pas l’existence d’une commande');
    $oldTrackingExpiry = (int) get_post_meta($orderId, 'tuc_suivi_expire', true);
    update_post_meta($orderId, 'tuc_suivi_expire', time() - 1);
    $assert($rest('/tuc/v1/commande/suivi', ['jeton' => $trackingToken])->get_status() === 410,
        'le lien de suivi expire après sa durée de validité');
    update_post_meta($orderId, 'tuc_suivi_expire', $oldTrackingExpiry);

    $itemTitle = (string) get_post((int) $itemId)->post_title;
    $storedItems = (array) get_post_meta($orderId, 'tuc_items', true);
    $assert(($storedItems[0]['opts'][0] ?? '') === 'Extra fromage +3', 'le libellé et le supplément sont stockés lisiblement');
    $assert(($storedItems[0]['nom'] ?? '') === $itemTitle
        && !str_contains(wp_json_encode($storedItems), 'Libellé falsifié'),
        'la commande stocke les libellés WordPress et ignore nom/prix fournis par le client');

    $optionItemId = wp_insert_post([
        'post_type' => 'tuc_menu_item', 'post_status' => 'publish',
        'post_title' => 'Plat options v2 ' . $suffix,
    ], true);
    $assert(!is_wp_error($optionItemId), 'fixture article options v2 créée');
    $created['items'][] = (int) $optionItemId;
    update_post_meta($optionItemId, 'tuc_resto_id', (int) $restaurantId);
    update_post_meta($optionItemId, 'tuc_prix', 20);
    update_post_meta($optionItemId, 'tuc_promo_pct', 10);
    update_post_meta($optionItemId, 'tuc_options', $optionDefinition);
    $optionOrderPhone = '+212600000031';
    $ratePhones[] = $optionOrderPhone;
    $optionOrder = $rest('/tuc/v1/commande', [
        'resto' => $slug, 'table' => 0, 'tel' => $optionOrderPhone, 'total' => 39,
        'lat' => 33.589, 'lng' => -7.621, 'accuracy' => 75, 'captured_at' => time(),
        'cle' => 'options-v2-' . $suffix,
        'items' => [[
            'id' => (int) $optionItemId, 'q' => 1,
            'options' => [
                ['group_id' => 'format', 'choices' => ['xl']],
                ['group_id' => 'extras', 'choices' => ['cheese', 'olives']],
                ['group_id' => 'xl_sauce', 'choices' => ['bbq']],
            ],
        ]],
    ]);
    $optionOrderData = $optionOrder->get_data();
    $assert($optionOrder->get_status() === 201 && abs((float) ($optionOrderData['total'] ?? 0) - 39) < 0.001,
        'le flux commande applique promotion, groupes, suppléments conditionnels et frais côté serveur');
    $optionOrderId = (int) ($optionOrderData['commande'] ?? 0);
    $created['orders'][] = $optionOrderId;
    $optionOrderLines = (array) get_post_meta($optionOrderId, 'tuc_items', true);
    $assert(abs((float) ($optionOrderLines[0]['pu'] ?? 0) - 34) < 0.001
        && count((array) ($optionOrderLines[0]['options'] ?? [])) === 3,
        'la commande conserve le prix final et le détail canonique des groupes sélectionnés');
    $invalidConditional = $rest('/tuc/v1/commande', [
        'resto' => $slug, 'table' => 0, 'tel' => '+212600000032', 'total' => 24,
        'lat' => 33.589, 'lng' => -7.621, 'accuracy' => 75, 'captured_at' => time(),
        'cle' => 'options-condition-' . $suffix,
        'items' => [['id' => (int) $optionItemId, 'q' => 1, 'options' => [
            ['group_id' => 'format', 'choices' => ['regular']],
            ['group_id' => 'xl_sauce', 'choices' => ['bbq']],
        ]]],
    ]);
    $assert($invalidConditional->get_status() === 422, 'la commande refuse un choix conditionnel non visible');

    update_post_meta($restaurantId, 'tuc_modes', ['livraison', 'table']);
    update_post_meta($restaurantId, 'tuc_tables', 2);
    $tableOrder = $validOrder;
    unset($tableOrder['lat'], $tableOrder['lng'], $tableOrder['accuracy'], $tableOrder['captured_at']);
    $tableOrder['table'] = 2;
    $tableOrder['mode'] = 'table';
    $tableOrder['tel'] = '+212600000033';
    $tableOrder['total'] = 26;
    $tableOrder['cle'] = 'table-order-' . $suffix;
    $ratePhones[] = $tableOrder['tel'];
    $tableOrderResponse = $rest('/tuc/v1/commande', $tableOrder);
    $tableOrderData = $tableOrderResponse->get_data();
    $assert($tableOrderResponse->get_status() === 201 && abs((float) ($tableOrderData['total'] ?? 0) - 26) < 0.001,
        'une commande à table valide ne facture pas les frais de livraison');
    $tableOrderId = (int) ($tableOrderData['commande'] ?? 0);
    $created['orders'][] = $tableOrderId;
    $assert(get_post_meta($tableOrderId, 'tuc_mode', true) === 'table'
        && (int) get_post_meta($tableOrderId, 'tuc_table', true) === 2,
        'le mode et le numéro de table sont enregistrés sur la commande');
    $badTableOrder = $tableOrder;
    $badTableOrder['table'] = 3;
    $badTableOrder['tel'] = '+212600000034';
    $badTableOrder['cle'] = 'invalid-table-' . $suffix;
    $assert($rest('/tuc/v1/commande', $badTableOrder)->get_status() === 422, 'un numéro de table supérieur au nombre configuré est refusé');
    update_post_meta($restaurantId, 'tuc_modes', ['livraison']);
    update_post_meta($restaurantId, 'tuc_tables', 0);

    // Un total client falsifié est refusé avec le total serveur.
    $tampered = $validOrder;
    $tampered['tel'] = '+212600000002';
    $tampered['total'] = 1;
    $tampered['cle'] = 'tampered-' . $suffix;
    $tamperedResponse = $rest('/tuc/v1/commande', $tampered);
    $tamperedData = $tamperedResponse->get_data();
    $assert($tamperedResponse->get_status() === 409
        && abs((float) ($tamperedData['data']['total_serveur'] ?? 0) - 31.0) < 0.001, 'un total client falsifié est refusé avec le total correct');

    // Report depuis /wa-panier : un mode absent de la configuration est refusé.
    // tuc_modes vient d'être restreint à ['livraison'] juste au-dessus.
    $modeRefuse = $validOrder;
    $modeRefuse['mode'] = 'emporter';
    $modeRefuse['tel'] = '+212600000002';
    $modeRefuse['cle'] = 'mode-refuse-' . $suffix;
    $assert($rest('/tuc/v1/commande', $modeRefuse)->get_status() === 422,
        'la commande refuse un mode absent de la configuration du commerce');

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
        && ($duplicateData['doublon'] ?? false) === true
        && ($duplicateData['suivi'] ?? '') === $trackingToken,
        'la clé d’idempotence empêche les doublons et restitue le même lien de suivi sans rotation');
    $assert($rest('/tuc/v1/commande/suivi', ['jeton' => $trackingToken])->get_status() === 200,
        'le lien de suivi reste valide après une nouvelle tentative idempotente');

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

    update_post_meta($otherRestaurantId, 'tuc_ferme', '0');
    update_post_meta($otherRestaurantId, 'tuc_frais', 0);
    update_post_meta($otherRestaurantId, 'tuc_minimum', 0);
    $otherItemId = wp_insert_post([
        'post_type'   => 'tuc_menu_item',
        'post_status' => 'publish',
        'post_title'  => 'Plat deuxième commerce ' . $suffix,
    ], true);
    $assert(!is_wp_error($otherItemId), 'fixture plat du deuxième commerce créée');
    $created['items'][] = (int) $otherItemId;
    update_post_meta($otherItemId, 'tuc_resto_id', (int) $otherRestaurantId);
    update_post_meta($otherItemId, 'tuc_prix', 7);
    update_post_meta($otherItemId, 'tuc_dispo', '1');
    $otherOrderPhone = '+212600000020';
    $ratePhones[] = $otherOrderPhone;
    $otherOrderResponse = $rest('/tuc/v1/commande', [
        'resto' => (string) get_post($otherRestaurantId)->post_name,
        'table' => 0,
        'mode'  => 'emporter',
        'tel'   => $otherOrderPhone,
        'total' => 7,
        'cle'   => 'second-restaurant-' . $suffix,
        'items' => [['id' => (int) $otherItemId, 'q' => 1]],
    ]);
    $assert($otherOrderResponse->get_status() === 201, 'une commande de test distincte est créée pour le deuxième commerce');
    $otherOrderId = (int) ($otherOrderResponse->get_data()['commande'] ?? 0);
    $created['orders'][] = $otherOrderId;

    $ownerUserId = wp_insert_user([
        'user_login' => 'tuc-owner-' . substr($suffix, 0, 8),
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => 'tuc-owner-' . substr($suffix, 0, 8) . '@example.invalid',
        'role' => 'tuc_restaurateur',
    ]);
    $managerUserId = wp_insert_user([
        'user_login' => 'tuc-manager-' . substr($suffix, 0, 8),
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => 'tuc-manager-' . substr($suffix, 0, 8) . '@example.invalid',
        'role' => 'tuc_gestionnaire',
    ]);
    $cashierUserId = wp_insert_user([
        'user_login' => 'tuc-cashier-' . substr($suffix, 0, 8),
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => 'tuc-cashier-' . substr($suffix, 0, 8) . '@example.invalid',
        'role' => 'tuc_caissier',
    ]);
    $serverUserId = wp_insert_user([
        'user_login' => 'tuc-server-' . substr($suffix, 0, 8),
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => 'tuc-server-' . substr($suffix, 0, 8) . '@example.invalid',
        'role' => 'tuc_serveur',
    ]);
    $unassignedUserId = wp_insert_user([
        'user_login' => 'tuc-unassigned-' . substr($suffix, 0, 8),
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => 'tuc-unassigned-' . substr($suffix, 0, 8) . '@example.invalid',
        'role' => 'tuc_caissier',
    ]);
    foreach ([$ownerUserId, $managerUserId, $cashierUserId, $serverUserId, $unassignedUserId] as $roleUserId) {
        $assert(!is_wp_error($roleUserId), 'fixture utilisateur métier créée');
        $created['users'][] = (int) $roleUserId;
    }
    update_user_meta((int) $ownerUserId, 'tuc_resto_id', (int) $restaurantId);
    update_user_meta((int) $managerUserId, 'tuc_resto_id', (int) $otherRestaurantId);
    update_user_meta((int) $cashierUserId, 'tuc_resto_id', (int) $restaurantId);
    update_user_meta((int) $serverUserId, 'tuc_resto_id', (int) $restaurantId);
    $serverAlert = TUC_Caisse::profil_alerte((int) $serverUserId, 'table', (int) $restaurantId);
    $cashierAlert = TUC_Caisse::profil_alerte((int) $cashierUserId, 'table', (int) $restaurantId);
    $managerAlert = TUC_Caisse::profil_alerte((int) $managerUserId, 'table', (int) $otherRestaurantId);
    $serverOtherTenantAlert = TUC_Caisse::profil_alerte((int) $serverUserId, 'table', (int) $otherRestaurantId);
    $serverDeliveryAlert = TUC_Caisse::profil_alerte((int) $serverUserId, 'livraison', (int) $restaurantId);
    $ownerTakeawayAlert = TUC_Caisse::profil_alerte((int) $ownerUserId, 'emporter', (int) $restaurantId);
    $cashierDeliveryAlert = TUC_Caisse::profil_alerte((int) $cashierUserId, 'livraison', (int) $restaurantId);
    $unassignedAlert = TUC_Caisse::profil_alerte((int) $unassignedUserId, 'table', (int) $restaurantId);
    $zeroRestaurantAlert = TUC_Caisse::profil_alerte((int) $ownerUserId, 'table', 0);
    // Décision propriétaire : le patron et la cuisine entendent toute commande.
    // Ce qui reste interdit, c'est l'inter-tenant et le compte non rattaché.
    $assert($serverAlert['audible'] && !$serverAlert['silent'] && $serverAlert['vibrate'] !== []
        && $cashierAlert['audible'] && !$cashierAlert['silent'] && $cashierAlert['vibrate'] !== []
        && $managerAlert['audible'] && $ownerTakeawayAlert['audible']
        && $serverDeliveryAlert['audible'] && $cashierDeliveryAlert['audible'],
        'bip/vibreur pour tous les rôles caisse rattachés, quel que soit le mode (table, emporter, livraison)');
    $assert(!$serverOtherTenantAlert['audible'] && $serverOtherTenantAlert['silent']
        && $serverOtherTenantAlert['vibrate'] === []
        && !$unassignedAlert['audible'] && $unassignedAlert['vibrate'] === []
        && !$zeroRestaurantAlert['audible'],
        'alerte sonore refusée hors du restaurant, sans affectation, ou sans restaurant identifié');
    $assert(TUC_Acces::peut(TUC_Roles::CAP_MANAGE_MENU, (int) $restaurantId, (int) $ownerUserId)
        && TUC_Acces::peut(TUC_Roles::CAP_MANAGE_MENU, (int) $otherRestaurantId, (int) $managerUserId)
        && !TUC_Acces::peut(TUC_Roles::CAP_MANAGE_MENU, (int) $restaurantId, (int) $managerUserId)
        && !TUC_Acces::peut(TUC_Roles::CAP_MANAGE_MENU, (int) $restaurantId, (int) $unassignedUserId)
        && !TUC_Acces::peut(TUC_Roles::CAP_MANAGE_MENU, 0, (int) $ownerUserId),
        'TUC_Acces::peut applique la capacité et interdit les accès inter-tenant ou sans affectation');
    $validAssignment = TUC_Espace_Pro::valider_affectation((int) $ownerUserId, 'tuc_gestionnaire', (int) $restaurantId);
    $assert(!is_wp_error($validAssignment) && $validAssignment['restaurant_id'] === (int) $restaurantId,
        'l’affectation exige un rôle TUC et un restaurant publié');
    $assert(is_wp_error(TUC_Espace_Pro::valider_affectation((int) $ownerUserId, 'administrator', (int) $restaurantId))
        && is_wp_error(TUC_Espace_Pro::valider_affectation((int) $ownerUserId, 'tuc_gestionnaire', 99999999)),
        'l’interface ne peut pas attribuer un rôle WordPress privilégié ou un restaurant inexistant');

    wp_set_current_user((int) $ownerUserId);
    $ownerOrders = TUC_Caisse::commandes(new WP_REST_Request('GET', '/tuc/v1/caisse/commandes'))->get_data()['commandes'] ?? [];
    $ownerOrderIds = array_map(static fn(array $order): int => (int) $order['id'], $ownerOrders);
    $matchingOwnerOrders = array_values(array_filter($ownerOrders, static fn(array $order): bool => (int) $order['id'] === (int) $orderId));
    $ownerOrder = $matchingOwnerOrders[0] ?? null;
    $assert(is_array($ownerOrder) && $ownerOrder['tel'] === '212600000001'
        && abs((float) $ownerOrder['lat'] - 33.589) < 0.000001
        && abs((float) $ownerOrder['lng'] + 7.621) < 0.000001
        && str_contains((string) $ownerOrder['location_url'], '33.589'),
        'la caisse du restaurant reçoit le téléphone, les coordonnées et le lien cartographique de livraison');
    $assert(TUC_Caisse::peut_acceder() && TUC_Caisse::mon_resto()->ID === (int) $restaurantId
        && in_array((int) $orderId, $ownerOrderIds, true)
        && !in_array($otherOrderId, $ownerOrderIds, true), 'le restaurateur ne voit que les commandes de son restaurant');
    $assert(TUC_Espace_Pro::permission_menu(), 'le restaurateur affecté peut gérer le menu de son restaurant');
    $assert(TUC_Espace_Pro::redirect_apres_connexion('/wp-admin/', '', get_userdata((int) $ownerUserId)) === home_url('/espace-pro/'),
        'la connexion d’un gestionnaire de menu affecté mène à l’espace mobile');
    $assert(TUC_Espace_Pro::redirect_apres_connexion('/wp-admin/', '', get_userdata((int) $serverUserId)) === home_url('/caisse/')
        && TUC_Espace_Pro::redirect_apres_connexion('/wp-admin/', '', get_userdata((int) $cashierUserId)) === home_url('/caisse/'),
        'serveur et caissier sont redirigés directement vers la caisse de leur restaurant');
    $proOrderData = TUC_Espace_Pro::commandes_pro(new WP_REST_Request('GET', '/tuc/v1/pro/commandes'))->get_data();
    $proOrderIds = array_map(static fn(array $order): int => (int) $order['id'], $proOrderData['orders'] ?? []);
    $assert(in_array($orderId, $proOrderIds, true) && !in_array($otherOrderId, $proOrderIds, true)
        && !array_key_exists('tel', ($proOrderData['orders'][0] ?? [])),
        'le tableau mobile liste les commandes du seul tenant sans exposer le téléphone client');
    $storeBefore = [
        'minimum' => get_post_meta($restaurantId, 'tuc_minimum', true),
        'fee' => get_post_meta($restaurantId, 'tuc_frais', true),
        'modes' => get_post_meta($restaurantId, 'tuc_modes', true),
        'tables' => get_post_meta($restaurantId, 'tuc_tables', true),
        'closed' => get_post_meta($restaurantId, 'tuc_ferme', true),
    ];
    $invalidSettingsRequest = new WP_REST_Request('PATCH', '/tuc/v1/pro/restaurant');
    $invalidSettingsRequest->set_header('content-type', 'application/json');
    $invalidSettingsRequest->set_body(wp_json_encode(['modes' => ['table'], 'tables' => 0]));
    $assert(is_wp_error(TUC_Espace_Pro::modifier_restaurant($invalidSettingsRequest)),
        'les réglages refusent le mode à table sans table configurée');
    $settingsRequest = new WP_REST_Request('PATCH', '/tuc/v1/pro/restaurant');
    $settingsRequest->set_header('content-type', 'application/json');
    $settingsRequest->set_body(wp_json_encode(['open' => false, 'minimum' => 25, 'fee' => 7, 'modes' => ['emporter', 'table'], 'tables' => 4]));
    $settingsResult = TUC_Espace_Pro::modifier_restaurant($settingsRequest);
    $settingsData = $settingsResult instanceof WP_REST_Response ? $settingsResult->get_data() : [];
    $assert($settingsResult instanceof WP_REST_Response && $settingsData['open'] === false
        && (float) $settingsData['minimum'] === 25.0 && (float) $settingsData['fee'] === 7.0
        && in_array('table', $settingsData['modes'], true) && (int) $settingsData['tables'] === 4,
        'le mobile peut enregistrer disponibilité, minimum, frais, modes et nombre de tables');
    $locationMetaKeys = ['tuc_lat', 'tuc_lng', 'tuc_coord_source', 'tuc_coord_accuracy', 'tuc_coord_updated_at'];
    $locationBefore = [];
    foreach ($locationMetaKeys as $metaKey) {
        $locationBefore[$metaKey] = get_post_meta($restaurantId, $metaKey, true);
    }
    $invalidLocationRequest = new WP_REST_Request('POST', '/tuc/v1/pro/restaurant/location');
    $invalidLocationRequest->set_header('content-type', 'application/json');
    $invalidLocationRequest->set_body(wp_json_encode(['lat' => 33.5900, 'lng' => -7.6280, 'source' => 'gps', 'accuracy' => 101, 'capturedAt' => time()]));
    $assert(is_wp_error(TUC_Espace_Pro::enregistrer_localisation_restaurant($invalidLocationRequest)),
        'l’API refuse une position GPS dont la précision dépasse 100 mètres');
    $gpsLocationRequest = new WP_REST_Request('POST', '/tuc/v1/pro/restaurant/location');
    $gpsLocationRequest->set_header('content-type', 'application/json');
    $gpsLocationRequest->set_body(wp_json_encode(['lat' => 33.5900, 'lng' => -7.6280, 'source' => 'gps', 'accuracy' => 25, 'capturedAt' => time()]));
    $gpsLocationResult = TUC_Espace_Pro::enregistrer_localisation_restaurant($gpsLocationRequest);
    $gpsLocation = $gpsLocationResult instanceof WP_REST_Response ? $gpsLocationResult->get_data()['location'] : null;
    $assert(is_array($gpsLocation) && $gpsLocation['source'] === 'gps' && (float) $gpsLocation['accuracy'] === 25.0
        && (float) get_post_meta($restaurantId, 'tuc_lat', true) === 33.59,
        'l’API du tableau de bord enregistre et retourne les coordonnées GPS valides');
    $unconfirmedMapRequest = new WP_REST_Request('POST', '/tuc/v1/pro/restaurant/location');
    $unconfirmedMapRequest->set_header('content-type', 'application/json');
    $unconfirmedMapRequest->set_body(wp_json_encode(['lat' => 33.59123, 'lng' => -7.62789, 'source' => 'google_maps_link']));
    $assert(is_wp_error(TUC_Espace_Pro::enregistrer_localisation_restaurant($unconfirmedMapRequest))
        && (float) get_post_meta($restaurantId, 'tuc_lat', true) === 33.59,
        'l’API refuse un repère Maps tant que le restaurateur ne le confirme pas');
    $outsideAreaRequest = new WP_REST_Request('POST', '/tuc/v1/pro/restaurant/location');
    $outsideAreaRequest->set_header('content-type', 'application/json');
    $outsideAreaRequest->set_body(wp_json_encode(['lat' => 0, 'lng' => 0, 'source' => 'google_maps_link', 'confirmed' => true]));
    $outsideAreaResult = TUC_Espace_Pro::enregistrer_localisation_restaurant($outsideAreaRequest);
    $assert(is_wp_error($outsideAreaResult) && (int) ($outsideAreaResult->get_error_data()['status'] ?? 0) === 422
        && (float) get_post_meta($restaurantId, 'tuc_lat', true) === 33.59,
        'l’API refuse les coordonnées hors zone sans écraser la position enregistrée');
    $mapLocationRequest = new WP_REST_Request('POST', '/tuc/v1/pro/restaurant/location');
    $mapLocationRequest->set_header('content-type', 'application/json');
    $mapLocationRequest->set_body(wp_json_encode(['lat' => 33.59123, 'lng' => -7.62789, 'source' => 'google_maps_link', 'confirmed' => true]));
    $mapLocationResult = TUC_Espace_Pro::enregistrer_localisation_restaurant($mapLocationRequest);
    $mapLocation = $mapLocationResult instanceof WP_REST_Response ? $mapLocationResult->get_data()['location'] : null;
    $assert(is_array($mapLocation) && $mapLocation['source'] === 'google_maps_link' && $mapLocation['accuracy'] === null
        && !array_key_exists('url', $mapLocation) && get_post_meta($restaurantId, 'tuc_coord_link', true) === ''
        && (float) get_post_meta($restaurantId, 'tuc_lng', true) === -7.62789,
        'l’API enregistre les coordonnées confirmées du lien Maps sans conserver le lien ni inventer une précision GPS');
    $unconfirmedCoordinatesRequest = new WP_REST_Request('POST', '/tuc/v1/pro/restaurant/location');
    $unconfirmedCoordinatesRequest->set_header('content-type', 'application/json');
    $unconfirmedCoordinatesRequest->set_body(wp_json_encode(['lat' => 33.59234, 'lng' => -7.62891, 'source' => 'manual_coordinates']));
    $assert(is_wp_error(TUC_Espace_Pro::enregistrer_localisation_restaurant($unconfirmedCoordinatesRequest))
        && (float) get_post_meta($restaurantId, 'tuc_lat', true) === 33.59123,
        'l’API exige la confirmation explicite de coordonnées saisies');
    $manualCoordinatesRequest = new WP_REST_Request('POST', '/tuc/v1/pro/restaurant/location');
    $manualCoordinatesRequest->set_header('content-type', 'application/json');
    $manualCoordinatesRequest->set_body(wp_json_encode(['lat' => 33.59234, 'lng' => -7.62891, 'source' => 'manual_coordinates', 'confirmed' => true]));
    $manualCoordinatesResult = TUC_Espace_Pro::enregistrer_localisation_restaurant($manualCoordinatesRequest);
    $manualCoordinates = $manualCoordinatesResult instanceof WP_REST_Response ? $manualCoordinatesResult->get_data()['location'] : null;
    $assert(is_array($manualCoordinates) && $manualCoordinates['source'] === 'manual_coordinates' && $manualCoordinates['accuracy'] === null
        && (float) get_post_meta($restaurantId, 'tuc_lat', true) === 33.59234,
        'l’API accepte les coordonnées saisies et ne leur attribue pas une précision GPS fictive');
    foreach ($locationBefore as $metaKey => $metaValue) {
        if ($metaValue === '') {
            delete_post_meta($restaurantId, $metaKey);
        } else {
            update_post_meta($restaurantId, $metaKey, $metaValue);
        }
    }
    update_post_meta($restaurantId, 'tuc_minimum', $storeBefore['minimum']);
    update_post_meta($restaurantId, 'tuc_frais', $storeBefore['fee']);
    update_post_meta($restaurantId, 'tuc_modes', $storeBefore['modes']);
    update_post_meta($restaurantId, 'tuc_tables', $storeBefore['tables']);
    update_post_meta($restaurantId, 'tuc_ferme', $storeBefore['closed']);
    $proPayload = [
        'title' => 'Plat créé via Espace Pro ' . $suffix,
        'description' => 'Description de contrôle',
        'price' => 18.5,
        'promo_pct' => 15,
        'available' => true,
        'options' => $optionDefinition,
        'status' => 'draft',
    ];
    $invalidProRequest = new WP_REST_Request('POST', '/tuc/v1/pro/menu');
    $invalidProPayload = $proPayload;
    $invalidProPayload['price'] = -1;
    $invalidProRequest->set_header('content-type', 'application/json');
    $invalidProRequest->set_body(wp_json_encode($invalidProPayload));
    $invalidProResult = TUC_Espace_Pro::creer_plat($invalidProRequest);
    $assert(is_wp_error($invalidProResult) && (int) ($invalidProResult->get_error_data()['status'] ?? 0) === 422,
        'l’API Espace Pro rejette un prix négatif avant insertion');
    $proCreateRequest = new WP_REST_Request('POST', '/tuc/v1/pro/menu');
    $proCreateRequest->set_header('content-type', 'application/json');
    $proCreateRequest->set_body(wp_json_encode($proPayload));
    $proCreateResult = TUC_Espace_Pro::creer_plat($proCreateRequest);
    $assert($proCreateResult instanceof WP_REST_Response && $proCreateResult->get_status() === 201,
        'l’API Espace Pro crée un plat après validation serveur');
    $proItemId = (int) ($proCreateResult->get_data()['id'] ?? 0);
    $created['items'][] = $proItemId;
    $assert($proItemId > 0 && get_post_status($proItemId) === 'draft'
        && (int) get_post_meta($proItemId, 'tuc_resto_id', true) === (int) $restaurantId
        && (int) get_post_meta($proItemId, 'tuc_promo_pct', true) === 15,
        'le plat est affecté au bon restaurant et reste brouillon avant publication');
    $ownerMenuList = TUC_Espace_Pro::liste_menu()->get_data();
    $ownerMenuIds = array_map(static fn(array $item): int => (int) $item['id'], $ownerMenuList);
    $assert(in_array($proItemId, $ownerMenuIds, true) && !in_array((int) $otherItemId, $ownerMenuIds, true),
        'la liste Espace Pro inclut ses brouillons mais reste isolée par restaurant');
    $proPayload['status'] = 'publish';
    $proUpdateRequest = new WP_REST_Request('PUT', '/tuc/v1/pro/menu/' . $proItemId);
    $proUpdateRequest->set_param('id', $proItemId);
    $proUpdateRequest->set_header('content-type', 'application/json');
    $proUpdateRequest->set_body(wp_json_encode($proPayload));
    $proUpdateResult = TUC_Espace_Pro::modifier_plat($proUpdateRequest);
    $assert($proUpdateResult instanceof WP_REST_Response && get_post_status($proItemId) === 'publish',
        'le gestionnaire peut publier son plat après aperçu et validation');
    $photoRequest = new WP_REST_Request('POST', '/tuc/v1/pro/menu/' . $proItemId . '/photo');
    $photoRequest->set_param('id', $proItemId);
    $photoResult = TUC_Espace_Pro::televerser_photo($photoRequest);
    $assert(is_wp_error($photoResult) && (int) ($photoResult->get_error_data()['status'] ?? 0) === 422,
        'le téléversement média refuse un fichier absent ou invalide');
    $foreignPhotoRequest = new WP_REST_Request('POST', '/tuc/v1/pro/menu/' . $otherItemId . '/photo');
    $foreignPhotoRequest->set_param('id', $otherItemId);
    $foreignPhotoResult = TUC_Espace_Pro::televerser_photo($foreignPhotoRequest);
    $assert(is_wp_error($foreignPhotoResult) && (int) ($foreignPhotoResult->get_error_data()['status'] ?? 0) === 404,
        'le téléversement média refuse un plat appartenant à un autre restaurant');
    $publicMenuRequest = new WP_REST_Request('GET', '/tuc/v1/menu/' . $slug);
    $publicMenuRequest->set_param('slug', $slug);
    $publicMenuResult = TUC_Espace_Pro::menu_public($publicMenuRequest);
    $publicMenuIds = array_map(static fn(array $item): int => (int) $item['id'], $publicMenuResult->get_data()['items'] ?? []);
    $assert(in_array($proItemId, $publicMenuIds, true) && !in_array((int) $otherItemId, $publicMenuIds, true),
        'le menu public renvoie les plats publiés sans exposer le menu d’un autre restaurant');
    $foreignMenuRequest = new WP_REST_Request('PUT', '/tuc/v1/pro/menu/' . $otherItemId);
    $foreignMenuRequest->set_param('id', $otherItemId);
    $foreignMenuRequest->set_header('content-type', 'application/json');
    $foreignMenuRequest->set_body(wp_json_encode($proPayload));
    $assert(is_wp_error(TUC_Espace_Pro::modifier_plat($foreignMenuRequest)),
        'le restaurateur ne peut pas modifier un plat appartenant à un autre commerce');

    $ownStatusRequest = new WP_REST_Request('POST', '/tuc/v1/commande/' . $orderId . '/statut');
    $ownStatusRequest->set_param('id', (int) $orderId);
    $ownStatusRequest->set_param('statut', 'preparation');
    $assert(call_user_func($legacyStatusRoute['permission_callback'], $ownStatusRequest) === true,
        'la capacité commande autorise le statut dans le restaurant assigné');
    $ownRoleStatus = TUC_Espace_Pro::changer_statut_pro($ownStatusRequest);
    $assert($ownRoleStatus instanceof WP_REST_Response && $ownRoleStatus->get_status() === 200,
        'le restaurateur peut mettre à jour une commande de son restaurant');
    $updatedTracking = $rest('/tuc/v1/commande/suivi', ['jeton' => $trackingToken]);
    $assert($updatedTracking->get_status() === 200 && ($updatedTracking->get_data()['status'] ?? '') === 'preparation',
        'le suivi client reflète les changements de statut opérés depuis l’espace restaurateur');
    update_post_meta($orderId, 'tuc_statut', 'nouvelle');

    wp_set_current_user((int) $managerUserId);
    $assert(TUC_Espace_Pro::permission_menu(), 'le gestionnaire affecté peut gérer le menu de son propre restaurant');
    $otherLocationBefore = [];
    foreach ($locationMetaKeys as $metaKey) {
        $otherLocationBefore[$metaKey] = get_post_meta($otherRestaurantId, $metaKey, true);
    }
    $ownerLatitudeBeforeManagerSave = get_post_meta($restaurantId, 'tuc_lat', true);
    $managerLocationRequest = new WP_REST_Request('POST', '/tuc/v1/pro/restaurant/location');
    $managerLocationRequest->set_header('content-type', 'application/json');
    $managerLocationRequest->set_body(wp_json_encode(['lat' => 33.5900, 'lng' => -7.6280, 'source' => 'gps', 'accuracy' => 30, 'capturedAt' => time()]));
    $managerLocationResult = TUC_Espace_Pro::enregistrer_localisation_restaurant($managerLocationRequest);
    $managerLocationData = $managerLocationResult instanceof WP_REST_Response ? $managerLocationResult->get_data() : [];
    $assert(call_user_func($proLocationRoute['permission_callback']) === true
        && (int) ($managerLocationData['id'] ?? 0) === (int) $otherRestaurantId
        && (float) get_post_meta($otherRestaurantId, 'tuc_lat', true) === 33.59
        && get_post_meta($restaurantId, 'tuc_lat', true) === $ownerLatitudeBeforeManagerSave,
        'la route de localisation n’écrit que sur le restaurant assigné au gestionnaire connecté');
    foreach ($otherLocationBefore as $metaKey => $metaValue) {
        if ($metaValue === '') {
            delete_post_meta($otherRestaurantId, $metaKey);
        } else {
            update_post_meta($otherRestaurantId, $metaKey, $metaValue);
        }
    }
    $managerForeignMenu = new WP_REST_Request('PUT', '/tuc/v1/pro/menu/' . $proItemId);
    $managerForeignMenu->set_param('id', $proItemId);
    $managerForeignMenu->set_header('content-type', 'application/json');
    $managerForeignMenu->set_body(wp_json_encode($proPayload));
    $assert(is_wp_error(TUC_Espace_Pro::modifier_plat($managerForeignMenu)),
        'le gestionnaire d’un restaurant voisin ne peut pas modifier le plat d’un autre tenant');
    $managerOrders = TUC_Caisse::commandes(new WP_REST_Request('GET', '/tuc/v1/caisse/commandes'))->get_data()['commandes'] ?? [];
    $managerOrderIds = array_map(static fn(array $order): int => (int) $order['id'], $managerOrders);
    $assert(TUC_Caisse::mon_resto()->ID === (int) $otherRestaurantId
        && in_array($otherOrderId, $managerOrderIds, true)
        && !in_array((int) $orderId, $managerOrderIds, true), 'le gestionnaire ne voit que les commandes de son restaurant');
    $managerProOrders = TUC_Espace_Pro::commandes_pro(new WP_REST_Request('GET', '/tuc/v1/pro/commandes'))->get_data()['orders'] ?? [];
    $managerProOrderIds = array_map(static fn(array $order): int => (int) $order['id'], $managerProOrders);
    $assert(in_array($otherOrderId, $managerProOrderIds, true) && !in_array((int) $orderId, $managerProOrderIds, true),
        'l’API de l’espace mobile conserve l’isolation tenant du gestionnaire');
    $foreignStatusRequest = new WP_REST_Request('POST', '/tuc/v1/commande/' . $orderId . '/statut');
    $foreignStatusRequest->set_param('id', (int) $orderId);
    $foreignStatusRequest->set_param('statut', 'preparation');
    $foreignStatusResult = TUC_Commande::changer_statut($foreignStatusRequest);
    $proForeignStatusResult = TUC_Espace_Pro::changer_statut_pro($foreignStatusRequest);
    $assert(is_wp_error($proForeignStatusResult)
        && (int) ($proForeignStatusResult->get_error_data()['status'] ?? 0) === 403,
        'la route de statut mobile refuse une commande appartenant à un autre tenant');
    $assert(is_wp_error($foreignStatusResult)
        && (int) ($foreignStatusResult->get_error_data()['status'] ?? 0) === 403
        && get_post_meta($orderId, 'tuc_statut', true) === 'nouvelle', 'un gestionnaire ne peut pas modifier la commande du restaurant voisin');
    $assert(call_user_func($legacyStatusRoute['permission_callback'], $foreignStatusRequest) === false,
        'la permission REST refuse aussi la commande d’un autre restaurant');
    $foreignCashierStatus = TUC_Caisse::statut($foreignStatusRequest);
    $assert($foreignCashierStatus->get_status() === 403
        && get_post_meta($orderId, 'tuc_statut', true) === 'nouvelle', 'la route de caisse refuse elle aussi une commande hors tenant');

    wp_set_current_user((int) $serverUserId);
    $serverOrders = TUC_Caisse::commandes(new WP_REST_Request('GET', '/tuc/v1/caisse/commandes'))->get_data()['commandes'] ?? [];
    $serverOrderIds = array_map(static fn(array $order): int => (int) $order['id'], $serverOrders);
    $serverMe = TUC_Caisse::moi()->get_data();
    $serverStatusRequest = new WP_REST_Request('POST', '/tuc/v1/caisse/statut');
    $serverStatusRequest->set_param('id', (int) $orderId);
    $serverStatusRequest->set_param('statut', 'acceptee');
    $serverStatusResult = TUC_Caisse::statut($serverStatusRequest);
    $assert(TUC_Caisse::peut_acceder() && TUC_Caisse::peut_voir_commandes() && TUC_Caisse::peut_modifier_commandes()
        && TUC_Caisse::mon_resto()->ID === (int) $restaurantId
        && in_array((int) $orderId, $serverOrderIds, true)
        && !in_array((int) $otherOrderId, $serverOrderIds, true)
        && $serverStatusResult->get_status() === 200
        && get_post_meta($orderId, 'tuc_statut', true) === 'acceptee'
        && ($serverMe['alerte_sonore'] ?? false) === true,
        'le serveur accède aux commandes de son store, suit leur statut et reçoit le profil sonore');
    update_post_meta($orderId, 'tuc_statut', 'nouvelle');

    wp_set_current_user((int) $cashierUserId);
    $cashierMe = TUC_Caisse::moi()->get_data();
    $assert(($cashierMe['alerte_sonore'] ?? false) === true,
        'le caissier rattaché au restaurant reçoit le profil sonore (décision propriétaire : la caisse entend)');
    $assert(!TUC_Espace_Pro::permission_menu(), 'le caissier ne peut pas gérer le menu via les routes Espace Pro');
    $assert(TUC_Caisse::peut_acceder() && TUC_Caisse::peut_modifier_commandes()
        && !TUC_Caisse::peut_gerer_restaurant()
        && call_user_func($openingRoute['permission_callback']) === false
        && !TUC_Espace_Pro::permission_restaurant()
        && call_user_func($proLocationRoute['permission_callback']) === false
        && TUC_Espace_Pro::permission_commandes(), 'le caissier peut traiter les commandes mais ne peut pas modifier les paramètres du commerce ni les coordonnées');
    wp_set_current_user((int) $unassignedUserId);
    $assert(!TUC_Espace_Pro::permission_menu() && !TUC_Espace_Pro::permission_restaurant()
        && call_user_func($proLocationRoute['permission_callback']) === false,
        'un compte non affecté ne peut ni gérer le menu ni enregistrer une localisation');
    $assert(TUC_Caisse::mon_resto() === null
        && TUC_Caisse::commandes(new WP_REST_Request('GET', '/tuc/v1/caisse/commandes'))->get_status() === 403,
        'un compte métier sans affectation explicite n’obtient aucun restaurant par défaut');
    wp_set_current_user(0);

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

    // L'aperçu WhatsApp du panier a été retiré : les commandes vont au tableau de
    // bord du restaurant, pas à un numéro de téléphone (décision propriétaire).
    // Les propriétés de sécurité qu'il testait sont reportées sur /commande, seul
    // chemin de commande réel. Cette garde empêche une réintroduction silencieuse.
    $routesRest = rest_get_server()->get_routes();
    $assert(!isset($routesRest['/tuc/v1/wa-panier']),
        'la route /wa-panier reste supprimée : aucune commande ne part vers un numéro de téléphone');
    $assert(isset($routesRest['/tuc/v1/commande']) && isset($routesRest['/tuc/v1/wa-lien']),
        'le chemin de commande réel et la vérification WhatsApp restent enregistrés');

    // ------------------------------------------------------------------
    // Catalogue public : lu en base, cliquable, démonstration signalée.
    // Le thème servait 25 restaurants codés en dur, sans article WordPress
    // donc sans URL : cartes non cliquables et aucune boutique consultable.
    // ------------------------------------------------------------------
    if (function_exists('tuc_restos') && function_exists('tuc_promos')) {
        update_post_meta((int) $restaurantId, 'tuc_kind', 'snack');
        update_post_meta((int) $restaurantId, 'tuc_demo', '1');
        update_post_meta((int) $restaurantId, 'tuc_img', 'tacos');
        update_post_meta((int) $otherRestaurantId, 'tuc_kind', 'restaurant');
        update_post_meta((int) $otherRestaurantId, 'tuc_demo', '0');
        update_post_meta((int) $itemId, 'tuc_promo_pct', 25);

        $catalogue = tuc_restos();
        $parId = [];
        foreach ($catalogue as $entree) {
            $parId[(int) $entree['id']] = $entree;
        }
        $fictif = $parId[(int) $restaurantId] ?? null;
        $reel = $parId[(int) $otherRestaurantId] ?? null;

        $assert(is_array($fictif) && is_array($reel),
            'le catalogue public vient des articles tuc_resto, pas d\'un tableau codé en dur');
        $assert(($fictif['url'] ?? '') !== '' && ($reel['url'] ?? '') !== ''
            && str_starts_with((string) $fictif['url'], 'http'),
            'chaque restaurant du catalogue porte une URL : la carte est cliquable');
        $assert(($fictif['demo'] ?? false) === true && ($reel['demo'] ?? true) === false,
            'le contenu de démonstration est distingué du contenu réel pour être badgé');
        $assert(($fictif['k'] ?? '') === 'snack' && ($reel['k'] ?? '') === 'restaurant',
            'le type de commerce alimente les sections de la page d\'accueil');
        $assert(($fictif['n'] ?? '') === $restaurant->post_title,
            'le nom affiché est celui de WordPress');

        // « Livraison offerte » ne doit pas aspirer un commerce qui ne livre pas.
        $sansLivraison = ['k' => 'snack', 'r' => 4.5, 't' => '10–20', 'fee' => null, 'livraison' => false];
        $livraisonOfferte = ['k' => 'snack', 'r' => 4.5, 't' => '10–20', 'fee' => null, 'livraison' => true];
        $sectionFree = tuc_pick([$sansLivraison, $livraisonOfferte], ['type' => 'free']);
        $assert(count($sectionFree) === 1 && $sectionFree[0]['livraison'] === true,
            'un commerce qui ne livre pas n\'apparaît pas dans « Livraison offerte »');

        $promos = tuc_promos();
        // Cibler CE plat par son titre : deux autres plats du même restaurant
        // portent déjà une remise (10 % et 15 %) et sont plus récents, donc
        // renvoyés avant lui par get_posts. Prendre le premier du restaurant
        // testait le mauvais plat.
        $maPromo = array_values(array_filter($promos, fn($x) => ($x['nm'] ?? '') === $itemTitle));
        $assert($maPromo !== [] && ($maPromo[0]['big'] ?? '') === '−25 %'
            && ($maPromo[0]['shop'] ?? '') === $restaurant->post_title,
            'une promotion vient de l\'article en remise et nomme sa boutique');
        $toutesAvecUrl = $promos !== [];
        foreach ($promos as $promo) {
            if (($promo['url'] ?? '') === '' || !str_starts_with((string) $promo['url'], 'http')) {
                $toutesAvecUrl = false;
            }
        }
        $assert($toutesAvecUrl, 'chaque promotion est cliquable vers sa boutique');
        update_post_meta((int) $itemId, 'tuc_promo_pct', 0);
    } else {
        $assert(false, 'le thème expose tuc_restos() et tuc_promos() : catalogue testable');
    }

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
    $assert(current_user_can('manage_options') && TUC_Caisse::peut_acceder(), 'l’administrateur conserve l’accès global à la caisse et à la route historique');
    $assert(call_user_func($cashierRoute['permission_callback']) === true
        && call_user_func($legacyStatusRoute['permission_callback']) === true, 'les permission_callback enregistrés autorisent un administrateur');
    $hadAssignedRestaurant = metadata_exists('user', (int) $admins[0], 'tuc_resto_id');
    $oldAssignedRestaurant = get_user_meta((int) $admins[0], 'tuc_resto_id', true);
    update_user_meta((int) $admins[0], 'tuc_resto_id', (int) $otherRestaurantId);
    $foreignStatusRequest = new WP_REST_Request('POST', '/tuc/v1/caisse/statut');
    $foreignStatusRequest->set_param('id', $orderId);
    $foreignStatusRequest->set_param('statut', 'preparation');
    $foreignCaisseStatus = TUC_Caisse::statut($foreignStatusRequest);
    $assert($foreignCaisseStatus->get_status() === 403
        && get_post_meta($orderId, 'tuc_statut', true) === 'nouvelle', 'la caisse refuse à un compte rattaché à un autre commerce de modifier la commande');
    if ($hadAssignedRestaurant) {
        update_user_meta((int) $admins[0], 'tuc_resto_id', $oldAssignedRestaurant);
    } else {
        delete_user_meta((int) $admins[0], 'tuc_resto_id');
    }
    $validStatus = $rest($statusPath, ['statut' => 'acceptee']);
    $assert($validStatus->get_status() === 200
        && get_post_meta($orderId, 'tuc_statut', true) === 'acceptee', 'un administrateur peut accepter la commande historique');
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

    // ── Montants saisis par un humain : la virgule ne doit plus multiplier un prix par 100.
    // Régression du 2026-11-12. Le champ prix était un input[type=number] : taper « 45,50 » y
    // laissait « 4550 », le contrôle natif répondait badInput=false et checkValidity()=true, et
    // le plat partait publié à 4 550 MAD sans un seul avertissement. Les bornes 0..100000 ne
    // pouvaient rien détecter — 4550 est dans les bornes. Ce qui protège désormais :
    // TUC_Espace_Pro::normaliser_montant(), qui lit ce que l’humain a écrit et refuse, en le
    // disant, ce qu’il ne peut pas lire. Le test est là parce qu’un correctif sans garde écrite
    // revient au commit suivant.
    $casLus = [
        ['45,50', 45.5], ['45.50', 45.5], ['45.5', 45.5], ['45', 45.0], ['0', 0.0],
        ['1 234', 1234.0], ["1\u{00A0}234", 1234.0], ["1\u{2019}234", 1234.0],
        ['1.234,56', 1234.56], [',50', 0.5], ['45,', 45.0], ['0,00', 0.0],
        ['99999,99', 99999.99], [' 45,50 ', 45.5], ['1 234,5', 1234.5],
    ];
    foreach ($casLus as [$saisie, $attendu]) {
        $lu = TUC_Espace_Pro::normaliser_montant($saisie);
        $assert($lu !== null && abs($lu - $attendu) < 0.001, sprintf('montant « %s » lu comme %.2f', $saisie, $attendu));
    }
    // Rétrocompatibilité : un nombre reçu en JSON (imports, API tierces) traverse sans être relu.
    $assert(TUC_Espace_Pro::normaliser_montant(4550) === 4550.0, 'un nombre JSON garde sa valeur, il n’est pas relu comme du texte');
    // Ce qui est ambigu est refusé et expliqué, jamais deviné : « 1,234 » vaut 1234 pour un
    // francophone et 1,234 pour un anglophone, aucun contexte ne tranche.
    foreach (['1,234', '45,500', '45,5,5', '1.2.3', '-5', 'abc', '', '45€', '1e3', '12345678'] as $refus) {
        $assert(TUC_Espace_Pro::normaliser_montant($refus) === null, sprintf('« %s » est refusé au lieu d’être deviné', $refus));
    }
    $assert(str_contains(TUC_Espace_Pro::message_montant('45,5,5', 'prix'), '45,50'), 'le message de refus dit comment réécrire');

    // Et de bout en bout, par le corps JSON que le navigateur du restaurateur envoie vraiment.
    wp_set_current_user((int) $ownerUserId);
    $soclePlat = ['description' => '', 'promo_pct' => 0, 'available' => true, 'status' => 'publish', 'options' => ['version' => 2, 'groups' => []]];
    $requeteVirgule = new WP_REST_Request('POST', '/tuc/v1/pro/menu');
    $requeteVirgule->set_body((string) wp_json_encode(array_merge($soclePlat, [
        'title' => 'Plat à la virgule ' . $suffix, 'price' => '45,50', 'promo_pct' => '10',
    ])));
    $requeteVirgule->set_header('Content-Type', 'application/json');
    $cree = TUC_Espace_Pro::creer_plat($requeteVirgule);
    $assert($cree instanceof WP_REST_Response, 'un plat saisi « 45,50 » est accepté par la route pro');
    if ($cree instanceof WP_REST_Response) {
        $idPlatVirgule = (int) ($cree->get_data()['id'] ?? 0);
        $created['items'][] = $idPlatVirgule;
        $assert($cree->get_status() === 201, 'création d’un plat à la virgule : HTTP 201');
        $assert(abs((float) get_post_meta($idPlatVirgule, 'tuc_prix', true) - 45.5) < 0.001, 'tuc_prix enregistré à 45,5 — et non 4550');
        $assert((int) get_post_meta($idPlatVirgule, 'tuc_promo_pct', true) === 10, 'une promotion écrite « 10 » suit la même lecture');
    }
    $requeteIllisible = new WP_REST_Request('POST', '/tuc/v1/pro/menu');
    $requeteIllisible->set_body((string) wp_json_encode(array_merge($soclePlat, [
        'title' => 'Plat illisible ' . $suffix, 'price' => '45,5,5',
    ])));
    $requeteIllisible->set_header('Content-Type', 'application/json');
    $refuse = TUC_Espace_Pro::creer_plat($requeteIllisible);
    $assert($refuse instanceof WP_Error && (int) ($refuse->get_error_data()['status'] ?? 0) === 422,
        'une écriture illisible est refusée en 422, elle n’est pas publiée');
    $assert($refuse instanceof WP_Error && str_contains($refuse->get_error_message(), '45,50'),
        'le refus explique comment réécrire le montant');

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

    // -----------------------------------------------------------------
    // Contrat de l'import CSV de menu.
    //
    // `require_once`, jamais `include` : si ce fichier disparaît, la suite doit
    // échouer bruyamment, pas sauter en silence. C'est exactement le défaut
    // qu'on a passé la session à traquer ailleurs (un harnais non appelé ne
    // prouve rien).
    //
    // Le nombre de cas est VÉRIFIÉ : un require qui ne charge rien, ou une
    // fonction qui sortirait avant la fin, donnerait sinon un faux vert.
    // 42 cas au moment de l'écriture ; si ce chiffre bouge, c'est soit un ajout
    // assumé, soit une régression — dans les deux cas on veut le voir.
    // -----------------------------------------------------------------
    require_once '/opt/tuc-tests/import-csv-contract.php';
    $casImport = tuc_importer_contrat_csv($assert);
    $assert(
        $casImport === 50,
        sprintf('contrat import CSV : 50 cas attendus, %d exécutés', $casImport)
    );
    WP_CLI::log(sprintf('Contrat import CSV : %d cas verts.', $casImport));

    // Écran de réglages (numéro WhatsApp). Même méthode : require_once, jamais
    // include — un fichier absent doit être fatal, pas un saut silencieux.
    require_once '/opt/tuc-tests/reglages-contract.php';
    $casReglages = tuc_reglages_contrat($assert);
    $assert(
        // 21 est MESURÉ (run 42701753558 : « 23 attendus, 21 exécutés »), pas
        // déduit d'un comptage à la main. Même leçon que les 17 → 44 du contrat
        // import : le compte se lit dans un run, il ne s'estime pas.
        $casReglages === 27,
        sprintf('contrat réglages : 27 cas attendus, %d exécutés', $casReglages)
    );
    WP_CLI::log(sprintf('Contrat réglages : %d cas verts.', $casReglages));

    // Parcours restaurateur public : limiteur à deux compteurs + photos iPhone.
    require_once '/opt/tuc-tests/parcours-restaurateur-contract.php';
    $casParcours = tuc_parcours_restaurateur_contrat($assert);
    $assert(
        // MESURÉ, jamais estimé (37, 46, 47, 48, 65, 133). Cinq estimations à la
        // main se sont déjà trompées. Cette valeur se lit dans un run.
        // 149 = 133 + les 16 cas des trois mondes de prochaine_generation()
        // (revue n°20 : ligne introuvable / conflit récupérable / panne réelle,
        // verdict rendu au client, et court-circuit des reprises sur la panne).
        $casParcours === 149,
        sprintf('contrat parcours restaurateur : 149 cas attendus, %d exécutés', $casParcours)
    );
    WP_CLI::log(sprintf('Contrat parcours restaurateur : %d cas verts.', $casParcours));

    // ---------------------------------------------------------------------
    // Régression HEIC côté REQUÊTE WEB (défaut du 2026-11-03 : wp_tempnam()
    // absent en front-end, invisible en WP-CLI où wp-admin est déjà chargé).
    //
    // Appelé DEPUIS ce runner, donc sans toucher au workflow : on lance un
    // PHP séparé qui charge wp-load.php sans wp-admin. `required` = si le
    // conteneur n'a pas de décodeur HEIC, on ÉCHOUE au lieu de sauter.
    // ---------------------------------------------------------------------
    // RÉSILIENCE DU LIMITEUR ET OBSERVATEUR HEIC (revue n°18).
    // Appelés DEPUIS ce runner, donc exécutés par la CI sans toucher au
    // workflow — le jeton de la session n'a pas le périmètre `workflow`.
    // Ces deux scripts exigent un bac déclaré jetable ; la CI en est un, et le
    // marqueur + le drapeau le prouvent explicitement.
    // ---------------------------------------------------------------------
    $bac = rtrim(ABSPATH, '/'); // racine WordPress réellement utilisée
    // Deux réglages doivent être posés AVANT de lancer les sous-processus :
    //   - WP_ENVIRONMENT_TYPE=local : par défaut WordPress vaut « production » ;
    //   - le rechargement $wp_rewrite->flush_rules() : sans lui, la route REST
    //     `tuc/v1/restaurant-applications` n'existe pas dans le permalien de
    //     ce conteneur neuf, et le POST réel tombe en 404 (et non en 201/503).
    global $wp_rewrite;
    if (is_object($wp_rewrite)) {
        $wp_rewrite->flush_rules();
    }
    $urlBac = getenv('TUC_WP_URL') ?: ('http://127.0.0.1:' . (getenv('TUC_WP_PORT') ?: '8080'));
    // `wp` est sur le PATH dans l'image wordpress:cli ; en bac local on passe le phar.
    $wpCliBin = getenv('TUC_WP_CLI') ?: 'wp';
    // Le marqueur de bac doit exister AVANT le sous-processus : la garde des
    // scripts destructifs l'exige, et le test de garde de limiteur-resilience
    // le retire puis le remet — d'où la restauration ci-dessous.
    $marqueurBac = is_writable($bac) ? $bac . '/.tuc-bac-jetable' : $bac . '/wp-content/uploads/.tuc-bac-jetable';
    @mkdir(dirname($marqueurBac), 0777, true);
    $marqueurAvant = is_file($marqueurBac) ? (string) file_get_contents($marqueurBac) : null;
    if (!is_file($marqueurBac)) {
        @file_put_contents($marqueurBac, 'bac CI ' . gmdate('c') . "\n");
    }
    $lancerBac = static function (string $script, array $args) use ($bac, $wpCliBin): array {
        $sortie = [];
        $code = 0;
        exec(
            "WP_ENVIRONMENT_TYPE=local TUC_BAC_JETABLE=1 TUC_WP_CLI=" . escapeshellarg($wpCliBin)
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg('/opt/tuc-tests/' . $script) . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1',
            $sortie,
            $code
        );
        return [$code, $sortie];
    };
    [$codeResilience, $sortieResilience] = $lancerBac(
        'limiteur-resilience.php',
        [$bac, $urlBac, '--bac-jetable']
    );
    foreach ($sortieResilience as $ligne) {
        WP_CLI::log('    [resilience] ' . $ligne);
    }
    if (!is_file($marqueurBac)) {
        @file_put_contents($marqueurBac, $marqueurAvant ?? ('bac CI ' . gmdate('c') . "\n"));
    }
    if ($codeResilience !== 0) {
        WP_CLI::warning('résilience : sortie complète ci-dessus (code ' . $codeResilience . ').');
    }
    $assert($codeResilience === 0, sprintf('résilience du limiteur, bac jetable (code %d)', $codeResilience));

    // Job « décodeur PRÉSENT, conversion obligatoire ». Ici, pas de décodeur :
    // il doit donc ÉCHOUER — c'est voulu, et le message le dit. Sur une image
    // avec libheif, il devient obligatoire et bloquant.
    [$codeRequis, $sortieRequis] = $lancerBac(
        'heic-decodeur-requis.php',
        [$bac, '/opt/tuc-tests/fixtures/heic-mire-1600x1200.heic']
    );
    foreach ($sortieRequis as $ligne) {
        WP_CLI::log('    [heic-decodeur-requis] ' . $ligne);
    }
    $decodeurIci = (bool) preg_match('/décodeur HEIC\s*:\s*oui/u', implode("\n", $sortieRequis));
    if ($decodeurIci) {
        $assert($codeRequis === 0, 'conversion HEIC OBLIGATOIRE (décodeur présent) : ' . $codeRequis);
    } else {
        WP_CLI::warning('JOB HEIC À DÉCODEUR : non exécutable ici (aucun décodeur). Ce job doit tourner sur une image avec libheif et y être BLOQUANT. Voir livre-ci-decodeur-heic.md.');
        $assert($codeRequis !== 0, 'sans décodeur, le job à décodeur requis ÉCHOUE bien (code ' . $codeRequis . ')');
    }

    [$codeObservateur, $sortieObservateur] = $lancerBac(
        'observateur-heic.php',
        [$bac, '--bac-jetable']
    );
    foreach ($sortieObservateur as $ligne) {
        WP_CLI::log('    [observateur] ' . $ligne);
    }
    $assert($codeObservateur === 0, sprintf('observateur HEIC, bac jetable (code %d)', $codeObservateur));

    // ---------------------------------------------------------------------
    // Deux tests, deux objets distincts, jamais confondus :
    //   A. heic-aiguillage.php      : la DÉPENDANCE et l'aiguillage. Aucun
    //      décodeur requis -> doit être vert PARTOUT, y compris dans l'image
    //      wordpress:cli qui a Imagick sans libheif (mesuré en CI le 2026-11-10).
    //   B. heic-dependance-http.php : la CONVERSION réelle. Exige un décodeur.
    //      Si l'image n'en a pas, l'assertion est ANNULÉE explicitement, avec la
    //      raison écrite dans le log. Ce n'est pas un skip silencieux : le log
    //      imprimé dans la CI dit lequel des deux a été vérifié. La couverture
    //      de la conversion est alors assurée par le bac jetable, où libheif
    //      est présent (logs livrés).
    $php = PHP_BINARY;
    // Le témoin est surchargeable UNIQUEMENT pour démontrer le comportement
    // « décodeur présent mais conversion en échec » (fixture volontairement
    // corrompue). En CI, la variable n'est pas posée : c'est le vrai témoin.
    $temoin = getenv('TUC_HEIC_FIXTURE') ?: 'fixtures/heic-mire-1600x1200.heic';
    $sortie = [];
    $codeSortie = 0;
    exec(
        escapeshellarg($php) . ' ' . escapeshellarg('/opt/tuc-tests/heic-aiguillage.php') . ' '
        . escapeshellarg(ABSPATH) . ' ' . escapeshellarg($temoin) . ' 2>&1',
        $sortie,
        $codeSortie
    );
    foreach ($sortie as $ligne) {
        WP_CLI::log('    [heic-aiguillage] ' . $ligne);
    }
    $assert(
        $codeSortie === 0,
        sprintf('aiguillage HEIC + dépendance wp_tempnam() côté web (code %d)', $codeSortie)
    );

    // ---------------------------------------------------------------------
    // DEUX comportements distincts, deux verdicts distincts :
    //   - décodeur ABSENT  => capacité NON VÉRIFIÉE. Le job reste vert, mais le
    //     log le dit en clair (ce n'est pas un skip silencieux).
    //   - décodeur PRÉSENT mais conversion en échec => job ROUGE. Un échec réel
    //     n'est jamais dégradé en avertissement.
    // Mesuré en CI le 2026-11-10 : l'image wordpress:cli n'a pas de décodeur.
    // ---------------------------------------------------------------------
    $sortieConv = [];
    $codeConv = 0;
    exec(
        escapeshellarg($php) . ' ' . escapeshellarg('/opt/tuc-tests/heic-dependance-http.php') . ' '
        . escapeshellarg(ABSPATH) . ' ' . escapeshellarg($temoin) . ' 2>&1',
        $sortieConv,
        $codeConv
    );
    $texteConv = implode("\n", $sortieConv);
    foreach ($sortieConv as $ligne) {
        WP_CLI::log('    [heic-conversion] ' . $ligne);
    }
    // DÉFAUT ATTRAPÉ PAR LA CI DU 2026-11-10 : sans décodeur, le script sort en
    // 0 parce qu'il a vérifié le CHEMIN D'ERREUR — et je lisais ce 0 comme une
    // « conversion réussie ». La présence du décodeur se lit donc d'abord, dans
    // la sortie ; le code de sortie ne départage qu'ensuite.
    $decodeurPresent = (bool) preg_match('/décodeur HEIC\s*:\s*oui/u', $texteConv);
    if (!$decodeurPresent) {
        // Capacité NON TESTÉE : ce n'est ni un succès ni un échec du produit.
        WP_CLI::warning('CONVERSION HEIC NON VÉRIFIÉE ICI : aucun décodeur HEIC dans ce conteneur. Ce n\'est pas un succès, c\'est une capacité non testée (seul le chemin d\'erreur est couvert).');
    } elseif ($codeConv === 0) {
        $assert(true, 'conversion HEIC réelle côté web (décodeur présent, conversion réussie)');
    } else {
        $assert(false, 'conversion HEIC réelle : décodeur PRÉSENT mais la conversion a ÉCHOUÉ (code ' . $codeConv . ')');
    }
}
