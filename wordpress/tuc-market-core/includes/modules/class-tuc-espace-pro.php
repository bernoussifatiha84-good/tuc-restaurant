<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Affectation des comptes, API de menu isolée par restaurant et espace mobile. */
final class TUC_Espace_Pro
{
    private const MEMBER_ROLES = ['tuc_restaurateur', 'tuc_gestionnaire', 'tuc_caissier', 'tuc_serveur'];
    private const PHOTO_MAX_BYTES = 5242880;

    public static function init(): void
    {
        add_action('init', [self::class, 'rewrite'], 4);
        add_action('init', [self::class, 'upgrade_rewrites'], 99);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('template_redirect', [self::class, 'servir_espace_pro'], 0);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_tuc_affecter_membre', [self::class, 'affecter_membre']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_filter('login_redirect', [self::class, 'redirect_apres_connexion'], 10, 3);
    }

    public static function rewrite(): void
    {
        add_rewrite_rule('^espace-pro/?$', 'index.php?tuc_espace_pro=1', 'top');
    }

    public static function query_vars(array $vars): array
    {
        $vars[] = 'tuc_espace_pro';
        return $vars;
    }

    public static function upgrade_rewrites(): void
    {
        if ((int) get_option('tuc_pro_rewrite_version', 0) < 1) {
            flush_rewrite_rules(false);
            update_option('tuc_pro_rewrite_version', 1, false);
        }
    }

    public static function admin_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=tuc_resto',
            'Équipe et affectations TUC',
            'Équipe TUC',
            'manage_options',
            'tuc-equipe',
            [self::class, 'page_affectations']
        );
    }

    /** Interface réservée aux administrateurs WordPress; aucun propriétaire ne gère ses rôles. */
    public static function page_affectations(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé aux administrateurs.', 'tuc-core'), '', ['response' => 403]);
        }
        $restaurants = get_posts([
            'post_type' => 'tuc_resto', 'post_status' => 'publish', 'posts_per_page' => 200,
            'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true,
        ]);
        $users = get_users(['number' => 500, 'orderby' => 'display_name', 'order' => 'ASC']);
        $notice = sanitize_key((string) ($_GET['tuc_notice'] ?? ''));
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('Équipe TUC — affectation par restaurant', 'tuc-core'); ?></h1>
          <p><?php esc_html_e('Un compte TUC n’accède à aucun restaurant tant qu’un rôle métier et un restaurant publié ne lui sont pas affectés. Les rôles TUC ne reçoivent pas les droits administrateur WordPress.', 'tuc-core'); ?></p>
          <?php if ($notice === 'saved') : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Affectation enregistrée.', 'tuc-core'); ?></p></div><?php endif; ?>
          <?php if ($notice === 'error') : ?><div class="notice notice-error"><p><?php esc_html_e('Affectation invalide : choisissez un compte non administrateur, un rôle TUC et un restaurant publié.', 'tuc-core'); ?></p></div><?php endif; ?>
          <div class="tuc-team-grid">
          <?php foreach ($users as $user) :
              if (user_can($user, 'manage_options')) {
                  continue;
              }
              $role = array_values(array_intersect((array) $user->roles, self::MEMBER_ROLES))[0] ?? '';
              $restaurantId = (int) get_user_meta($user->ID, 'tuc_resto_id', true);
          ?>
            <form class="tuc-team-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="tuc_affecter_membre">
              <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>">
              <?php wp_nonce_field('tuc_affecter_membre_' . $user->ID); ?>
              <h2><?php echo esc_html($user->display_name ?: $user->user_login); ?></h2>
              <p class="description"><?php echo esc_html($user->user_login); ?></p>
              <label><?php esc_html_e('Rôle TUC', 'tuc-core'); ?>
                <select name="tuc_role">
                  <option value="none" <?php selected($role, ''); ?>><?php esc_html_e('Aucun accès TUC', 'tuc-core'); ?></option>
                  <option value="tuc_restaurateur" <?php selected($role, 'tuc_restaurateur'); ?>><?php esc_html_e('Restaurateur', 'tuc-core'); ?></option>
                  <option value="tuc_gestionnaire" <?php selected($role, 'tuc_gestionnaire'); ?>><?php esc_html_e('Gestionnaire', 'tuc-core'); ?></option>
                  <option value="tuc_caissier" <?php selected($role, 'tuc_caissier'); ?>><?php esc_html_e('Caissier', 'tuc-core'); ?></option>
                  <option value="tuc_serveur" <?php selected($role, 'tuc_serveur'); ?>><?php esc_html_e('Serveur (commandes, bip + vibration à table)', 'tuc-core'); ?></option>
                </select>
              </label>
              <label><?php esc_html_e('Restaurant', 'tuc-core'); ?>
                <select name="restaurant_id">
                  <option value="0" <?php selected($restaurantId, 0); ?>><?php esc_html_e('— Aucun —', 'tuc-core'); ?></option>
                  <?php foreach ($restaurants as $restaurant) : ?>
                    <option value="<?php echo esc_attr((string) $restaurant->ID); ?>" <?php selected($restaurantId, $restaurant->ID); ?>><?php echo esc_html($restaurant->post_title); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button class="button button-primary" type="submit"><?php esc_html_e('Enregistrer', 'tuc-core'); ?></button>
            </form>
          <?php endforeach; ?>
          </div>
          <style>
            .tuc-team-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:16px;margin-top:20px}
            .tuc-team-card{display:grid;gap:12px;padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:12px}
            .tuc-team-card h2,.tuc-team-card p{margin:0}.tuc-team-card label{display:grid;gap:6px;font-weight:600}
            .tuc-team-card select,.tuc-team-card button{min-height:44px;max-width:100%}
          </style>
        </div>
        <?php
    }

    public static function valider_affectation(int $userId, string $role, int $restaurantId): array|WP_Error
    {
        $user = get_user_by('id', $userId);
        if (!$user instanceof WP_User || user_can($user, 'manage_options')) {
            return new WP_Error('tuc_staff_user', 'Compte administrateur ou utilisateur introuvable.', ['status' => 422]);
        }
        if ($role === 'none') {
            return ['user' => $user, 'role' => 'none', 'restaurant_id' => 0];
        }
        $restaurant = $restaurantId > 0 ? get_post($restaurantId) : null;
        if (!in_array($role, self::MEMBER_ROLES, true)
            || !$restaurant instanceof WP_Post
            || $restaurant->post_type !== TUC_CPT::RESTO
            || $restaurant->post_status !== 'publish') {
            return new WP_Error('tuc_staff_assignment', 'Rôle TUC et restaurant publié requis.', ['status' => 422]);
        }
        return ['user' => $user, 'role' => $role, 'restaurant_id' => $restaurant->ID];
    }

    public static function affecter_membre(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé aux administrateurs.', 'tuc-core'), '', ['response' => 403]);
        }
        $userId = absint($_POST['user_id'] ?? 0);
        check_admin_referer('tuc_affecter_membre_' . $userId);
        $role = sanitize_key((string) wp_unslash($_POST['tuc_role'] ?? 'none'));
        $restaurantId = absint($_POST['restaurant_id'] ?? 0);
        $assignment = self::valider_affectation($userId, $role, $restaurantId);
        if (is_wp_error($assignment)) {
            self::retour_affectation('error');
        }
        /** @var WP_User $user */
        $user = $assignment['user'];
        if ($role === 'none') {
            delete_user_meta($userId, 'tuc_resto_id');
            if (array_intersect((array) $user->roles, self::MEMBER_ROLES) !== []) {
                $user->set_role('subscriber');
            }
            self::retour_affectation('saved');
        }

        $user->set_role($role);
        update_user_meta($userId, 'tuc_resto_id', $assignment['restaurant_id']);
        self::retour_affectation('saved');
    }

    private static function retour_affectation(string $notice): never
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'tuc-equipe', 'tuc_notice' => $notice],
            admin_url('edit.php?post_type=tuc_resto')
        ));
        exit;
    }

    public static function routes(): void
    {
        register_rest_route('tuc/v1', '/pro/restaurant', [
            'methods' => 'GET', 'permission_callback' => [self::class, 'permission_restaurant'],
            'callback' => [self::class, 'restaurant_courant'],
        ]);
        register_rest_route('tuc/v1', '/pro/restaurant', [
            'methods' => ['PUT', 'PATCH'], 'permission_callback' => [self::class, 'permission_restaurant'],
            'callback' => [self::class, 'modifier_restaurant'],
        ]);
        register_rest_route('tuc/v1', '/pro/restaurant/location', [
            'methods' => 'POST', 'permission_callback' => [self::class, 'permission_restaurant'],
            'callback' => [self::class, 'enregistrer_localisation_restaurant'],
        ]);
        register_rest_route('tuc/v1', '/pro/commandes', [
            'methods' => 'GET', 'permission_callback' => [self::class, 'permission_commandes'],
            'callback' => [self::class, 'commandes_pro'],
        ]);
        register_rest_route('tuc/v1', '/pro/commandes/(?P<id>\d+)/statut', [
            'methods' => 'POST', 'permission_callback' => [self::class, 'permission_statut_pro'],
            'callback' => [self::class, 'changer_statut_pro'],
        ]);
        register_rest_route('tuc/v1', '/pro/menu', [
            'methods' => 'GET', 'permission_callback' => [self::class, 'permission_menu'],
            'callback' => [self::class, 'liste_menu'],
        ]);
        register_rest_route('tuc/v1', '/pro/menu', [
            'methods' => 'POST', 'permission_callback' => [self::class, 'permission_menu'],
            'callback' => [self::class, 'creer_plat'],
        ]);
        register_rest_route('tuc/v1', '/pro/menu/(?P<id>\d+)', [
            'methods' => ['PUT', 'PATCH'], 'permission_callback' => [self::class, 'permission_menu'],
            'callback' => [self::class, 'modifier_plat'],
        ]);
        register_rest_route('tuc/v1', '/pro/menu/(?P<id>\d+)', [
            'methods' => 'DELETE', 'permission_callback' => [self::class, 'permission_menu'],
            'callback' => [self::class, 'archiver_plat'],
        ]);
        register_rest_route('tuc/v1', '/pro/menu/(?P<id>\d+)/photo', [
            'methods' => 'POST', 'permission_callback' => [self::class, 'permission_menu'],
            'callback' => [self::class, 'televerser_photo'],
        ]);
        register_rest_route('tuc/v1', '/menu/(?P<slug>[a-z0-9-]+)', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => [self::class, 'menu_public'],
        ]);
    }

    public static function permission_menu(): bool
    {
        $restaurant = TUC_Caisse::mon_resto();
        return $restaurant instanceof WP_Post
            && TUC_Acces::peut(TUC_Roles::CAP_MANAGE_MENU, $restaurant->ID);
    }

    public static function permission_restaurant(): bool
    {
        $restaurant = TUC_Caisse::mon_resto();
        return $restaurant instanceof WP_Post && TUC_Acces::peut(TUC_Roles::CAP_MANAGE_RESTAURANT, $restaurant->ID);
    }

    public static function permission_commandes(): bool
    {
        $restaurant = TUC_Caisse::mon_resto();
        return $restaurant instanceof WP_Post && TUC_Acces::peut(TUC_Roles::CAP_VIEW_ORDERS, $restaurant->ID);
    }

    public static function permission_statut_pro(): bool
    {
        $restaurant = TUC_Caisse::mon_resto();
        return $restaurant instanceof WP_Post && TUC_Acces::peut(TUC_Roles::CAP_UPDATE_ORDERS, $restaurant->ID);
    }

    public static function restaurant_courant(): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        if (!$restaurant instanceof WP_Post) {
            return new WP_Error('tuc_resto', 'Aucun restaurant assigné à ce compte.', ['status' => 409]);
        }
        return rest_ensure_response([
            'id' => $restaurant->ID,
            'name' => sanitize_text_field($restaurant->post_title),
            'slug' => $restaurant->post_name,
            'minimum' => (float) get_post_meta($restaurant->ID, 'tuc_minimum', true),
            'fee' => (float) get_post_meta($restaurant->ID, 'tuc_frais', true),
            'open' => get_post_meta($restaurant->ID, 'tuc_ferme', true) !== '1',
            'modes' => array_values(array_intersect((array) get_post_meta($restaurant->ID, 'tuc_modes', true), ['livraison', 'emporter', 'table'])),
            'tables' => max(0, (int) get_post_meta($restaurant->ID, 'tuc_tables', true)),
            'location' => self::localisation_restaurant($restaurant->ID),
        ]);
    }

    /** Coordonnées privées du tableau de bord, servies uniquement au responsable autorisé. */
    private static function localisation_restaurant(int $restaurantId): ?array
    {
        $lat = get_post_meta($restaurantId, 'tuc_lat', true);
        $lng = get_post_meta($restaurantId, 'tuc_lng', true);
        if (!is_numeric($lat) || !is_numeric($lng)
            || (float) $lat < -90 || (float) $lat > 90
            || (float) $lng < -180 || (float) $lng > 180) {
            return null;
        }
        $accuracy = get_post_meta($restaurantId, 'tuc_coord_accuracy', true);
        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'source' => sanitize_key((string) get_post_meta($restaurantId, 'tuc_coord_source', true)),
            'accuracy' => is_numeric($accuracy) && (float) $accuracy > 0 ? (float) $accuracy : null,
            'updatedAt' => max(0, (int) get_post_meta($restaurantId, 'tuc_coord_updated_at', true)),
        ];
    }

    public static function enregistrer_localisation_restaurant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        if (!$restaurant instanceof WP_Post) {
            return new WP_Error('tuc_resto', 'Aucun restaurant assigné à ce compte.', ['status' => 409]);
        }
        $payload = $request->get_json_params();
        if (!is_array($payload) || !is_numeric($payload['lat'] ?? null) || !is_numeric($payload['lng'] ?? null)) {
            return new WP_Error('tuc_location_fields', 'Coordonnées manquantes ou invalides.', ['status' => 422]);
        }
        $lat = (float) $payload['lat'];
        $lng = (float) $payload['lng'];
        if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return new WP_Error('tuc_location_bounds', 'Les coordonnées doivent être valides (latitude −90 à 90, longitude −180 à 180).', ['status' => 422]);
        }
        $source = is_string($payload['source'] ?? null) ? sanitize_key($payload['source']) : '';
        if (!in_array($source, ['gps', 'google_maps_link'], true)) {
            return new WP_Error('tuc_location_source', 'Utilisez le GPS sur place ou un lien Google Maps contenant les coordonnées.', ['status' => 422]);
        }
        if ($source === 'gps') {
            $accuracy = $payload['accuracy'] ?? null;
            $capturedAt = $payload['capturedAt'] ?? null;
            $now = time();
            if (!is_numeric($accuracy) || !is_finite((float) $accuracy) || (float) $accuracy <= 0 || (float) $accuracy > 100
                || !is_numeric($capturedAt) || (int) $capturedAt > $now + 60 || (int) $capturedAt < $now - 600) {
                return new WP_Error('tuc_location_accuracy', 'La position GPS est trop imprécise ou trop ancienne. Réessayez sur place ou utilisez un lien Google Maps.', ['status' => 422]);
            }
        } elseif (($payload['confirmed'] ?? false) !== true) {
            return new WP_Error('tuc_location_confirmation', 'Confirmez que le repère Google Maps correspond bien à votre établissement.', ['status' => 422]);
        }

        // Le site ne propose actuellement que les quartiers de Casablanca. Évite de
        // classer silencieusement des établissements hors de la zone desservie.
        if (class_exists(TUC_Geo::class)) {
            $withinServiceArea = false;
            foreach (TUC_Geo::quartiers() as $quartier) {
                if (TUC_Geo::distance2($lat, $lng, (float) $quartier['lat'], (float) $quartier['lng']) <= 25_000 * 25_000) {
                    $withinServiceArea = true;
                    break;
                }
            }
            if (!$withinServiceArea) {
                return new WP_Error('tuc_location_area', 'Cette position est hors de la zone actuellement desservie à Casablanca.', ['status' => 422]);
            }
        }

        update_post_meta($restaurant->ID, 'tuc_lat', round($lat, 6));
        update_post_meta($restaurant->ID, 'tuc_lng', round($lng, 6));
        update_post_meta($restaurant->ID, 'tuc_coord_source', $source);
        update_post_meta($restaurant->ID, 'tuc_coord_updated_at', time());
        if ($source === 'gps') {
            update_post_meta($restaurant->ID, 'tuc_coord_accuracy', round((float) $payload['accuracy'], 1));
        } else {
            delete_post_meta($restaurant->ID, 'tuc_coord_accuracy');
        }

        return self::restaurant_courant();
    }

    public static function modifier_restaurant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        if (!$restaurant instanceof WP_Post) {
            return new WP_Error('tuc_resto', 'Aucun restaurant assigné à ce compte.', ['status' => 409]);
        }
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('tuc_restaurant_fields', 'Paramètres de boutique invalides.', ['status' => 422]);
        }
        $storedMinimum = get_post_meta($restaurant->ID, 'tuc_minimum', true);
        $storedFee = get_post_meta($restaurant->ID, 'tuc_frais', true);
        $storedTables = get_post_meta($restaurant->ID, 'tuc_tables', true);
        $minimum = $payload['minimum'] ?? (is_numeric($storedMinimum) ? $storedMinimum : 0);
        $fee = $payload['fee'] ?? (is_numeric($storedFee) ? $storedFee : 0);
        $tables = $payload['tables'] ?? (is_numeric($storedTables) ? $storedTables : 0);
        if (!is_numeric($minimum) || (float) $minimum < 0 || (float) $minimum > 50000
            || !is_numeric($fee) || (float) $fee < 0 || (float) $fee > 10000
            || !is_numeric($tables) || (float) $tables !== (float) (int) $tables || (int) $tables < 0 || (int) $tables > 500) {
            return new WP_Error('tuc_restaurant_fields', 'Minimum, frais ou nombre de tables invalide.', ['status' => 422]);
        }
        $modes = (array) get_post_meta($restaurant->ID, 'tuc_modes', true);
        if (array_key_exists('modes', $payload)) {
            if (!is_array($payload['modes'])) {
                return new WP_Error('tuc_restaurant_modes', 'Sélectionnez au moins un mode de service.', ['status' => 422]);
            }
            $modes = array_values(array_unique(array_filter(array_map(
                static fn($mode): string => is_scalar($mode) ? sanitize_key((string) $mode) : '',
                $payload['modes']
            ))));
            if ($modes === [] || array_diff($modes, ['livraison', 'emporter', 'table']) !== []) {
                return new WP_Error('tuc_restaurant_modes', 'Modes acceptés : livraison, à emporter ou à table.', ['status' => 422]);
            }
        }
        if (in_array('table', $modes, true) && (int) $tables < 1) {
            return new WP_Error('tuc_restaurant_tables', 'Indiquez au moins une table pour activer le service à table.', ['status' => 422]);
        }
        if (array_key_exists('open', $payload) && !is_scalar($payload['open'])) {
            return new WP_Error('tuc_restaurant_open', 'L’état d’ouverture est invalide.', ['status' => 422]);
        }

        update_post_meta($restaurant->ID, 'tuc_minimum', round((float) $minimum, 2));
        update_post_meta($restaurant->ID, 'tuc_frais', round((float) $fee, 2));
        update_post_meta($restaurant->ID, 'tuc_tables', (int) $tables);
        if (array_key_exists('modes', $payload)) {
            update_post_meta($restaurant->ID, 'tuc_modes', $modes);
        }
        if (array_key_exists('open', $payload)) {
            update_post_meta($restaurant->ID, 'tuc_ferme', rest_sanitize_boolean($payload['open']) ? '0' : '1');
        }
        return self::restaurant_courant();
    }

    public static function commandes_pro(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        if (!$restaurant instanceof WP_Post || !TUC_Acces::peut(TUC_Roles::CAP_VIEW_ORDERS, $restaurant->ID)) {
            return new WP_Error('rest_forbidden', 'Accès aux commandes refusé.', ['status' => 403]);
        }
        $rawStatus = $request->get_param('statut');
        $status = is_scalar($rawStatus) ? sanitize_key((string) $rawStatus) : 'toutes';
        $allowed = TUC_Commande::STATUTS;
        if ($status !== '' && $status !== 'toutes' && !in_array($status, $allowed, true)) {
            return new WP_Error('tuc_order_filter', 'Filtre de statut invalide.', ['status' => 422]);
        }
        $meta = [['key' => 'tuc_resto', 'value' => $restaurant->ID, 'compare' => '=', 'type' => 'NUMERIC']];
        if ($status !== '' && $status !== 'toutes') {
            $meta[] = ['key' => 'tuc_statut', 'value' => $status];
        }
        $orders = get_posts([
            'post_type' => TUC_Commande::CPT, 'post_status' => 'publish', 'posts_per_page' => 100,
            'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true, 'meta_query' => $meta,
        ]);
        $output = [];
        foreach ($orders as $order) {
            $items = [];
            foreach ((array) get_post_meta($order->ID, 'tuc_items', true) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $items[] = [
                    'title' => is_scalar($line['nom'] ?? null) ? sanitize_text_field((string) $line['nom']) : '',
                    'quantity' => max(1, min(50, (int) ($line['q'] ?? 1))),
                    'unit_price' => max(0.0, (float) ($line['pu'] ?? 0)),
                    'options' => array_values(array_map(static fn($option): string => is_scalar($option) ? sanitize_text_field((string) $option) : '', (array) ($line['opts'] ?? []))),
                ];
            }
            $table = (int) get_post_meta($order->ID, 'tuc_table', true);
            $orderMode = sanitize_key((string) get_post_meta($order->ID, 'tuc_mode', true));
            if (!in_array($orderMode, ['livraison', 'emporter', 'table'], true)) {
                $orderMode = $table > 0 ? 'table' : ((float) get_post_meta($order->ID, 'tuc_frais', true) > 0 ? 'livraison' : 'emporter');
            }
            $output[] = [
                'id' => $order->ID,
                'reference' => (string) $order->ID,
                'created_at' => get_post_time('c', true, $order),
                'mode' => $orderMode,
                'table' => $table,
                'status' => sanitize_key((string) get_post_meta($order->ID, 'tuc_statut', true)) ?: 'nouvelle',
                'total' => (float) get_post_meta($order->ID, 'tuc_total', true),
                'items' => $items,
            ];
        }
        return rest_ensure_response(['orders' => $output, 'count' => count($output)]);
    }

    public static function changer_statut_pro(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!self::permission_statut_pro()) {
            return new WP_Error('rest_forbidden', 'Modification de commande refusée.', ['status' => 403]);
        }
        $request->set_param('id', absint($request['id']));
        return TUC_Commande::changer_statut($request);
    }

    public static function liste_menu(): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        if (!$restaurant instanceof WP_Post) {
            return new WP_Error('tuc_resto', 'Aucun restaurant assigné à ce compte.', ['status' => 409]);
        }
        $items = get_posts([
            'post_type' => TUC_CPT::ITEM, 'post_status' => ['publish', 'draft'],
            'posts_per_page' => 200, 'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'no_found_rows' => true,
            'meta_query' => [['key' => 'tuc_resto_id', 'value' => $restaurant->ID, 'compare' => '=', 'type' => 'NUMERIC']],
        ]);
        $output = [];
        foreach ($items as $item) {
            $serialised = self::serialiser_plat($item);
            if (is_wp_error($serialised)) {
                return $serialised;
            }
            $output[] = $serialised;
        }
        return rest_ensure_response($output);
    }

    private static function serialiser_plat(WP_Post $item): array|WP_Error
    {
        $options = TUC_Options::normaliser_definition(get_post_meta($item->ID, 'tuc_options', true));
        if (is_wp_error($options)) {
            return $options;
        }
        return [
            'id' => $item->ID,
            'title' => sanitize_text_field($item->post_title),
            'description' => sanitize_textarea_field($item->post_content),
            'price' => (float) get_post_meta($item->ID, 'tuc_prix', true),
            'promo_pct' => max(0, min(100, (int) get_post_meta($item->ID, 'tuc_promo_pct', true))),
            'available' => get_post_meta($item->ID, 'tuc_dispo', true) !== '0',
            'status' => $item->post_status,
            'options' => $options,
            'photo' => esc_url_raw(get_the_post_thumbnail_url($item->ID, 'medium') ?: ''),
        ];
    }

    public static function creer_plat(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        if (!$restaurant instanceof WP_Post) {
            return new WP_Error('tuc_resto', 'Aucun restaurant assigné à ce compte.', ['status' => 409]);
        }
        $fields = self::valider_payload((array) $request->get_json_params(), null);
        if (is_wp_error($fields)) {
            return $fields;
        }
        $id = wp_insert_post([
            'post_type' => TUC_CPT::ITEM,
            'post_status' => $fields['status'],
            'post_title' => $fields['title'],
            'post_content' => $fields['description'],
            'post_author' => get_current_user_id(),
            'meta_input' => [
                'tuc_resto_id' => $restaurant->ID,
                'tuc_prix' => $fields['price'],
                'tuc_promo_pct' => $fields['promo_pct'],
                'tuc_dispo' => $fields['available'] ? '1' : '0',
                'tuc_options' => $fields['options'],
                'tuc_options_schema_version' => TUC_Options::VERSION,
            ],
        ], true);
        if (is_wp_error($id)) {
            return $id;
        }
        return new WP_REST_Response(self::serialiser_plat(get_post((int) $id)), 201);
    }

    public static function modifier_plat(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        $id = absint($request['id']);
        $item = get_post($id);
        if (!$restaurant instanceof WP_Post || !$item instanceof WP_Post || $item->post_type !== TUC_CPT::ITEM
            || (int) get_post_meta($id, 'tuc_resto_id', true) !== $restaurant->ID) {
            return new WP_Error('tuc_item', 'Plat introuvable dans ce restaurant.', ['status' => 404]);
        }
        $fields = self::valider_payload((array) $request->get_json_params(), $item);
        if (is_wp_error($fields)) {
            return $fields;
        }
        $updated = wp_update_post([
            'ID' => $id, 'post_title' => $fields['title'], 'post_content' => $fields['description'],
            'post_status' => $fields['status'],
        ], true);
        if (is_wp_error($updated)) {
            return $updated;
        }
        update_post_meta($id, 'tuc_prix', $fields['price']);
        update_post_meta($id, 'tuc_promo_pct', $fields['promo_pct']);
        update_post_meta($id, 'tuc_dispo', $fields['available'] ? '1' : '0');
        update_post_meta($id, 'tuc_options', $fields['options']);
        update_post_meta($id, 'tuc_options_schema_version', TUC_Options::VERSION);
        return rest_ensure_response(self::serialiser_plat(get_post($id)));
    }

    public static function archiver_plat(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        $id = absint($request['id']);
        $item = get_post($id);
        if (!$restaurant instanceof WP_Post || !$item instanceof WP_Post || $item->post_type !== TUC_CPT::ITEM
            || (int) get_post_meta($id, 'tuc_resto_id', true) !== $restaurant->ID) {
            return new WP_Error('tuc_item', 'Plat introuvable dans ce restaurant.', ['status' => 404]);
        }
        $result = wp_update_post(['ID' => $id, 'post_status' => 'draft'], true);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(['ok' => true, 'status' => 'draft']);
    }

    private static function valider_payload(array $payload, ?WP_Post $existing): array|WP_Error
    {
        $oldOptions = $existing ? get_post_meta($existing->ID, 'tuc_options', true) : [];
        $titleValue = $payload['title'] ?? ($existing ? $existing->post_title : '');
        $descriptionValue = $payload['description'] ?? ($existing ? $existing->post_content : '');
        if (!is_scalar($titleValue) || !is_scalar($descriptionValue)) {
            return new WP_Error('tuc_menu_fields', 'Nom et description doivent être du texte.', ['status' => 422]);
        }
        $title = sanitize_text_field((string) $titleValue);
        $description = sanitize_textarea_field((string) $descriptionValue);
        $priceValue = $payload['price'] ?? ($existing ? get_post_meta($existing->ID, 'tuc_prix', true) : null);
        $promoValue = $payload['promo_pct'] ?? ($existing ? get_post_meta($existing->ID, 'tuc_promo_pct', true) : 0);
        if ($title === '' || strlen($title) > 400 || strlen($description) > 4000 || !is_numeric($priceValue) || (float) $priceValue < 0 || (float) $priceValue > 100000) {
            return new WP_Error('tuc_menu_fields', 'Nom et prix valides requis.', ['status' => 422]);
        }
        if (!is_numeric($promoValue) || (int) $promoValue < 0 || (int) $promoValue > 100) {
            return new WP_Error('tuc_promo', 'La promotion doit être comprise entre 0 et 100 %.', ['status' => 422]);
        }
        $options = TUC_Options::normaliser_definition($payload['options'] ?? $oldOptions);
        if (is_wp_error($options)) {
            return $options;
        }
        $statusValue = $payload['status'] ?? ($existing ? $existing->post_status : 'draft');
        if (!is_scalar($statusValue)) {
            return new WP_Error('tuc_menu_status', 'Statut de publication invalide.', ['status' => 422]);
        }
        $status = sanitize_key((string) $statusValue);
        if (!in_array($status, ['draft', 'publish'], true)) {
            return new WP_Error('tuc_menu_status', 'Statut de publication invalide.', ['status' => 422]);
        }
        $availableValue = $payload['available'] ?? ($existing ? get_post_meta($existing->ID, 'tuc_dispo', true) !== '0' : true);
        if (!is_scalar($availableValue)) {
            return new WP_Error('tuc_menu_fields', 'La disponibilité doit être booléenne.', ['status' => 422]);
        }
        return [
            'title' => $title,
            'description' => $description,
            'price' => round((float) $priceValue, 2),
            'promo_pct' => (int) $promoValue,
            'available' => rest_sanitize_boolean($availableValue),
            'status' => $status,
            'options' => $options,
        ];
    }

    public static function televerser_photo(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $restaurant = TUC_Caisse::mon_resto();
        $id = absint($request['id']);
        $item = get_post($id);
        if (!$restaurant instanceof WP_Post || !$item instanceof WP_Post || $item->post_type !== TUC_CPT::ITEM
            || (int) get_post_meta($id, 'tuc_resto_id', true) !== $restaurant->ID) {
            return new WP_Error('tuc_item', 'Plat introuvable dans ce restaurant.', ['status' => 404]);
        }
        if (empty($_FILES['file']) || !is_array($_FILES['file'])
            || !isset($_FILES['file']['error'], $_FILES['file']['size'], $_FILES['file']['tmp_name'], $_FILES['file']['name'])
            || !is_scalar($_FILES['file']['name']) || !is_scalar($_FILES['file']['tmp_name'])
            || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('tuc_photo', 'Choisissez une photo valide.', ['status' => 422]);
        }
        $file = $_FILES['file'];
        if ((int) $file['size'] < 1 || (int) $file['size'] > self::PHOTO_MAX_BYTES || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('tuc_photo', 'La photo dépasse 5 Mo ou le téléversement est invalide.', ['status' => 422]);
        }
        $image = @getimagesize($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $type = wp_check_filetype_and_ext($file['tmp_name'], sanitize_file_name($file['name']));
        $width = is_array($image) ? (int) ($image[0] ?? 0) : 0;
        $height = is_array($image) ? (int) ($image[1] ?? 0) : 0;
        if (!is_array($image) || $width < 1 || $height < 1 || $width > 8000 || $height > 8000
            || $width * $height > 40000000 || !in_array((string) ($image['mime'] ?? ''), $allowed, true)
            || !in_array((string) ($type['type'] ?? ''), $allowed, true)) {
            return new WP_Error('tuc_photo', 'Formats acceptés : JPEG, PNG ou WebP.', ['status' => 422]);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $uploaded = wp_handle_upload($file, [
            'test_form' => false,
            'mimes' => ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'],
        ]);
        if (isset($uploaded['error'])) {
            return new WP_Error('tuc_photo', sanitize_text_field((string) $uploaded['error']), ['status' => 400]);
        }
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $uploaded['type'],
            'post_title' => sanitize_text_field(pathinfo((string) $file['name'], PATHINFO_FILENAME)),
            'post_status' => 'inherit',
            'post_author' => get_current_user_id(),
        ], $uploaded['file'], $id, true);
        if (is_wp_error($attachmentId)) {
            wp_delete_file($uploaded['file']);
            return $attachmentId;
        }
        wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $uploaded['file']));
        set_post_thumbnail($id, $attachmentId);
        return new WP_REST_Response(['id' => (int) $attachmentId, 'url' => wp_get_attachment_image_url($attachmentId, 'medium') ?: ''], 201);
    }

    public static function menu_public(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = sanitize_title((string) $request['slug']);
        $restaurant = get_page_by_path($slug, OBJECT, TUC_CPT::RESTO);
        if (!$restaurant instanceof WP_Post || $restaurant->post_status !== 'publish') {
            return new WP_Error('tuc_resto', 'Restaurant introuvable.', ['status' => 404]);
        }
        $items = get_posts([
            'post_type' => TUC_CPT::ITEM, 'post_status' => 'publish', 'posts_per_page' => 100,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'], 'no_found_rows' => true,
            'meta_query' => [['key' => 'tuc_resto_id', 'value' => $restaurant->ID, 'compare' => '=', 'type' => 'NUMERIC']],
        ]);
        $menu = [];
        foreach ($items as $item) {
            $options = TUC_Options::normaliser_definition(get_post_meta($item->ID, 'tuc_options', true));
            if (is_wp_error($options)) {
                continue;
            }
            $menu[] = [
                'id' => $item->ID,
                'title' => sanitize_text_field($item->post_title),
                'description' => sanitize_textarea_field($item->post_content),
                'price' => (float) get_post_meta($item->ID, 'tuc_prix', true),
                'promo_pct' => max(0, min(100, (int) get_post_meta($item->ID, 'tuc_promo_pct', true))),
                'available' => get_post_meta($item->ID, 'tuc_dispo', true) !== '0',
                'options' => $options,
                'photo' => esc_url_raw(get_the_post_thumbnail_url($item->ID, 'tuc-card') ?: ''),
            ];
        }
        return rest_ensure_response([
            'restaurant' => [
                'id' => $restaurant->ID,
                'name' => sanitize_text_field($restaurant->post_title),
                'slug' => $restaurant->post_name,
                'open' => get_post_meta($restaurant->ID, 'tuc_ferme', true) !== '1',
                'minimum' => (float) get_post_meta($restaurant->ID, 'tuc_minimum', true),
                'fee' => (float) get_post_meta($restaurant->ID, 'tuc_frais', true),
                'modes' => array_values(array_intersect((array) get_post_meta($restaurant->ID, 'tuc_modes', true), ['livraison', 'emporter', 'table'])),
                'tables' => max(0, (int) get_post_meta($restaurant->ID, 'tuc_tables', true)),
            ],
            'items' => $menu,
        ]);
    }

    public static function servir_espace_pro(): void
    {
        if ((int) get_query_var('tuc_espace_pro') !== 1) {
            return;
        }
        // L’interface métier est autonome; le back-office WordPress ajouté en surimpression
        // n’y est pas utile et introduit ses propres liens/repères clavier hors du parcours Pro.
        show_admin_bar(false);
        nocache_headers();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/espace-pro/')));
            exit;
        }
        if (!self::permission_menu()) {
            status_header(403);
            include TUC_CORE_DIR . 'includes/vues/espace-pro-interdit.php';
            exit;
        }
        status_header(200);
        include TUC_CORE_DIR . 'includes/vues/espace-pro.php';
        exit;
    }

    public static function redirect_apres_connexion(string $redirectTo, string $requested, $user): string
    {
        if (!$user instanceof WP_User) {
            return $redirectTo;
        }
        $restaurantId = (int) get_user_meta($user->ID, 'tuc_resto_id', true);
        if ($restaurantId <= 0) {
            return $redirectTo;
        }
        if (user_can($user, TUC_Roles::CAP_MANAGE_MENU)
            && TUC_Caisse::utilisateur_autorise_restaurant($user->ID, $restaurantId, TUC_Roles::CAP_MANAGE_MENU)) {
            return home_url('/espace-pro/');
        }
        if (user_can($user, TUC_Roles::CAP_ACCESS_CAISSE)
            && TUC_Caisse::utilisateur_autorise_restaurant($user->ID, $restaurantId, TUC_Roles::CAP_ACCESS_CAISSE)) {
            return home_url('/caisse/');
        }
        return $redirectTo;
    }

    public static function assets(): void
    {
        if ((int) get_query_var('tuc_espace_pro') === 1) {
            wp_enqueue_style('tuc-espace-pro', TUC_CORE_URL . 'assets/pro/espace-pro.css', [], TUC_CORE_VERSION);
            wp_enqueue_script('tuc-espace-pro', TUC_CORE_URL . 'assets/pro/espace-pro.js', [], TUC_CORE_VERSION, true);
            wp_localize_script('tuc-espace-pro', 'TUCPro', [
                'rest' => esc_url_raw(rest_url('tuc/v1/pro/')),
                'nonce' => wp_create_nonce('wp_rest'),
                'site' => esc_url_raw(home_url('/')),
                'canViewOrders' => current_user_can(TUC_Roles::CAP_VIEW_ORDERS) || current_user_can('manage_options'),
                'canUpdateOrders' => current_user_can(TUC_Roles::CAP_UPDATE_ORDERS) || current_user_can('manage_options'),
                'canManageRestaurant' => current_user_can(TUC_Roles::CAP_MANAGE_RESTAURANT) || current_user_can('manage_options'),
            ]);
        }
        if (is_singular(TUC_CPT::RESTO)) {
            $currentCustomer = wp_get_current_user();
            $customerId = $currentCustomer instanceof WP_User
                && in_array(TUC_Compte::ROLE, (array) $currentCustomer->roles, true)
                ? (int) $currentCustomer->ID : 0;
            $savedLocation = null;
            if ($customerId > 0) {
                $savedLat = get_user_meta($customerId, 'tuc_location_lat', true);
                $savedLng = get_user_meta($customerId, 'tuc_location_lng', true);
                $savedAccuracy = get_user_meta($customerId, 'tuc_location_accuracy', true);
                $savedAt = get_user_meta($customerId, 'tuc_location_captured_at', true);
                $savedAtInt = is_numeric($savedAt) ? (int) $savedAt : 0;
                $validLocation = is_numeric($savedLat) && (float) $savedLat >= -90 && (float) $savedLat <= 90
                    && is_numeric($savedLng) && (float) $savedLng >= -180 && (float) $savedLng <= 180
                    && is_numeric($savedAccuracy) && (float) $savedAccuracy > 0
                    && (float) $savedAccuracy <= TUC_Commande::MAX_LOCATION_ACCURACY_METERS
                    && $savedAtInt >= time() - TUC_Commande::MAX_LOCATION_AGE_SECONDS && $savedAtInt <= time() + 300;
                if ($validLocation) {
                    $savedLocation = [
                        'lat' => (float) $savedLat,
                        'lng' => (float) $savedLng,
                        'accuracy' => (float) $savedAccuracy,
                        'capturedAt' => $savedAtInt,
                        'label' => (string) get_user_meta($customerId, 'tuc_location_label', true),
                    ];
                } elseif (metadata_exists('user', $customerId, 'tuc_location_lat') || metadata_exists('user', $customerId, 'tuc_location_lng')) {
                    foreach (['tuc_location_lat', 'tuc_location_lng', 'tuc_location_accuracy', 'tuc_location_captured_at', 'tuc_location_label'] as $metaKey) {
                        delete_user_meta($customerId, $metaKey);
                    }
                }
            }
            wp_enqueue_style('tuc-client-cart', TUC_CORE_URL . 'assets/pro/client-cart.css', [], TUC_CORE_VERSION);
            wp_enqueue_script('tuc-client-cart', TUC_CORE_URL . 'assets/pro/client-cart.js', [], TUC_CORE_VERSION, true);
            wp_localize_script('tuc-client-cart', 'TUCClient', [
                'rest' => esc_url_raw(rest_url('tuc/v1/')),
                'nonce' => wp_create_nonce('wp_rest'),
                'phoneLabel' => __('Téléphone pour le suivi de commande', 'tuc-core'),
                'loggedIn' => is_user_logged_in(),
                'phone' => is_user_logged_in() ? (string) get_user_meta(get_current_user_id(), 'tuc_tel', true) : '',
                'location' => $savedLocation,
            ]);
        }
    }
}
