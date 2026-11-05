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

        // Route administrative historique. Le statut opérationnel de caisse
        // passe par TUC_Caisse::statut(), qui vérifie aussi la boutique.
        register_rest_route('tuc/v1', '/commande/(?P<id>\d+)/statut', [
            'methods'             => 'POST',
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'callback'            => [self::class, 'changer_statut'],
        ]);
    }

    /** Prix unitaire réel d'un plat, options comprises. Jamais le prix du client. */
    public static function prix_unite(int $item_id, array $options = []): float
    {
        $base = (float) get_post_meta($item_id, 'tuc_prix', true);
        $definies = (array) get_post_meta($item_id, 'tuc_options', true);

        $supplement = 0.0;
        foreach ($options as $choix) {
            $id_opt = is_array($choix) ? ($choix['id'] ?? '') : (string) $choix;
            foreach ($definies as $o) {
                if (($o['id'] ?? '') === $id_opt) {
                    $supplement += (float) ($o['prix'] ?? 0);
                    break;
                }
            }
        }

        return $base + $supplement;
    }

    public static function creer(WP_REST_Request $req)
    {
        $resto_slug = sanitize_title((string) $req->get_param('resto'));
        $table      = (int) ($req->get_param('table') ?? 0);
        $telBrut    = preg_replace('/[^0-9+]/', '', (string) $req->get_param('tel'));
        $tel        = TUC_Compte::normaliser($telBrut);
        $annonce    = (float) $req->get_param('total');
        $items      = json_decode((string) wp_json_encode($req->get_param('items')), true) ?: [];

        // ---- 1. le restaurant existe et il est ouvert ----
        $resto = get_page_by_path($resto_slug, OBJECT, 'tuc_resto');
        if (!$resto instanceof WP_Post || $resto->post_status !== 'publish') {
            return new WP_Error('tuc_resto', 'Restaurant inconnu.', ['status' => 404]);
        }
        if (get_post_meta($resto->ID, 'tuc_ferme', true) === '1') {
            return new WP_Error('tuc_ferme', 'Le restaurant est fermé.', ['status' => 423]);
        }
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $tel)) {
            return new WP_Error('tuc_tel', 'Numéro de téléphone invalide.', ['status' => 422]);
        }

        if (!is_array($items) || $items === [] || count($items) > 50) {
            return new WP_Error('tuc_vide', 'Panier vide ou trop volumineux.', ['status' => 400]);
        }

        // ---- 2. idempotence : un double-tap ne crée pas deux commandes ----
        $cle = (string) $req->get_param('cle');
        if ($cle !== '' && !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $cle)) {
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
                return new WP_REST_Response(['ok' => true, 'commande' => (int) $deja[0], 'doublon' => true], 200);
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

            $pu = self::prix_unite($item_id, $opts);
            $total += $pu * $qte;

            // Le client envoie des identifiants d'options (`sauce-bbq`). La
            // caisse et le message WhatsApp, eux, doivent afficher « Sauce BBQ
            // +4 », pas « sauce-bbq ». On résout le libellé ici, une seule
            // fois : le prix affiché et le prix facturé restent la même source.
            $opts_lisibles = [];
            foreach ($opts as $choix) {
                $id_opt = is_array($choix) ? (string) ($choix['id'] ?? '') : (string) $choix;
                if ($id_opt === '') {
                    continue;
                }
                $libelle = $id_opt;
                $prix    = 0.0;
                foreach ((array) get_post_meta($item_id, 'tuc_options', true) as $o) {
                    if ((string) ($o['id'] ?? '') === $id_opt) {
                        $libelle = (string) ($o['nom'] ?? $id_opt);
                        $prix    = (float) ($o['prix'] ?? 0);
                        break;
                    }
                }
                $opts_lisibles[] = trim($libelle . ($prix > 0 ? ' +' . rtrim(rtrim(number_format($prix, 2, ',', ''), '0'), ',') : ''));
            }

            $lignes[] = [
                'id'    => $item_id,
                'nom'   => $plat->post_title,
                'q'     => $qte,
                'pu'    => $pu,
                'opts'  => array_map('sanitize_text_field', $opts_lisibles),
            ];
        }

        // frais de livraison : 0 si commande à table
        $frais = $table > 0 ? 0.0 : (float) get_post_meta($resto->ID, 'tuc_frais', true);
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
                'tuc_items'  => $lignes,
                'tuc_sous_total' => $total,
                'tuc_frais'  => $frais,
                'tuc_total'  => $du,
                'tuc_tel'    => $tel,
                'tuc_statut' => 'nouvelle',
                'tuc_cle'    => $cle,
                'tuc_historique' => [['nouvelle', current_time('c')]],
            ],
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }

        /** Module 6 : Web Push vers le téléphone de la caisse. */
        do_action('tuc_commande_creee', $id, $lignes);

        return new WP_REST_Response([
            'ok'     => true,
            'commande' => $id,
            'total'  => $du,
            'frais'  => $frais,
        ], 201);
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

        if (!in_array($vers, self::STATUTS, true)) {
            return new WP_Error('tuc_statut', 'Statut inconnu.', ['status' => 400]);
        }

        $histo = (array) get_post_meta($id, 'tuc_historique', true);
        $histo[] = [$vers, current_time('c')];

        update_post_meta($id, 'tuc_statut', $vers);
        update_post_meta($id, 'tuc_historique', $histo);

        return new WP_REST_Response(['ok' => true, 'statut' => $vers], 200);
    }
}
