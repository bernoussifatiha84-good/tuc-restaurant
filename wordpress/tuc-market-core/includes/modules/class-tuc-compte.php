<?php
/**
 * Module 2b — Comptes client par numéro WhatsApp. Pas d'e-mail, pas de nom.
 *
 * Principe : l'identité, c'est le numéro qui a ENVOYÉ le message.
 * On ne peut donc pas créer un compte pour un numéro qu'on ne contrôle pas.
 *
 * Deux modes :
 *   - `magie`   : lien à usage unique, 15 min (par défaut)
 *   - `motdepasse` : mot de passe généré, à changer au premier accès
 *
 * Aucune donnée demandée : ni nom, ni e-mail, ni date de naissance.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Compte
{
    public const ROLE = 'tuc_client';

    public static function init(): void
    {
        add_action('init', [self::class, 'role']);
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function role(): void
    {
        if (get_role(self::ROLE) === null) {
            add_role(self::ROLE, 'Client TUC', ['read' => true]);
        }
    }

    /** Limiteur simple pour les routes publiques qui créent du travail/état. */
    private static function limiter_anonyme(string $bucket, int $maximum, int $fenetre): bool
    {
        $ip = filter_var((string) ($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);
        if ($ip === false) {
            return true; // WP-CLI/tests sans requête HTTP; en HTTP le serveur fournit REMOTE_ADDR.
        }
        $cle = 'tuc_public_' . sanitize_key($bucket) . '_' . md5($ip);
        $n = (int) get_transient($cle);
        if ($n >= $maximum) {
            return false;
        }
        set_transient($cle, $n + 1, $fenetre);
        return true;
    }

    /** Le téléphone est la clé. Normalisé au format international sans « + ». */
    public static function normaliser(string $tel): string
    {
        $t = preg_replace('/[^0-9]/', '', $tel);
        if (str_starts_with($t, '00')) {
            $t = substr($t, 2);
        }
        // Maroc : 0612345678 -> 212612345678
        if (str_starts_with($t, '0') && strlen($t) === 10) {
            $t = '212' . substr($t, 1);
        }
        if (str_starts_with($t, '212') === false && strlen($t) === 9) {
            $t = '212' . $t;
        }
        return $t;
    }

    /** Trouve l'utilisateur par numéro, ou null. */
    public static function par_telephone(string $tel): ?WP_User
    {
        $t = self::normaliser($tel);
        if ($t === '') {
            return null;
        }
        $q = new WP_User_Query([
            'meta_key'   => 'tuc_tel',
            'meta_value' => $t,
            'number'     => 1,
            'fields'     => 'all',
        ]);
        $u = $q->get_results();
        return $u[0] ?? null;
    }

    /**
     * Crée le compte s'il n'existe pas. Retourne [user, cree].
     * Le mot de passe n'est JAMAIS envoyé en clair par défaut :
     * on renvoie un lien magique à usage unique.
     */
    public static function assurer(string $tel): array
    {
        $t = self::normaliser($tel);
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $t)) {
            return [null, false];
        }
        $existant = self::par_telephone($t);
        if ($existant instanceof WP_User) {
            return [$existant, false];
        }

        // identifiant = le numéro. Pas de nom, pas d'e-mail.
        $login = 'tuc_' . $t;
        $mail  = $t . '@client.tuc.ma'; // interne, jamais utilisé pour écrire

        $uid = wp_insert_user([
            'user_login'   => $login,
            'user_pass'    => wp_generate_password(32, true, true),
            'user_email'   => $mail,
            'display_name' => '+' . $t,
            'role'         => self::ROLE,
        ]);

        if (is_wp_error($uid)) {
            return [null, false];
        }

        update_user_meta($uid, 'tuc_tel', $t);
        update_user_meta($uid, 'tuc_tel_verifie', current_time('c')); // prouvé par l'envoi du message

        return [get_user_by('id', $uid), true];
    }

    /** Lien magique : usage unique, 15 minutes. */
    public static function lien_magique(int $uid): string
    {
        $jeton = wp_generate_password(40, false, false);
        set_transient('tuc_magie_' . hash('sha256', $jeton), $uid, 15 * MINUTE_IN_SECONDS);
        return add_query_arg('tuc_magie', $jeton, home_url('/connexion/'));
    }

    public static function routes(): void
    {
        // ① le navigateur demande un lien wa.me + un code à usage unique
        register_rest_route('tuc/v1', '/wa-lien', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'wa_lien'],
        ]);

        // ② n8n appelle cette route à la réception du message WhatsApp
        register_rest_route('tuc/v1', '/wa-hook', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'verif_secret'],
            'callback'            => [self::class, 'wa_hook'],
        ]);

        // ③ le navigateur vérifie si le compte est prêt
        register_rest_route('tuc/v1', '/wa-statut', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'wa_statut'],
        ]);

        // ③b aperçu du message pré-rempli (panier en texte)
        register_rest_route('tuc/v1', '/wa-panier', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'wa_panier'],
        ]);

        // ④ consommation du lien magique
        register_rest_route('tuc/v1', '/magie', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'consommer_magie'],
        ]);

        // ⑤ changer son mot de passe (connecté)
        register_rest_route('tuc/v1', '/motdepasse', [
            'methods'             => 'POST',
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'callback'            => [self::class, 'changer_mdp'],
        ]);
    }

    public static function verif_secret(WP_REST_Request $req): bool
    {
        $secret = (string) get_option('tuc_wa_secret', '');
        if (strlen($secret) < 32) {
            return false; // secret absent/faible = route fermée
        }

        $timestamp = (string) $req->get_header('x-tuc-timestamp');
        $eventId = (string) $req->get_header('x-tuc-event-id');
        $signature = strtolower((string) $req->get_header('x-tuc-signature'));
        $code = strtoupper(sanitize_text_field((string) $req->get_param('code')));
        $tel = self::normaliser(sanitize_text_field((string) $req->get_param('tel')));

        if (!preg_match('/^[0-9]{10}$/', $timestamp)
            || abs(time() - (int) $timestamp) > 300
            || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $eventId)
            || !preg_match('/^[A-Z0-9]{8}$/', $code)
            || !preg_match('/^[1-9][0-9]{7,14}$/', $tel)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
        ) {
            return false;
        }

        // Contrat signé stable pour l’adaptateur n8n : timestamp, identifiant
        // d’événement, référence et téléphone normalisé, séparés par LF.
        $message = implode("\n", [$timestamp, $eventId, $code, $tel]);
        $expected = hash_hmac('sha256', $message, $secret);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        // Un event_id ne peut être traité qu’une seule fois, même si n8n
        // réessaie le POST avec un nouveau timestamp/signature.
        $replayKey = 'tuc_wa_event_' . hash('sha256', $eventId);
        if (get_transient($replayKey) !== false) {
            return false;
        }
        set_transient($replayKey, 1, 30 * MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Génère l'URL wa.me. Le message est PRÉ-REMPLI, jamais initié par nous.
     * Le code à usage unique lie la session du navigateur au numéro qui écrira.
     */
    public static function wa_lien(WP_REST_Request $req)
    {
        $but   = sanitize_key((string) ($req->get_param('but') ?? 'compte')); // compte | commande
        $resto = sanitize_title((string) ($req->get_param('resto') ?? ''));
        $table = (int) ($req->get_param('table') ?? 0);

        $numero = preg_replace('/[^0-9]/', '', (string) get_option('tuc_wa_numero', ''));
        if ($numero === '') {
            return new WP_Error('tuc_wa', 'Numéro WhatsApp du site non configuré.', ['status' => 503]);
        }
        if (!in_array($but, ['compte', 'commande'], true)) {
            return new WP_Error('tuc_but', 'Objet de demande invalide.', ['status' => 422]);
        }
        if (!self::limiter_anonyme('wa_lien', 20, 10 * MINUTE_IN_SECONDS)) {
            return new WP_Error('tuc_trop', 'Trop de demandes. Réessayez plus tard.', ['status' => 429]);
        }
        if ($but === 'commande') {
            $commerce = get_page_by_path($resto, OBJECT, 'tuc_resto');
            if (!$commerce instanceof WP_Post || $commerce->post_status !== 'publish') {
                return new WP_Error('tuc_resto', 'Restaurant inconnu.', ['status' => 404]);
            }
            if ($table < 0 || ($table > 0 && $table > (int) get_post_meta($commerce->ID, 'tuc_tables', true))) {
                return new WP_Error('tuc_table', 'Table invalide.', ['status' => 422]);
            }
        }

        $code = strtoupper(wp_generate_password(8, false, false));
        // Secret navigateur distinct de la référence envoyée dans WhatsApp :
        // la référence seule ne doit pas suffire à récupérer un lien de session.
        $pollSecret = wp_generate_password(40, false, false);
        set_transient('tuc_wa_' . $code, [
            'but'        => $but,
            'resto'      => $resto,
            'table'      => $table,
            'poll_hash'  => hash('sha256', $pollSecret),
        ], 30 * MINUTE_IN_SECONDS);

        if ($but === 'commande') {
            $texte = sprintf('TUC — JE COMMANDE %s [table %d] ref:%s', $resto, $table, $code);
        } else {
            $texte = sprintf('TUC — JE VEUX CRÉER MON COMPTE ref:%s', $code);
        }

        return new WP_REST_Response([
            'url'   => 'https://wa.me/' . $numero . '?text=' . rawurlencode($texte),
            'code'  => $code,
            'poll'  => $pollSecret,
            'expire' => 30,
        ], 200);
    }

    /**
     * Appelé par n8n quand le message arrive.
     * Le numéro est celui de l'expéditeur — c'est la preuve d'identité.
     */
    public static function wa_hook(WP_REST_Request $req)
    {
        $code = strtoupper(sanitize_text_field((string) $req->get_param('code')));
        $tel  = sanitize_text_field((string) $req->get_param('tel'));

        $ctx = get_transient('tuc_wa_' . $code);
        if ($ctx === false) {
            return new WP_Error('tuc_code', 'Code inconnu ou expiré.', ['status' => 410]);
        }

        $telNormalise = self::normaliser($tel);
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $telNormalise)) {
            return new WP_Error('tuc_tel', 'Numéro WhatsApp invalide.', ['status' => 422]);
        }

        [$user, $cree] = self::assurer($telNormalise);
        if (!$user instanceof WP_User) {
            return new WP_Error('tuc_compte', 'Création du compte impossible.', ['status' => 500]);
        }

        $magie = self::lien_magique($user->ID);

        // le navigateur d'origine pourra récupérer ce lien via /wa-statut
        set_transient('tuc_wa_ok_' . $code, [
            'poll_hash' => (string) ($ctx['poll_hash'] ?? ''),
            'magie'     => $magie,
            'cree'      => $cree,
        ], 30 * MINUTE_IN_SECONDS);
        delete_transient('tuc_wa_' . $code);

        return new WP_REST_Response([
            'ok'      => true,
            'cree'    => $cree,
            'login'   => $user->user_login,
            // n8n renvoie ce texte tel quel dans la conversation (fenêtre 24 h)
            'reponse' => sprintf(
                "Bienvenue chez TUC 👋\n\nIdentifiant : %s\nConnexion directe (valable 15 min) : %s\n\nVous pourrez définir votre mot de passe une fois connecté.",
                $user->user_login,
                $magie
            ),
        ], 201);
    }

    /** Construit un aperçu WhatsApp à partir des prix et options du serveur. */
    public static function wa_panier(WP_REST_Request $req)
    {
        if (!self::limiter_anonyme('wa_panier', 60, 10 * MINUTE_IN_SECONDS)) {
            return new WP_Error('tuc_trop', 'Trop de demandes. Réessayez plus tard.', ['status' => 429]);
        }
        $slug  = sanitize_title((string) $req->get_param('resto'));
        $mode  = sanitize_key((string) ($req->get_param('mode') ?? 'emporter'));
        $table = (int) ($req->get_param('table') ?? 0);
        $entrees = json_decode((string) wp_json_encode($req->get_param('items')), true);
        $resto = get_page_by_path($slug, OBJECT, 'tuc_resto');

        if (!$resto instanceof WP_Post || $resto->post_status !== 'publish') {
            return new WP_Error('tuc_resto', 'Restaurant inconnu.', ['status' => 404]);
        }
        if (get_post_meta($resto->ID, 'tuc_ferme', true) === '1') {
            return new WP_Error('tuc_ferme', 'Le restaurant est fermé.', ['status' => 423]);
        }
        if (!in_array($mode, ['table', 'emporter', 'livraison'], true)) {
            return new WP_Error('tuc_mode', 'Mode de service invalide.', ['status' => 422]);
        }
        $modesAutorises = array_values(array_filter(
            array_map('sanitize_key', (array) get_post_meta($resto->ID, 'tuc_modes', true)),
            static fn(string $modeAutorise): bool => $modeAutorise !== ''
        ));
        if ($modesAutorises !== [] && !in_array($mode, $modesAutorises, true)) {
            return new WP_Error('tuc_mode', 'Ce mode de service n’est pas proposé.', ['status' => 422]);
        }
        $nbTables = max(0, (int) get_post_meta($resto->ID, 'tuc_tables', true));
        if (($mode === 'table' && ($table < 1 || $table > $nbTables)) || ($mode !== 'table' && $table !== 0)) {
            return new WP_Error('tuc_table', 'Table invalide pour ce commerce.', ['status' => 422]);
        }
        if (!is_array($entrees) || $entrees === [] || count($entrees) > 50) {
            return new WP_Error('tuc_vide', 'Panier vide ou trop volumineux.', ['status' => 400]);
        }

        $lignes = [];
        $sousTotal = 0.0;
        foreach ($entrees as $ligne) {
            if (!is_array($ligne)) {
                return new WP_Error('tuc_item', 'Ligne de panier invalide.', ['status' => 422]);
            }
            $itemId = (int) ($ligne['id'] ?? 0);
            $plat = get_post($itemId);
            if (!$plat instanceof WP_Post || $plat->post_type !== 'tuc_menu_item' || $plat->post_status !== 'publish') {
                return new WP_Error('tuc_item', 'Article inconnu ou non publié.', ['status' => 422]);
            }
            if ((int) get_post_meta($itemId, 'tuc_resto_id', true) !== $resto->ID) {
                return new WP_Error('tuc_item_resto', 'Article hors du menu de ce restaurant.', ['status' => 422]);
            }
            if (get_post_meta($itemId, 'tuc_dispo', true) === '0') {
                return new WP_Error('tuc_item_indisponible', 'Cet article est indisponible.', ['status' => 422]);
            }

            $qte = max(1, min(50, (int) ($ligne['q'] ?? 1)));
            $options = (array) ($ligne['options'] ?? []);
            if (count($options) > 20) {
                return new WP_Error('tuc_options', 'Trop d’options pour un article.', ['status' => 422]);
            }
            $prixUnitaire = TUC_Commande::prix_unite($itemId, $options);
            $optionsLisibles = [];
            foreach ($options as $choix) {
                $optionId = is_array($choix) ? (string) ($choix['id'] ?? '') : (string) $choix;
                foreach ((array) get_post_meta($itemId, 'tuc_options', true) as $option) {
                    if ($optionId !== '' && (string) ($option['id'] ?? '') === $optionId) {
                        $nom = sanitize_text_field((string) ($option['nom'] ?? $optionId));
                        $prixOption = (float) ($option['prix'] ?? 0);
                        $optionsLisibles[] = $nom . ($prixOption > 0 ? ' +' . number_format($prixOption, 2, ',', '') : '');
                        break;
                    }
                }
            }
            $lignes[] = [
                'nom'  => sanitize_text_field($plat->post_title),
                'q'    => $qte,
                'pu'   => $prixUnitaire,
                'opts' => $optionsLisibles,
            ];
            $sousTotal += $prixUnitaire * $qte;
        }

        $minimum = (float) get_post_meta($resto->ID, 'tuc_minimum', true);
        if ($minimum > 0 && $sousTotal < $minimum) {
            return new WP_Error('tuc_minimum', 'Le panier est sous le minimum requis.', ['status' => 422]);
        }
        $frais = $mode === 'livraison' ? (float) get_post_meta($resto->ID, 'tuc_frais', true) : 0.0;
        $total = $sousTotal + $frais;

        $numero = preg_replace('/[^0-9]/', '', (string) get_option('tuc_wa_numero', ''));
        if ($numero === '') {
            return new WP_Error('tuc_wa', 'Numéro WhatsApp non configuré.', ['status' => 503]);
        }
        $msg = TUC_WA_Message::panier($resto->post_title, $lignes, $frais, $total, $mode, $table);

        return new WP_REST_Response([
            'message' => $msg,
            'url'     => TUC_WA_Message::wa_url($numero, $msg),
            'total'   => $total,
        ], 200);
    }

    public static function wa_statut(WP_REST_Request $req)
    {
        $code = strtoupper(sanitize_text_field((string) $req->get_param('code')));
        $pollSecret = (string) $req->get_param('poll');
        $ok = preg_match('/^[A-Z0-9]{8}$/', $code) ? get_transient('tuc_wa_ok_' . $code) : false;

        $peutLire = is_array($ok)
            && strlen($pollSecret) >= 32
            && !empty($ok['poll_hash'])
            && hash_equals((string) $ok['poll_hash'], hash('sha256', $pollSecret));
        if (!$peutLire) {
            $response = new WP_REST_Response(['pret' => false], 200);
            $response->header('Cache-Control', 'no-store, private, max-age=0');
            $response->header('Pragma', 'no-cache');
            return $response;
        }

        $response = new WP_REST_Response([
            'pret'  => true,
            'cree'  => (bool) $ok['cree'],
            'magie' => $ok['magie'],
        ], 200);
        $response->header('Cache-Control', 'no-store, private, max-age=0');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    public static function consommer_magie(WP_REST_Request $req)
    {
        $jeton = (string) $req->get_param('jeton');
        $cle = 'tuc_magie_' . hash('sha256', $jeton);
        $uid = get_transient($cle);

        if ($uid === false) {
            return new WP_Error('tuc_jeton', 'Lien expiré ou déjà utilisé.', ['status' => 410]);
        }
        delete_transient($cle); // usage unique

        wp_set_current_user((int) $uid);
        wp_set_auth_cookie((int) $uid, true, is_ssl());

        return new WP_REST_Response(['ok' => true, 'uid' => (int) $uid], 200);
    }

    public static function changer_mdp(WP_REST_Request $req)
    {
        $mdp = (string) $req->get_param('mdp');
        if (strlen($mdp) < 8) {
            return new WP_Error('tuc_mdp', '8 caractères minimum.', ['status' => 422]);
        }
        wp_set_password($mdp, get_current_user_id());
        return new WP_REST_Response(['ok' => true], 200);
    }
}
