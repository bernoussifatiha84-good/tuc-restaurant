<?php
/**
 * Module 4 — REST `POST /tuc/v1/commande`.
 *
 * AUDIT : la première version faisait confiance au client pour le total.
 * `{"items":[…],"total":1}` créait une commande à 1 MAD. Corrigé ici :
 *  - le serveur RECALCULE chaque ligne depuis le CPT (prix + options)
 *  - un écart avec le total annoncé renvoie 409, avec le vrai total
 *  - clé d'idempotence : un double-tap ne crée pas deux commandes
 *  - limitation : 5 commandes / 10 min / téléphone
 *  - le restaurant doit être ouvert, sinon 423
 *
 * Pas de paiement en ligne : la commande est transmise, le client paie en cash.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Commande
{
    public const CPT = 'tuc_commande';
    public const STATUTS = ['nouvelle', 'acceptee', 'preparation', 'prete', 'servie', 'refusee', 'annulee'];

    public static function init(): void
    {
        add_action('init', [self::class, 'register'], 5);
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function register(): void
    {
        register_post_type(self::CPT, [
            'labels'       => ['name' => __('Commandes', 'tuc-core')],
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => false,
            'supports'     => ['title'],
            'menu_icon'    => 'dashicons-clipboard',
        ]);
    }

    public static function routes(): void
    {
        register_rest_route('tuc/v1', '/commande', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'creer'],
        ]);
        register_rest_route('tuc/v1', '/commande/suivi', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'suivre'],
        ]);

        // Route historique : les rôles métier sont filtrés par capacité et
        // affectation restaurant dans la permission et dans le callback.
        register_rest_route('tuc/v1', '/commande/(?P<id>\d+)/statut', [
            'methods'             => 'POST',
            'permission_callback' => [TUC_Caisse::class, 'permission_statut_commande'],
            'callback'            => [self::class, 'changer_statut'],
        ]);
    }

    private static function nouveau_jeton_suivi(int $order_id, string $idempotency_key): string
    {
        $digest = hash_hmac('sha256', 'tuc-order-track|' . $order_id . '|' . $idempotency_key, wp_salt('auth'), true);
        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    /** Prix serveur (promotion + suppléments validés), jamais celui du client. */
    public static function details_prix(int $item_id, array $options = []): array|WP_Error
    {
        $selection = TUC_Options::valider_selection(get_post_meta($item_id, 'tuc_options', true), $options);
        if (is_wp_error($selection)) {
            return $selection;
        }
        $base = max(0.0, (float) get_post_meta($item_id, 'tuc_prix', true));
        $promo = max(0, min(100, (int) get_post_meta($item_id, 'tuc_promo_pct', true)));
        $prixApresPromo = round($base * (100 - $promo) / 100, 2);

        return [
            'list_price' => round($base, 2),
            'promo_pct' => $promo,
            'unit_price' => round($prixApresPromo + $selection['surcharge'], 2),
            'options' => $selection,
        ];
    }

    public static function prix_unite(int $item_id, array $options = []): float|WP_Error
    {
        $details = self::details_prix($item_id, $options);
        return is_wp_error($details) ? $details : $details['unit_price'];
    }

    public static function creer(WP_REST_Request $req)
    {
        $slugParam = $req->get_param('resto');
        $tableParam = $req->get_param('table');
        $modeParam = $req->get_param('mode');
        $phoneParam = $req->get_param('tel');
        $totalParam = $req->get_param('total');
        $latitudeParam = $req->get_param('lat');
        $longitudeParam = $req->get_param('lng');
        $resto_slug = is_scalar($slugParam) ? sanitize_title((string) $slugParam) : '';
        $table      = is_scalar($tableParam) ? absint($tableParam) : 0;
        $mode       = is_scalar($modeParam) ? sanitize_key((string) $modeParam) : '';
        $telBrut    = is_scalar($phoneParam) ? preg_replace('/[^0-9+]/', '', (string) $phoneParam) : '';
        $tel        = TUC_Compte::normaliser($telBrut);
        $annonce    = is_numeric($totalParam) ? (float) $totalParam : 0.0;
        $items      = json_decode((string) wp_json_encode($req->get_param('items')), true) ?: [];

        // ---- 1. le restaurant existe et il est ouvert ----
        $resto = get_page_by_path($resto_slug, OBJECT, 'tuc_resto');
        if (!$resto instanceof WP_Post || $resto->post_status !== 'publish') {
            return new WP_Error('tuc_resto', 'Restaurant inconnu.', ['status' => 404]);
        }
        if (get_post_meta($resto->ID, 'tuc_ferme', true) === '1') {
            return new WP_Error('tuc_ferme', 'Le restaurant est fermé.', ['status' => 423]);
        }
        $modesBruts = array_filter((array) get_post_meta($resto->ID, 'tuc_modes', true), 'is_scalar');
        $modesAutorises = array_values(array_intersect(
            array_map(static fn($modeAutorise): string => sanitize_key((string) $modeAutorise), $modesBruts),
            ['livraison', 'emporter', 'table']
        ));
        if ($mode === '') {
            if ($table > 0) {
                $mode = 'table';
            } elseif (in_array('livraison', $modesAutorises, true)) {
                $mode = 'livraison';
            } elseif (in_array('emporter', $modesAutorises, true)) {
                $mode = 'emporter';
            } elseif ($modesAutorises !== []) {
                $mode = $modesAutorises[0];
            } else {
                $mode = (float) get_post_meta($resto->ID, 'tuc_frais', true) > 0 ? 'livraison' : 'emporter';
            }
        }
        $nbTables = max(0, (int) get_post_meta($resto->ID, 'tuc_tables', true));
        if (!in_array($mode, ['livraison', 'emporter', 'table'], true)
            || ($modesAutorises !== [] && !in_array($mode, $modesAutorises, true))
            || ($mode === 'table' && ($table < 1 || $table > $nbTables))
            || ($mode !== 'table' && $table !== 0)) {
            return new WP_Error('tuc_mode', 'Mode de service ou numéro de table invalide.', ['status' => 422]);
        }
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $tel)) {
            return new WP_Error('tuc_tel', 'Numéro de téléphone invalide.', ['status' => 422]);
        }
        $currentUser = wp_get_current_user();
        if ($currentUser instanceof WP_User && in_array(TUC_Compte::ROLE, (array) $currentUser->roles, true)) {
            $verifiedPhone = TUC_Compte::normaliser((string) get_user_meta($currentUser->ID, 'tuc_tel', true));
            if ($verifiedPhone !== '' && !hash_equals($verifiedPhone, $tel)) {
                return new WP_Error('tuc_tel_compte', 'Le téléphone doit correspondre au numéro WhatsApp vérifié du compte.', ['status' => 403]);
            }
        }
        $latitude = null;
        $longitude = null;
        $hasLatitude = $req->has_param('lat');
        $hasLongitude = $req->has_param('lng');
        if ($mode === 'livraison' && (!$hasLatitude || !$hasLongitude)) {
            return new WP_Error('tuc_location', 'La position est obligatoire pour confirmer une livraison.', ['status' => 422]);
        }
        if ($hasLatitude || $hasLongitude) {
            if ($mode !== 'livraison' || !$hasLatitude || !$hasLongitude
                || !is_numeric($latitudeParam) || !is_numeric($longitudeParam)
                || !is_finite((float) $latitudeParam) || !is_finite((float) $longitudeParam)
                || (float) $latitudeParam < -90 || (float) $latitudeParam > 90
                || (float) $longitudeParam < -180 || (float) $longitudeParam > 180) {
                return new WP_Error('tuc_location', 'Position de livraison invalide.', ['status' => 422]);
            }
            $latitude = round((float) $latitudeParam, 6);
            $longitude = round((float) $longitudeParam, 6);
        }

        if (!is_array($items) || $items === [] || count($items) > 50) {
            return new WP_Error('tuc_vide', 'Panier vide ou trop volumineux.', ['status' => 400]);
        }

        // ---- 2. idempotence : un double-tap ne crée pas deux commandes ----
        $cleParam = $req->get_param('cle');
        if ($cleParam !== null && !is_scalar($cleParam)) {
            return new WP_Error('tuc_cle', 'Clé de commande invalide.', ['status' => 422]);
        }
        $cle = is_scalar($cleParam) ? (string) $cleParam : '';
        if ($cle !== '' && !preg_match('/^[A-Za-z0-9._-]{24,64}$/', $cle)) {
            return new WP_Error('tuc_cle', 'Clé de commande invalide.', ['status' => 422]);
        }
        if ($cle !== '') {
            $deja = get_posts([
                'post_type'      => self::CPT,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [['key' => 'tuc_cle', 'value' => $cle]],
            ]);
            if ($deja) {
                $existingOrderId = (int) $deja[0];
                if ((int) get_post_meta($existingOrderId, 'tuc_resto', true) !== $resto->ID
                    || !hash_equals((string) get_post_meta($existingOrderId, 'tuc_tel', true), $tel)) {
                    return new WP_Error('tuc_cle', 'Clé de commande déjà utilisée.', ['status' => 409]);
                }
                $trackingToken = self::nouveau_jeton_suivi($existingOrderId, $cle);
                update_post_meta($existingOrderId, 'tuc_suivi_hash', hash('sha256', $trackingToken));
                update_post_meta($existingOrderId, 'tuc_suivi_expire', time() + 30 * DAY_IN_SECONDS);
                return new WP_REST_Response([
                    'ok' => true,
                    'commande' => (int) $deja[0],
                    'doublon' => true,
                    'suivi' => $trackingToken,
                ], 200);
            }
        }

        // ---- 3. recalcul serveur : le client ne fixe pas les prix ----
        $lignes = [];
        $total = 0.0;

        foreach ($items as $ligne) {
            $item_id = (int) ($ligne['id'] ?? 0);
            $qte     = max(1, min(50, (int) ($ligne['q'] ?? 1)));
            $opts    = (array) ($ligne['options'] ?? []);
            if (count($opts) > 20) {
                return new WP_Error('tuc_options', 'Trop d’options pour un article.', ['status' => 422]);
            }

            $plat = get_post($item_id);
            if (!$plat instanceof WP_Post || $plat->post_type !== 'tuc_menu_item' || $plat->post_status !== 'publish') {
                return new WP_Error('tuc_item', 'Article inconnu ou non publié : ' . $item_id, ['status' => 422]);
            }
            if (get_post_meta($item_id, 'tuc_dispo', true) === '0') {
                return new WP_Error('tuc_item_indisponible', 'Cet article est indisponible.', ['status' => 422]);
            }
            // le plat doit appartenir à CE restaurant
            if ((int) get_post_meta($item_id, 'tuc_resto_id', true) !== $resto->ID) {
                return new WP_Error('tuc_item_resto', 'Article hors du menu de ce restaurant.', ['status' => 422]);
            }

            $prix = self::details_prix($item_id, $opts);
            if (is_wp_error($prix)) {
                return $prix;
            }
            $total += $prix['unit_price'] * $qte;

            $lignes[] = [
                'id'          => $item_id,
                'nom'         => $plat->post_title,
                'q'           => $qte,
                'pu'          => $prix['unit_price'],
                'prix_avant_promo' => $prix['list_price'],
                'promo_pct'   => $prix['promo_pct'],
                'options'     => $prix['options']['selections'],
                'opts'        => array_map('sanitize_text_field', $prix['options']['labels']),
            ];
        }

        // Les frais ne s’appliquent qu’à la livraison, jamais à emporter ou à table.
        $frais = $mode === 'livraison' ? max(0.0, (float) get_post_meta($resto->ID, 'tuc_frais', true)) : 0.0;
        $minimum = (float) get_post_meta($resto->ID, 'tuc_minimum', true);

        if ($minimum > 0 && $total < $minimum) {
            return new WP_Error(
                'tuc_minimum',
                sprintf('Le panier doit atteindre %s MAD.', number_format($minimum, 2, ',', ' ')),
                ['status' => 422, 'manque' => $minimum - $total]
            );
        }

        $du = $total + $frais;

        // ---- 5. écart de total : on refuse et on renvoie le vrai prix ----
        if ($annonce > 0 && abs($du - $annonce) > 0.01) {
            return new WP_Error('tuc_total', 'Le total a changé.', [
                'status'       => 409,
                'total_serveur' => $du,
                'frais'         => $frais,
            ]);
        }

        // ---- 6. anti-abus après validation, par téléphone normalisé et IP ----
        // Les erreurs de panier/prix ne peuvent plus consommer le quota d'un tiers.
        $limites = [['tuc_rl_' . md5($tel), 5]];
        $ip = filter_var((string) ($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);
        if ($ip !== false) {
            $limites[] = ['tuc_rl_ip_' . md5($ip), 20];
        }
        foreach ($limites as [$cleLimite, $maximum]) {
            if ((int) get_transient($cleLimite) >= $maximum) {
                return new WP_Error('tuc_trop', 'Trop de commandes. Réessayez dans quelques minutes.', ['status' => 429]);
            }
        }
        $lockOption = $cle !== '' ? 'tuc_order_lock_' . md5($resto->ID . '|' . $cle) : '';
        $lockAcquired = $lockOption === '' || add_option($lockOption, time(), '', false);
        if (!$lockAcquired) {
            $lockedSince = (int) get_option($lockOption, 0);
            if ($lockedSince > 0 && time() - $lockedSince > 30) {
                delete_option($lockOption);
                $lockAcquired = add_option($lockOption, time(), '', false);
            }
        }
        if (!$lockAcquired) {
            return new WP_Error('tuc_commande_en_cours', 'Cette commande est en cours de confirmation. Réessayez.', ['status' => 409]);
        }
        foreach ($limites as [$cleLimite, $maximum]) {
            set_transient($cleLimite, (int) get_transient($cleLimite) + 1, 10 * MINUTE_IN_SECONDS);
        }

        // ---- 7. création ----
        $id = wp_insert_post([
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => sprintf('%s — %s', $resto->post_title, gmdate('d/m H:i')),
            'meta_input'  => [
                'tuc_resto'  => $resto->ID,
                'tuc_table'  => $table,
                'tuc_mode'   => $mode,
                'tuc_items'  => $lignes,
                'tuc_sous_total' => $total,
                'tuc_frais'  => $frais,
                'tuc_total'  => $du,
                'tuc_tel'    => $tel,
                'tuc_statut' => 'nouvelle',
                'tuc_statut_updated_at' => current_time('mysql', true),
                'tuc_cle'    => $cle,
                'tuc_historique' => [['nouvelle', current_time('c')]],
            ],
        ], true);

        if (is_wp_error($id)) {
            if ($lockOption !== '') {
                delete_option($lockOption);
            }
            return $id;
        }
        if ($latitude !== null && $longitude !== null) {
            update_post_meta((int) $id, 'tuc_location_lat', $latitude);
            update_post_meta((int) $id, 'tuc_location_lng', $longitude);
            $customer = wp_get_current_user();
            if ($customer instanceof WP_User
                && in_array(TUC_Compte::ROLE, (array) $customer->roles, true)
                && TUC_Compte::normaliser((string) get_user_meta($customer->ID, 'tuc_tel', true)) === $tel) {
                update_user_meta($customer->ID, 'tuc_location_lat', $latitude);
                update_user_meta($customer->ID, 'tuc_location_lng', $longitude);
                update_user_meta($customer->ID, 'tuc_location_label', 'Position enregistrée pour les prochaines livraisons');
            }
        }
        $trackingToken = self::nouveau_jeton_suivi((int) $id, $cle);
        update_post_meta((int) $id, 'tuc_suivi_hash', hash('sha256', $trackingToken));
        update_post_meta((int) $id, 'tuc_suivi_expire', time() + 30 * DAY_IN_SECONDS);
        if ($lockOption !== '') {
            delete_option($lockOption);
        }

        /** Module 6 : Web Push vers le téléphone de la caisse. */
        do_action('tuc_commande_creee', $id, $lignes);

        return new WP_REST_Response([
            'ok'     => true,
            'commande' => $id,
            'total'  => $du,
            'frais'  => $frais,
            'suivi' => $trackingToken,
        ], 201);
    }

    /** Suivi public par jeton secret, sans exposer téléphone, lignes ou total. */
    public static function suivre(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ip = filter_var((string) ($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);
        $rateKey = 'tuc_order_track_rl_' . md5($ip !== false ? $ip : 'unknown');
        $count = (int) get_transient($rateKey);
        if ($count >= 600) {
            return new WP_Error('tuc_suivi_rate', 'Trop de vérifications. Réessayez dans quelques minutes.', ['status' => 429]);
        }
        set_transient($rateKey, $count + 1, 10 * MINUTE_IN_SECONDS);

        $token = $request->get_param('jeton');
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return new WP_Error('tuc_suivi', 'Lien de suivi invalide ou expiré.', ['status' => 404]);
        }
        $hash = hash('sha256', $token);
        $matches = get_posts([
            'post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 1,
            'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => 'tuc_suivi_hash', 'value' => $hash, 'compare' => '=']],
        ]);
        if ($matches === []) {
            return new WP_Error('tuc_suivi', 'Lien de suivi invalide ou expiré.', ['status' => 404]);
        }
        $orderId = (int) $matches[0];
        if ((int) get_post_meta($orderId, 'tuc_suivi_expire', true) < time()) {
            return new WP_Error('tuc_suivi', 'Lien de suivi invalide ou expiré.', ['status' => 410]);
        }
        $restaurant = get_post((int) get_post_meta($orderId, 'tuc_resto', true));
        $status = sanitize_key((string) get_post_meta($orderId, 'tuc_statut', true));
        $statusLabels = [
            'nouvelle' => 'Reçue', 'acceptee' => 'Acceptée', 'preparation' => 'En préparation',
            'prete' => 'Prête', 'servie' => 'Servie', 'refusee' => 'Refusée', 'annulee' => 'Annulée',
        ];
        $history = [];
        foreach ((array) get_post_meta($orderId, 'tuc_historique', true) as $entry) {
            if (!is_array($entry) || !isset($entry[0], $entry[1])) {
                continue;
            }
            $entryStatus = sanitize_key((string) $entry[0]);
            if (isset($statusLabels[$entryStatus])) {
                $history[] = ['status' => $entryStatus, 'label' => $statusLabels[$entryStatus], 'at' => sanitize_text_field((string) $entry[1])];
            }
        }
        $response = new WP_REST_Response([
            'reference' => $orderId,
            'restaurant' => $restaurant instanceof WP_Post ? sanitize_text_field($restaurant->post_title) : 'Restaurant TUC',
            'status' => isset($statusLabels[$status]) ? $status : 'nouvelle',
            'label' => $statusLabels[$status] ?? 'Reçue',
            'updated_at' => (string) get_post_meta($orderId, 'tuc_statut_updated_at', true),
            'history' => $history,
        ], 200);
        $response->header('Cache-Control', 'no-store, private, max-age=0');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    /** Transitions contrôlées : pas de statut libre. */
    public static function changer_statut(WP_REST_Request $req)
    {
        $id = (int) $req->get_param('id');
        $vers = sanitize_key((string) $req->get_param('statut'));
        $commande = get_post($id);
        if (!$commande instanceof WP_Post || $commande->post_type !== self::CPT) {
            return new WP_Error('tuc_commande', 'Commande inconnue.', ['status' => 404]);
        }
        $restaurant_id = (int) get_post_meta($id, 'tuc_resto', true);
        if (!TUC_Caisse::utilisateur_autorise_restaurant(
            get_current_user_id(),
            $restaurant_id,
            TUC_Roles::CAP_UPDATE_ORDERS
        )) {
            return new WP_Error('rest_forbidden', 'Cette commande n’appartient pas à votre restaurant.', ['status' => 403]);
        }

        if (!in_array($vers, self::STATUTS, true)) {
            return new WP_Error('tuc_statut', 'Statut inconnu.', ['status' => 400]);
        }

        $histo = (array) get_post_meta($id, 'tuc_historique', true);
        $histo[] = [$vers, current_time('c')];

        update_post_meta($id, 'tuc_statut', $vers);
        update_post_meta($id, 'tuc_statut_updated_at', current_time('mysql', true));
        update_post_meta($id, 'tuc_historique', $histo);
        do_action('tuc_commande_statut', $id, $vers);

        return new WP_REST_Response(['ok' => true, 'statut' => $vers], 200);
    }
}
