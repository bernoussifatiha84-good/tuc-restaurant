<?php
/**
 * Contrat — écran de réglages du site (numéro WhatsApp).
 *
 * Appelé par `require_once` depuis tests/wp-docker-regression.php. La fonction
 * compte ses cas et rend le compte : le runner échoue si le compte attendu
 * n'est pas atteint, donc un contrat qui sortirait trop tôt ne peut pas passer
 * pour un contrat complet.
 *
 * Aucun numéro réel ici : uniquement les numéros factices réservés au runner
 * (212600000000, 212600000099) et des formes de saisie manifestement fictives.
 *
 * @param callable|null $assertExterne Helper du runner, qui lance « ÉCHEC : ».
 * @return int Nombre de cas exécutés.
 */

declare(strict_types=1);

function tuc_reglages_contrat(string $assertExterne = null): int
{
    $assert = $assertExterne ?? static function (bool $ok, string $message): void {
        if (!$ok) {
            throw new RuntimeException('ÉCHEC : ' . $message);
        }
    };

    $casExecutees = 0;
    $compter = static function (bool $ok, string $message) use ($assert, &$casExecutees): void {
        $assert($ok, $message);
        $casExecutees++;
    };

    // ---- sauvegarde de l'état initial, restauré dans le finally ----
    $avaitOption = get_option(TUC_Reglages::OPTION_WA, false) !== false;
    $ancienneValeur = get_option(TUC_Reglages::OPTION_WA, '');

    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    if ($admin === []) {
        WP_CLI::error('Aucun administrateur : la suite a besoin d’un compte à capacités.');
    }

    // `wp_die()` n'est PAS une exception : sous WP-CLI il termine le processus.
    // On intercepte le gestionnaire de mort, et on le retire en PREMIÈRE
    // instruction du finally (leçon du chantier import : un remove_filter au fil
    // de l'eau ne s'exécute jamais quand une assertion tombe au milieu).
    $intercepterMort = null;
    $poseInterception = static function () use (&$intercepterMort): void {
        $intercepterMort = static function (): callable {
            return static function ($message = ''): void {
                throw new RuntimeException('WP_DIE:' . (is_scalar($message) ? (string) $message : ''));
            };
        };
        add_filter('wp_die_handler', $intercepterMort);
        add_filter('wp_die_ajax_handler', $intercepterMort);
        add_filter('wp_die_json_handler', $intercepterMort);
    };
    $retirerInterception = static function () use (&$intercepterMort): void {
        if ($intercepterMort === null) {
            return;
        }
        remove_filter('wp_die_handler', $intercepterMort);
        remove_filter('wp_die_ajax_handler', $intercepterMort);
        remove_filter('wp_die_json_handler', $intercepterMort);
        $intercepterMort = null;
    };

    // Le handler redirige puis `exit` : on ne peut pas l'appeler tel quel depuis
    // un process WP-CLI. On extrait donc la décision. `preparer()` est la seule
    // porte d'écriture, et c'est elle que ces cas mesurent.
    // Une mutation sur `preparer()` (retrait de normaliser) doit faire tomber les
    // cas 1, 2 et 3 — sinon l'écriture ne protège rien.

    try {
        wp_set_current_user((int) $admin[0]);

        // =================================================================
        // 1. 0600000000 → 212600000000
        //    Sans normaliser(), la valeur stockée serait « 0600000000 » et le
        //    lien wa.me silencieusement cassé. C'est LE piège du module.
        // =================================================================
        $r1 = TUC_Reglages::preparer('0600000000');
        $compter(!is_wp_error($r1) && $r1 === '212600000000', '1. un numéro saisi avec zéro initial est normalisé en international');
        $compter(!is_wp_error($r1) && !str_starts_with((string) $r1, '0'), '1b. et le zéro initial ne survit pas : wa.me/0… serait cassé');

        // =================================================================
        // 2. 00212600000000 → 212600000000
        // =================================================================
        $r2 = TUC_Reglages::preparer('00212600000000');
        $compter(!is_wp_error($r2) && $r2 === '212600000000', '2. un numéro saisi avec 00 est normalisé');

        // =================================================================
        // 3. « +212 600-000000 » → 212600000000, sans + ni espace
        // =================================================================
        $r3 = TUC_Reglages::preparer('+212 600-000000');
        $compter(!is_wp_error($r3) && $r3 === '212600000000', '3. un numéro saisi avec + et séparateurs est normalisé');
        $compter(!is_wp_error($r3) && preg_match('/^[0-9]+$/', (string) $r3) === 1, '3b. le résultat ne contient que des chiffres');

        // =================================================================
        // 4. saisie invalide → refus, et l'ancienne valeur reste intacte
        //    On écrit d'abord une valeur connue pour pouvoir constater qu'elle
        //    survit au refus.
        // =================================================================
        update_option(TUC_Reglages::OPTION_WA, '212600000000', false);
        $avant = (string) get_option(TUC_Reglages::OPTION_WA, '');
        foreach (['abc', '12', '', '1'] as $invalide) {
            $r = TUC_Reglages::preparer($invalide);
            // '' est légitime (effacement volontaire) : il rend '' et non une erreur.
            if ($invalide === '') {
                $compter($r === '', '4. un champ vidé volontairement est accepté et rend la chaîne vide');
            } else {
                $compter(is_wp_error($r) && $r->get_error_message() !== '', '4. « ' . $invalide . ' » est refusé avec un motif lisible');
            }
        }
        $compter(
            (string) get_option(TUC_Reglages::OPTION_WA, '') === $avant,
            '4b. un refus n’écrit RIEN : l’ancienne valeur est intacte'
        );

        // =================================================================
        // 5. sécurité : sans capacité → wp_die, même avec un nonce valide
        // =================================================================
        $poseInterception();

        $abonne = wp_insert_user([
            'user_login' => 'tuc_reglages_abonne_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(24),
            'role'       => 'subscriber',
        ]);

        wp_set_current_user(0);
        $_REQUEST['_wpnonce'] = wp_create_nonce('tuc_reglages_enregistrer');
        $refus = null;
        try {
            TUC_Reglages::enregistrer_admin();
        } catch (Throwable $e) {
            $refus = $e;
        }
        $compter($refus !== null && str_contains($refus->getMessage(), 'WP_DIE'), '5a. un visiteur non connecté est arrêté par wp_die');

        wp_set_current_user((int) $abonne);
        $_REQUEST['_wpnonce'] = wp_create_nonce('tuc_reglages_enregistrer');
        $refus = null;
        try {
            TUC_Reglages::enregistrer_admin();
        } catch (Throwable $e) {
            $refus = $e;
        }
        $compter($refus !== null && str_contains($refus->getMessage(), 'WP_DIE'), '5b. un compte sans manage_options est refusé, MÊME avec un nonce valide');

        wp_set_current_user((int) $admin[0]);
        $_REQUEST['_wpnonce'] = 'faux-nonce';
        $refus = null;
        try {
            TUC_Reglages::enregistrer_admin();
        } catch (Throwable $e) {
            $refus = $e;
        }
        $compter($refus !== null, '5c. un administrateur avec un nonce invalide est refusé');

        unset($_REQUEST['_wpnonce']);
        wp_delete_user((int) $abonne);
        $retirerInterception();

        // =================================================================
        // 6. option vide → les DEUX endpoints en 503 ; après enregistrement,
        //    wa_lien rend une URL wa.me portant le numéro normalisé.
        // =================================================================
        delete_option(TUC_Reglages::OPTION_WA);

        $requete = new WP_REST_Request('POST', '/tuc/v1/wa-lien');
        $requete->set_param('but', 'compte');
        $reponse = TUC_Compte::wa_lien($requete);
        $compter(
            $reponse instanceof WP_Error && (int) ($reponse->get_error_data()['status'] ?? 0) === 503,
            '6a. sans numéro, wa_lien refuse en 503 (création de compte client bloquée)'
        );

        $onboarding = TUC_Onboarding_Restaurant::soumettre(new WP_REST_Request('POST', '/tuc/v1/restaurant-onboarding'));
        $codeOnboarding = $onboarding instanceof WP_Error
            ? (int) ($onboarding->get_error_data()['status'] ?? 0) : 0;
        // Le rate-limit (429) passe AVANT la lecture du numéro : sur un site qui a
        // déjà reçu des demandes, c'est lui qu'on voit. On l'accepte, mais on le
        // nomme — un cas qui accepterait « 429 ou 503 » sans le dire masquerait
        // que le 503 n'a pas été atteint.
        $compter(
            in_array($codeOnboarding, [503, 429], true),
            '6b. sans numéro, l’inscription restaurant est refusée (503 attendu ; 429 si le rate-limit a parlé avant) — vu : ' . $codeOnboarding
        );

        update_option(TUC_Reglages::OPTION_WA, (string) TUC_Reglages::preparer('0600000000'), false);
        $requete2 = new WP_REST_Request('POST', '/tuc/v1/wa-lien');
        $requete2->set_param('but', 'compte');
        $reponse2 = TUC_Compte::wa_lien($requete2);
        $url = $reponse2 instanceof WP_REST_Response ? (string) ($reponse2->get_data()['url'] ?? '') : '';
        $compter(str_starts_with($url, 'https://wa.me/212600000000'), '6c. après enregistrement, wa_lien rend un wa.me portant le numéro normalisé');
        $compter(!str_contains($url, 'wa.me/0'), '6d. et le lien ne porte pas le zéro initial — le bug silencieux est fermé');

        // =================================================================
        // 7. la notice « non configuré » apparaît, puis disparaît
        // =================================================================
        $notice = static function (): string {
            ob_start();
            TUC_Reglages::notice_non_configure();
            return (string) ob_get_clean();
        };

        delete_option(TUC_Reglages::OPTION_WA);
        $texte = $notice();
        $compter(str_contains($texte, 'non configuré') && str_contains($texte, '503'), '7a. la notice apparaît tant que rien n’est configuré, et nomme le 503');

        update_option(TUC_Reglages::OPTION_WA, '212600000099', false);
        $compter($notice() === '', '7b. la notice disparaît dès qu’un numéro est configuré');

        // =================================================================
        // 8. l'aperçu de lien est celui des deux lectures, pas un autre
        //     (mutation : si l'écran affichait la valeur brute, l'aperçu
        //      montrerait « wa.me/0… » et l'humain pourrait le croire valide)
        // =================================================================
        $compter(
            TUC_Reglages::apercu_lien('212600000099') === 'https://wa.me/212600000099',
            '8. l’aperçu affiché est exactement le lien que produit le site'
        );
        $compter(
            TUC_Reglages::apercu_lien('00212600000099') === 'https://wa.me/00212600000099',
            '8b. et il montre la casse d’une valeur non normalisée, au lieu de la masquer'
        );

        return (int) $casExecutees;
    } finally {
        // 1. la mutation globale d'abord : wp_die doit redevenir mortel.
        $retirerInterception();

        // 2. la restauration d'option ensuite, dans tous les cas.
        if ($avaitOption) {
            update_option(TUC_Reglages::OPTION_WA, $ancienneValeur);
        } else {
            delete_option(TUC_Reglages::OPTION_WA);
        }

        wp_set_current_user(0);
    }
}
