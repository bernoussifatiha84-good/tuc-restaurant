<?php
/**
 * Module 6 — PWA de caisse : le téléphone/la tablette du comptoir reçoit la
 * commande SANS passer par WhatsApp.
 *
 * POURQUOI CE MODULE EST LE P0
 * ----------------------------
 * Une commande qui n'arrive nulle part n'est pas une commande. Et le calcul de
 * WHATSAPP-COUTS.md §7 est sans appel : porter 1 000 restos × 20 commandes/jour
 * en messages coûte 420 000 MAD/mois. Ici : 0 MAD, et un coût qui ne dépend que
 * du nombre d'appareils abonnés, jamais du nombre de commandes.
 *
 * CE QUE FAIT CE MODULE
 * ---------------------
 *  - sert `/caisse/` (page privée, pleine page, hors du thème) ;
 *  - sert `manifest.webmanifest` + `sw.js` à la racine `/caisse/` : c'est
 *    obligatoire, un service worker ne peut contrôler que son propre dossier ;
 *  - expose les commandes du restaurant à la caisse connectée ;
 *  - enregistre les abonnements Web Push du compte ;
 *  - prévient la caisse dès qu'une commande tombe (`tuc_commande_creee`).
 *
 * CE QU'IL NE FAIT PAS
 * --------------------
 * Pas de paiement. Pas de livraison. Pas de KDS v1 : on affine après les
 * premiers restos réels.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Caisse
{
    /** Capacité dédiée de caisse; les rôles WP génériques ne donnent pas accès. */
    public const CAP = TUC_Roles::CAP_ACCESS_CAISSE;

    public static function peut_acceder(): bool
    {
        return current_user_can('manage_options') || TUC_Acces::peut(self::CAP);
    }

    public static function peut_voir_commandes(): bool
    {
        return current_user_can('manage_options') || TUC_Acces::peut(TUC_Roles::CAP_VIEW_ORDERS);
    }

    public static function peut_modifier_commandes(): bool
    {
        return current_user_can('manage_options') || TUC_Acces::peut(TUC_Roles::CAP_UPDATE_ORDERS);
    }

    public static function peut_gerer_restaurant(): bool
    {
        return current_user_can('manage_options') || TUC_Acces::peut(TUC_Roles::CAP_MANAGE_RESTAURANT);
    }

    /**
     * Bip et vibration pour tout compte de la caisse rattaché au restaurant et
     * porteur de CAP_AUDIBLE_ORDER_ALERTS : restaurateur, gestionnaire, caissier
     * et serveur. Tous les modes (table, emporter, livraison) sont couverts.
     * Un compte non rattaché, ou rattaché à un autre restaurant, reste notifié
     * silencieusement : l'isolation par restaurant n'est pas négociable.
     *
     * @return array{audible:bool,silent:bool,vibrate:array<int,int>}
     */
    public static function profil_alerte(int $user_id, string $mode, int $restaurant_id): array
    {
        // Décision propriétaire : la cuisine et le patron doivent entendre toute
        // nouvelle commande. Le mode ne filtre plus et le rôle n'est plus codé en
        // dur : seule la capacité décide, accordée aux rôles caisse par TUC_Roles.
        // $mode reste dans la signature pour les appelants et le message push.
        // L'isolation par restaurant est conservée : elle est une exigence de
        // sécurité, pas un réglage d'alerte.
        $autorise = $restaurant_id > 0
            && user_can($user_id, TUC_Roles::CAP_AUDIBLE_ORDER_ALERTS)
            && (int) get_user_meta($user_id, 'tuc_resto_id', true) === $restaurant_id;

        return [
            'audible' => $autorise,
            'silent' => !$autorise,
            'vibrate' => $autorise ? [120, 60, 120, 60, 220] : [],
        ];
    }

    /** Vérifie la capacité métier et l'affectation explicite au restaurant. */
    public static function utilisateur_autorise_restaurant(int $user_id, int $restaurant_id, string $capability): bool
    {
        return TUC_Acces::peut($capability, $restaurant_id, $user_id);
    }

    /** Permission de la route historique, désormais limitée au restaurant assigné. */
    public static function permission_statut_commande(?WP_REST_Request $request = null): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        if ($request === null) {
            return false;
        }
        $commande = get_post((int) $request->get_param('id'));
        if (!$commande instanceof WP_Post || $commande->post_type !== TUC_Commande::CPT) {
            return false;
        }
        $restaurant_id = (int) get_post_meta($commande->ID, 'tuc_resto', true);
        return self::utilisateur_autorise_restaurant(
            get_current_user_id(),
            $restaurant_id,
            TUC_Roles::CAP_UPDATE_ORDERS
        );
    }

    public static function init(): void
    {
        add_action('init', [self::class, 'routes_reelles'], 5);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('template_redirect', [self::class, 'servir'], 1);
        add_action('rest_api_init', [self::class, 'rest']);
        add_action('tuc_commande_creee', [self::class, 'prevenir_caisse'], 10, 2);
    }

    // =====================================================================
    // 1. Routage : /caisse/, /caisse/sw.js, /caisse/manifest.webmanifest
    // =====================================================================

    public static function routes_reelles(): void
    {
        add_rewrite_rule('^caisse/?$', 'index.php?tuc_caisse=page', 'top');
        add_rewrite_rule('^caisse/sw\.js$', 'index.php?tuc_caisse=sw', 'top');
        add_rewrite_rule('^caisse/manifest\.webmanifest$', 'index.php?tuc_caisse=manifest', 'top');
        add_rewrite_rule('^caisse/icone-([0-9]+)\.png$', 'index.php?tuc_caisse=icone&tuc_taille=$matches[1]', 'top');
        add_rewrite_rule('^caisse/fonts/([a-z0-9\-]+)\.woff2$', 'index.php?tuc_caisse=font&tuc_fichier=$matches[1]', 'top');
    }

    public static function query_vars(array $vars): array
    {
        $vars[] = 'tuc_caisse';
        $vars[] = 'tuc_taille';
        $vars[] = 'tuc_fichier';
        return $vars;
    }

    /** Le préfixe d'installation (/uat) : indispensable pour les URL du SW. */
    public static function base(): string
    {
        return untrailingslashit((string) parse_url(home_url('/'), PHP_URL_PATH));
    }

    public static function url(): string
    {
        return self::base() . '/caisse/';
    }

    public static function servi(): bool
    {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return false;
        }
        $path = trim((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        $home = trim(self::base(), '/');
        if ($home !== '' && str_starts_with($path, $home)) {
            $path = trim(substr($path, strlen($home)), '/');
        }
        return str_starts_with($path, 'caisse');
    }

    public static function servir(): void
    {
        $quoi = (string) get_query_var('tuc_caisse');
        if ($quoi === '') {
            return;
        }

        switch ($quoi) {
            case 'sw':
                self::servir_sw();
                break;
            case 'manifest':
                self::servir_manifeste();
                break;
            case 'icone':
                self::servir_icone((int) get_query_var('tuc_taille'));
                break;
            case 'font':
                self::servir_police((string) get_query_var('tuc_fichier'));
                break;
            default:
                self::servir_page();
        }
        exit;
    }

    private static function servir_sw(): void
    {
        // Un service worker DOIT être servi en JS et à la racine du scope.
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: ' . self::url());
        header('X-Content-Type-Options: nosniff');
        readfile(TUC_CORE_DIR . 'assets/caisse/sw.js');
    }

    private static function servir_manifeste(): void
    {
        $resto = self::mon_resto();
        $nom = $resto ? $resto->post_title : 'TUC';

        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');

        echo wp_json_encode([
            'name'             => 'Caisse · ' . $nom,
            'short_name'       => 'Caisse TUC',
            'description'      => 'Les commandes de ' . $nom . ' en direct.',
            'lang'             => 'fr',
            'dir'              => 'ltr',
            'start_url'        => self::url() . '?source=pwa',
            'scope'            => self::url(),
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#FFFFFF',
            'theme_color'      => '#0E9E7E',
            'icons'            => [
                ['src' => self::base() . '/caisse/icone-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => self::base() . '/caisse/icone-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => self::base() . '/caisse/icone-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ]);
    }

    private static function servir_icone(int $taille): void
    {
        $taille = in_array($taille, [192, 512], true) ? $taille : 192;
        $fichier = TUC_CORE_DIR . 'assets/caisse/icone-' . $taille . '.png';

        nocache_headers();
        header('Content-Type: image/png');
        if (is_readable($fichier)) {
            header('Cache-Control: public, max-age=2592000');
            readfile($fichier);
        }
    }

    /**
     * Polices servies par le plugin : la caisse ne dépend ni du thème ni d'un
     * CDN. Une caisse qui perd sa police au mauvais moment, c'est une caisse
     * qui devient illisible en plein service.
     */
    private static function servir_police(string $nom): void
    {
        $autorisees = ['manrope', 'plexar-400', 'plexar-700'];
        if (!in_array($nom, $autorisees, true)) {
            status_header(404);
            return;
        }

        $fichier = TUC_CORE_DIR . 'assets/caisse/fonts/' . $nom . '.woff2';
        nocache_headers();
        header('Content-Type: font/woff2');
        if (is_readable($fichier)) {
            header('Cache-Control: public, max-age=31536000, immutable');
            readfile($fichier);
        }
    }

    // =====================================================================
    // 2. Page /caisse/
    // =====================================================================

    private static function servir_page(): void
    {
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');

        $connecte = is_user_logged_in() && self::peut_acceder();

        include TUC_CORE_DIR . 'includes/vues/caisse.php';
    }

    /** Le restaurant de l'utilisateur connecté, ou null. */
    public static function mon_resto(): ?WP_Post
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return null;
        }

        // 1. un resto explicitement rattaché au compte
        $id = (int) get_user_meta($user_id, 'tuc_resto_id', true);
        if ($id > 0) {
            $p = get_post($id);
            $published = $p instanceof WP_Post && $p->post_type === 'tuc_resto' && $p->post_status === 'publish';
            $ownedDraft = $p instanceof WP_Post && $p->post_type === 'tuc_resto' && $p->post_status === 'draft'
                && user_can($user_id, TUC_Roles::CAP_MANAGE_RESTAURANT);
            if ($published || $ownedDraft) {
                return $p;
            }
            return null; // une affectation invalide ne doit jamais retomber sur un autre commerce.
        }

        // Les rôles métier doivent toujours avoir une affectation explicite.
        // Le fallback historique est réservé aux administrateurs globaux.
        if (!user_can($user_id, 'manage_options')) {
            return null;
        }
        $tous = get_posts([
            'post_type'      => 'tuc_resto',
            'post_status'    => 'publish',
            'posts_per_page' => 2,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        if (count($tous) === 1) {
            return get_post((int) $tous[0]);
        }

        return null;
    }

    // =====================================================================
    // 3. REST
    // =====================================================================

    public static function rest(): void
    {
        $auth = static fn(): bool => self::peut_acceder();
        $viewOrders = static fn(): bool => self::peut_voir_commandes();
        $updateOrders = static fn(): bool => self::peut_modifier_commandes();
        $manageRestaurant = static fn(): bool => self::peut_gerer_restaurant();

        register_rest_route('tuc/v1', '/caisse/moi', [
            'methods'             => 'GET',
            'permission_callback' => $auth,
            'callback'            => [self::class, 'moi'],
        ]);

        register_rest_route('tuc/v1', '/caisse/commandes', [
            'methods'             => 'GET',
            'permission_callback' => $viewOrders,
            'callback'            => [self::class, 'commandes'],
        ]);

        register_rest_route('tuc/v1', '/caisse/statut', [
            'methods'             => 'POST',
            'permission_callback' => $updateOrders,
            'callback'            => [self::class, 'statut'],
        ]);

        register_rest_route('tuc/v1', '/caisse/ouverture', [
            'methods'             => 'POST',
            'permission_callback' => $manageRestaurant,
            'callback'            => [self::class, 'ouverture'],
        ]);

        register_rest_route('tuc/v1', '/caisse/push', [
            'methods'             => 'POST',
            'permission_callback' => $auth,
            'callback'            => [self::class, 'push_abonner'],
        ]);

        register_rest_route('tuc/v1', '/caisse/push', [
            'methods'             => 'DELETE',
            'permission_callback' => $auth,
            'callback'            => [self::class, 'push_desabonner'],
        ]);

        register_rest_route('tuc/v1', '/caisse/push-test', [
            'methods'             => 'POST',
            'permission_callback' => $auth,
            'callback'            => [self::class, 'push_test'],
        ]);
    }

    public static function moi(): WP_REST_Response
    {
        $resto = self::mon_resto();
        return new WP_REST_Response([
            'resto'       => $resto ? [
                'id'     => $resto->ID,
                'nom'    => $resto->post_title,
                'slug'   => $resto->post_name,
                'table'  => get_post_meta($resto->ID, 'tuc_tables', true) ?: 0,
                'ferme'  => get_post_meta($resto->ID, 'tuc_ferme', true) === '1',
            ] : null,
            'appareils'   => TUC_Push::nb_appareils(get_current_user_id()),
            'alerte_sonore' => $resto instanceof WP_Post
                ? self::profil_alerte(get_current_user_id(), 'table', $resto->ID)['audible']
                : false,
            'vapid'       => TUC_Push::cle_publique(),
            'sw'          => self::base() . '/caisse/sw.js',
            'nonce'       => wp_create_nonce('wp_rest'),
            'maintenant'  => current_time('H:i'),
        ]);
    }

    /** La liste des commandes, filtrée par statut. */
    public static function commandes(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::peut_voir_commandes()) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        $resto = self::mon_resto();
        if ($resto === null) {
            return new WP_REST_Response(['ok' => false, 'erreur' => 'Aucun restaurant rattaché à ce compte.'], 409);
        }

        $statut = sanitize_key((string) $req->get_param('statut'));
        $meta   = [];
        if ($statut !== '' && $statut !== 'toutes') {
            $meta[] = ['key' => 'tuc_statut', 'value' => $statut];
        }

        $posts = get_posts([
            'post_type'      => TUC_Commande::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 40,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array_merge(
                [['key' => 'tuc_resto', 'value' => $resto->ID, 'compare' => '=', 'type' => 'NUMERIC']],
                $meta
            ),
        ]);

        $maintenant = (int) current_time('timestamp');

        // 'cree' n'est pas un doublon de 'age' : la page a besoin d'un instant
        // ABSOLU pour decider si une commande appartient a la fenetre qu'elle
        // n'a pas vue. Un age relatif recalcule a chaque reponse ne le permet
        // pas (il derive avec l'heure du navigateur et l'aller-retour reseau).
        $sortie = [];

        foreach ($posts as $p) {
            $table = (int) get_post_meta($p->ID, 'tuc_table', true);
            $mode = sanitize_key((string) get_post_meta($p->ID, 'tuc_mode', true));
            if (!in_array($mode, ['table', 'livraison', 'emporter'], true)) {
                $mode = $table > 0 ? 'table' : ((int) get_post_meta($p->ID, 'tuc_frais', true) > 0 ? 'livraison' : 'emporter');
            }

            $sortie[] = [
                'id'        => $p->ID,
                'titre'     => $p->post_title,
                'table'     => $table,
                'mode'      => $mode,
                'tel'       => (string) get_post_meta($p->ID, 'tuc_tel', true),
                'lat'       => is_numeric(get_post_meta($p->ID, 'tuc_location_lat', true)) ? (float) get_post_meta($p->ID, 'tuc_location_lat', true) : null,
                'lng'       => is_numeric(get_post_meta($p->ID, 'tuc_location_lng', true)) ? (float) get_post_meta($p->ID, 'tuc_location_lng', true) : null,
                'location_url' => is_numeric(get_post_meta($p->ID, 'tuc_location_lat', true))
                    && is_numeric(get_post_meta($p->ID, 'tuc_location_lng', true))
                    ? 'https://maps.google.com/?q=' . rawurlencode(
                        (string) get_post_meta($p->ID, 'tuc_location_lat', true) . ',' . (string) get_post_meta($p->ID, 'tuc_location_lng', true)
                    )
                    : '',
                'items'     => (array) get_post_meta($p->ID, 'tuc_items', true),
                'sous_total' => (float) get_post_meta($p->ID, 'tuc_sous_total', true),
                'frais'     => (float) get_post_meta($p->ID, 'tuc_frais', true),
                'total'     => (float) get_post_meta($p->ID, 'tuc_total', true),
                'statut'    => (string) get_post_meta($p->ID, 'tuc_statut', true),
                'heure'     => get_the_date('H:i', $p),
                'cree'      => (int) strtotime($p->post_date),
                'age'       => max(0, $maintenant - strtotime($p->post_date)),
            ];
        }

        // 'maintenant' (instant serveur) permet a la page de calculer sa fenetre
        // de reprise sans dependre de l'horloge de la tablette, qui peut deriver
        // de plusieurs minutes. C'est la meme reference que 'age'.
        return new WP_REST_Response([
            'ok'         => true,
            'commandes'  => $sortie,
            'nb'         => count($sortie),
            'maintenant' => $maintenant,
        ], 200);
    }

    /** Changement de statut depuis la caisse, limité au restaurant assigné. */
    public static function statut(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::peut_modifier_commandes()) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        $id    = (int) $req->get_param('id');
        $vers  = sanitize_key((string) $req->get_param('statut'));
        $resto = self::mon_resto();

        $commande = get_post($id);
        if (!$commande instanceof WP_Post || $commande->post_type !== TUC_Commande::CPT) {
            return new WP_REST_Response(['ok' => false, 'erreur' => 'Commande inconnue.'], 404);
        }
        if ($resto === null || (int) get_post_meta($id, 'tuc_resto', true) !== $resto->ID) {
            return new WP_REST_Response(['ok' => false, 'erreur' => 'Cette commande n\'est pas la vôtre.'], 403);
        }
        if (!in_array($vers, TUC_Commande::STATUTS, true)) {
            return new WP_REST_Response(['ok' => false, 'erreur' => 'Statut inconnu.'], 400);
        }

        $histo   = (array) get_post_meta($id, 'tuc_historique', true);
        $histo[] = [$vers, current_time('c'), get_current_user_id()];

        update_post_meta($id, 'tuc_statut', $vers);
        update_post_meta($id, 'tuc_statut_updated_at', current_time('mysql', true));
        update_post_meta($id, 'tuc_historique', $histo);

        // le client suit l'état sur sa page : rien à pousser côté WhatsApp
        do_action('tuc_commande_statut', $id, $vers);

        return new WP_REST_Response(['ok' => true, 'statut' => $vers], 200);
    }

    /** Pause / reprise : le restaurant arrête de prendre des commandes en un tap. */
    public static function ouverture(WP_REST_Request $req): WP_REST_Response
    {
        if (!self::peut_gerer_restaurant()) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        $resto = self::mon_resto();
        if ($resto === null) {
            return new WP_REST_Response(['ok' => false], 409);
        }
        $ferme = (bool) $req->get_param('ferme');
        update_post_meta($resto->ID, 'tuc_ferme', $ferme ? '1' : '0');

        return new WP_REST_Response(['ok' => true, 'ferme' => $ferme], 200);
    }

    public static function push_abonner(WP_REST_Request $req): WP_REST_Response
    {
        $ok = TUC_Push::abonner(
            get_current_user_id(),
            (string) $req->get_param('endpoint'),
            (string) $req->get_param('p256dh'),
            (string) $req->get_param('auth')
        );

        return new WP_REST_Response([
            'ok'        => $ok,
            'appareils' => TUC_Push::nb_appareils(get_current_user_id()),
        ], $ok ? 201 : 400);
    }

    public static function push_desabonner(WP_REST_Request $req): WP_REST_Response
    {
        TUC_Push::desabonner(get_current_user_id(), (string) $req->get_param('endpoint'));
        return new WP_REST_Response([
            'ok'        => true,
            'appareils' => TUC_Push::nb_appareils(get_current_user_id()),
        ], 200);
    }

    public static function push_test(): WP_REST_Response
    {
        $resto = self::mon_resto();
        $profil = self::profil_alerte(
            get_current_user_id(),
            'table',
            $resto instanceof WP_Post ? $resto->ID : 0
        );
        $bilan = TUC_Push::envoyer(get_current_user_id(), [
            'titre' => $profil['audible'] ? 'Test sonore TUC' : 'Test de notification TUC',
            'texte' => $profil['audible']
                ? 'Vérification du bip et de la vibration pour la caisse.'
                : 'Notification silencieuse de contrôle pour la caisse.',
            'url'   => self::url(),
            'tag'   => 'tuc-test',
            'silent' => $profil['silent'],
            'vibrate' => $profil['vibrate'],
            'requireInteraction' => true,
        ]);

        return new WP_REST_Response([
            'ok'        => $bilan['envoyes'] > 0,
            'envoyes'   => $bilan['envoyes'],
            'morts'     => $bilan['morts'],
            'erreurs'   => $bilan['erreurs'],
        ], 200);
    }

    // =====================================================================
    // 4. Le moment qui compte : une commande tombe
    // =====================================================================

    /**
     * Branchement sur `tuc_commande_creee` (déjà émis par TUC_Commande::creer).
     * C'est la seule notification qui sort du site. Coût : 0 MAD.
     */
    public static function prevenir_caisse($commande_id, $lignes = []): void
    {
        $commande_id = (int) $commande_id;
        $commande = get_post($commande_id);
        if (!$commande instanceof WP_Post) {
            return;
        }

        $resto_id = (int) get_post_meta($commande_id, 'tuc_resto', true);
        $table    = (int) get_post_meta($commande_id, 'tuc_table', true);
        $total    = (float) get_post_meta($commande_id, 'tuc_total', true);
        $items    = (array) get_post_meta($commande_id, 'tuc_items', true);
        $mode = sanitize_key((string) get_post_meta($commande_id, 'tuc_mode', true));
        if (!in_array($mode, ['table', 'livraison', 'emporter'], true)) {
            $mode = $table > 0 ? 'table' : ((int) get_post_meta($commande_id, 'tuc_frais', true) > 0 ? 'livraison' : 'emporter');
        }
        $ou = $mode === 'table' ? 'Table ' . $table : ($mode === 'livraison' ? 'Livraison' : 'À emporter');
        $nb = 0;
        foreach ($items as $l) {
            $nb += (int) ($l['q'] ?? 1);
        }

        $charge = [
            'titre'            => 'Nouvelle commande · ' . $ou,
            'texte'            => number_format($total, 0, ',', ' ') . ' MAD · ' . $nb . ' article' . ($nb > 1 ? 's' : ''),
            'url'              => self::url() . '?c=' . $commande_id,
            'tag'              => 'tuc-cmd-' . $commande_id,
            'commande'         => $commande_id,
            'requireInteraction' => true,
            'vibrate'          => [120, 60, 120, 60, 220],
        ];

        foreach (self::comptes_du_resto($resto_id) as $user_id) {
            $profil = self::profil_alerte($user_id, $mode, $resto_id);
            $chargePourCompte = $charge;
            $chargePourCompte['silent'] = $profil['silent'];
            $chargePourCompte['vibrate'] = $profil['vibrate'];
            if (!$profil['audible']) {
                $chargePourCompte['texte'] .= ' · copie silencieuse';
            }
            TUC_Push::envoyer($user_id, $chargePourCompte);
        }

        /** Point d'extension : n8n, imprimante, KDS v2… plus tard, sans toucher ici. */
        do_action('tuc_caisse_prevenue', $commande_id, $charge);
    }

    /** Qui doit être prévenu pour ce restaurant. */
    private static function comptes_du_resto(int $resto_id): array
    {
        $ids = [];

        // gérants explicitement rattachés
        $users = get_users([
            'meta_key'   => 'tuc_resto_id',
            'meta_value' => $resto_id,
            'fields'     => 'ID',
            'number'     => 0,
        ]);
        foreach ($users as $u) {
            $user_id = (int) $u;
            if ((int) get_user_meta($user_id, 'tuc_resto_id', true) === $resto_id
                && user_can($user_id, TUC_Roles::CAP_ACCESS_CAISSE)
                && user_can($user_id, TUC_Roles::CAP_VIEW_ORDERS)) {
                $ids[] = $user_id;
            }
        }

        // au lancement : un seul resto, donc les admins du site sont la caisse
        if ($ids === []) {
            $restos = get_posts(['post_type' => 'tuc_resto', 'post_status' => 'publish', 'posts_per_page' => 2, 'fields' => 'ids', 'no_found_rows' => true]);
            if (count($restos) === 1) {
                $admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 5]);
                foreach ($admins as $u) {
                    $ids[] = (int) $u;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
