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
        $attendu = (string) get_option('tuc_wa_secret', '');
        if ($attendu === '') {
            return false; // pas de secret configuré = route fermée
        }
        $fourni = (string) $req->get_header('x-tuc-secret');
        return hash_equals($attendu, $fourni);
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

        $code = strtoupper(wp_generate_password(8, false, false));
        set_transient('tuc_wa_' . $code, [
            'but'   => $but,
            'resto' => $resto,
            'table' => $table,
        ], 30 * MINUTE_IN_SECONDS);

        if ($but === 'commande') {
            $texte = sprintf('TUC — JE COMMANDE %s [table %d] ref:%s', $resto, $table, $code);
        } else {
            $texte = sprintf('TUC — JE VEUX CRÉER MON COMPTE ref:%s', $code);
        }

        return new WP_REST_Response([
            'url'   => 'https://wa.me/' . $numero . '?text=' . rawurlencode($texte),
            'code'  => $code,
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

        [$user, $cree] = self::assurer($tel);
        if (!$user instanceof WP_User) {
            return new WP_Error('tuc_compte', 'Création du compte impossible.', ['status' => 500]);
        }

        $magie = self::lien_magique($user->ID);

        // le navigateur d'origine pourra récupérer ce lien via /wa-statut
        set_transient('tuc_wa_ok_' . $code, [
            'uid'   => $user->ID,
            'magie' => $magie,
            'cree'  => $cree,
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

    public static function wa_statut(WP_REST_Request $req)
    {
        $code = strtoupper(sanitize_text_field((string) $req->get_param('code')));
        if ($code === '') {
            return new WP_Error('tuc_code', 'Code manquant.', ['status' => 400]);
        }
        $ok = get_transient('tuc_wa_ok_' . $code);
        if ($ok === false) {
            return new WP_REST_Response(['pret' => false], 200);
        }
        return new WP_REST_Response([
            'pret'  => true,
            'cree'  => (bool) $ok['cree'],
            'magie' => $ok['magie'],
        ], 200);
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
