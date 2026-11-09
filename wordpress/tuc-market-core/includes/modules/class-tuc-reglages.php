<?php
/**
 * Module — Réglages du site (aujourd'hui : le numéro WhatsApp).
 *
 * POURQUOI CE MODULE EXISTE
 * `tuc_wa_numero` est lu à deux endroits — `TUC_Compte::wa_lien()`
 * (class-tuc-compte.php:265) et l'inscription restaurant publique
 * (class-tuc-onboarding-restaurant.php:139) — et il n'était écrit NULLE PART
 * dans le plugin. Sans l'écran ci-dessous, un propriétaire ne pouvait changer
 * son numéro qu'en terminal. C'est un trou produit, pas un détail.
 *
 * LE PIÈGE, ET POURQUOI LA NORMALISATION VIT ICI
 * Les deux lectures font `preg_replace('/[^0-9]/', '', …)` puis collent le
 * résultat derrière `https://wa.me/`. Elles n'appellent PAS `normaliser()`.
 * Donc chaque forme incorrecte produit un lien silencieusement cassé :
 *
 *   saisi 0600000000      → wa.me/0600000000      (cassé, aucune erreur)
 *   saisi 00212…          → wa.me/00212…          (cassé, aucune erreur)
 *   saisi +212 600-000000 → wa.me/212600000000    (correct)
 *
 * La correction appartient à l'ÉCRITURE : on normalise à l'enregistrement et on
 * ne stocke jamais la valeur brute. Toucher aux deux points de lecture
 * reviendrait à modifier le chemin de vérification — un autre chantier.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Reglages
{
    /** L'option globale concernée. */
    public const OPTION_WA = 'tuc_wa_numero';

    private const SLUG = 'tuc-reglages';

    /** Même borne que le reste du code (onboarding l.359, commande l.152). */
    private const BORNE_WA = '/^[1-9][0-9]{7,14}$/';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_tuc_reglages_enregistrer', [self::class, 'enregistrer_admin']);
        add_action('admin_notices', [self::class, 'notice_non_configure']);
    }

    public static function admin_menu(): void
    {
        // Sous « Réglages », pas sous les Restaurants : c'est une option GLOBALE
        // du site, elle ne concerne aucun restaurant en particulier. Les deux
        // écrans existants vivent sous edit.php?post_type=tuc_resto parce qu'ils
        // portent sur des fiches ; celui-ci non.
        add_options_page(
            'Réglages TUC',
            'Réglages TUC',
            'manage_options',
            self::SLUG,
            [self::class, 'page_admin']
        );
    }

    /** La valeur enregistrée, telle quelle. Peut être vide. */
    public static function numero_enregistre(): string
    {
        return (string) get_option(self::OPTION_WA, '');
    }

    /**
     * Le lien wa.me tel que les DEUX lectures le construiraient.
     * Sert d'aperçu à l'écran : l'œil humain attrape une erreur de format bien
     * mieux qu'un motif d'expression régulière.
     */
    public static function apercu_lien(string $numero): string
    {
        $chiffres = preg_replace('/[^0-9]/', '', $numero);
        return 'https://wa.me/' . $chiffres;
    }

    /**
     * Normalise puis valide. Retourne le numéro stockable, ou un WP_Error qui
     * dit POURQUOI — pas un « invalide » sec.
     */
    public static function preparer(string $saisie): string|WP_Error
    {
        $saisie = trim($saisie);
        if ($saisie === '') {
            return ''; // vidé volontairement : autorisé, l'état « non configuré » revient
        }
        $normalise = TUC_Compte::normaliser($saisie);
        if (!preg_match(self::BORNE_WA, $normalise)) {
            return new WP_Error(
                'tuc_reglages_wa',
                sprintf(
                    'Numéro refusé après normalisation : « %s » donne « %s », qui n’est pas un numéro international valide (8 à 15 chiffres, sans zéro initial). Saisissez l’indicatif pays suivi du numéro.',
                    $saisie,
                    $normalise
                )
            );
        }
        return $normalise;
    }

    // ---------------------------------------------------------------------
    // Le handler — patron : class-tuc-onboarding-restaurant.php:349/353
    // ---------------------------------------------------------------------

    public static function enregistrer_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        check_admin_referer('tuc_reglages_enregistrer');

        $saisie = sanitize_text_field((string) wp_unslash($_POST[self::OPTION_WA] ?? ''));
        $resultat = self::preparer($saisie);

        if (is_wp_error($resultat)) {
            // AUCUNE écriture : l'ancienne valeur reste. On revient à l'écran
            // avec le motif, encodé pour l'URL.
            wp_safe_redirect(add_query_arg(
                ['page' => self::SLUG, 'tuc_erreur' => rawurlencode($resultat->get_error_message())],
                admin_url('options-general.php')
            ));
            exit;
        }

        if ($resultat === '') {
            delete_option(self::OPTION_WA);
            $message = 'Numéro WhatsApp effacé : le site est de nouveau NON CONFIGURÉ.';
        } else {
            update_option(self::OPTION_WA, $resultat, false);
            $message = 'Numéro WhatsApp enregistré, normalisé en « ' . $resultat . ' ».';
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'tuc_ok' => rawurlencode($message)],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Notice d'admin tant que rien n'est configuré.
     * Elle existe pour que le propriétaire TROUVE l'écran sans le chercher :
     * sans elle, il ne saurait pas qu'un réglage manque.
     */
    public static function notice_non_configure(): void
    {
        if (!current_user_can('manage_options') || self::numero_enregistre() !== '') {
            return;
        }
        $url = admin_url('options-general.php?page=' . self::SLUG);
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('TUC — numéro WhatsApp non configuré.', 'tuc-core'),
            esc_html__('Tant qu’il manque, deux endpoints refusent en 503 : l’inscription d’un restaurant et la création de compte client.', 'tuc-core'),
            esc_url($url),
            esc_html__('Le régler maintenant', 'tuc-core')
        );
    }

    // ---------------------------------------------------------------------
    // L'écran
    // ---------------------------------------------------------------------

    public static function page_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        nocache_headers();

        $enregistre = self::numero_enregistre();
        $configure = $enregistre !== '';
        $erreur = isset($_GET['tuc_erreur']) ? (string) wp_unslash($_GET['tuc_erreur']) : '';
        $ok = isset($_GET['tuc_ok']) ? (string) wp_unslash($_GET['tuc_ok']) : '';
        ?>
        <div class="wrap">
          <h1>Réglages TUC</h1>

          <?php if ($erreur !== '') : ?>
            <div class="notice notice-error"><p><?php echo esc_html($erreur); ?></p></div>
          <?php endif; ?>
          <?php if ($ok !== '') : ?>
            <div class="notice notice-success"><p><?php echo esc_html($ok); ?></p></div>
          <?php endif; ?>

          <h2>Numéro WhatsApp du site</h2>

          <?php if ($configure) : ?>
            <p>
              État : <strong>configuré</strong> — valeur enregistrée (normalisée) :
              <code><?php echo esc_html($enregistre); ?></code>
            </p>
            <p>
              Lien produit par le site :
              <code><?php echo esc_html(self::apercu_lien($enregistre)); ?></code>
            </p>
            <p class="description">
              Vérifiez que ce lien ouvre bien la bonne conversation. C’est le seul
              contrôle qui attrape une erreur de format avant un client.
            </p>
          <?php else : ?>
            <p>
              État : <strong style="color:#b32d2e">NON CONFIGURÉ</strong>.
            </p>
            <p>
              Tant que ce numéro est vide, <strong>deux endpoints refusent en 503</strong> :
            </p>
            <ul style="list-style:disc;margin-left:2em">
              <li><strong>inscription d’un restaurant</strong> — la demande publique ne peut pas partir ;</li>
              <li><strong>création de compte client</strong> — le lien WhatsApp qui porte le code de vérification n’est pas construit.</li>
            </ul>
            <p class="description">
              Le refus est explicite, pas silencieux : rien n’est perdu, mais rien ne
              fonctionne non plus.
            </p>
          <?php endif; ?>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tuc_reglages_enregistrer">
            <?php wp_nonce_field('tuc_reglages_enregistrer'); ?>
            <table class="form-table">
              <tr>
                <th><label for="tuc_wa_numero">Numéro WhatsApp du site</label></th>
                <td>
                  <input type="text" name="tuc_wa_numero" id="tuc_wa_numero" class="regular-text"
                         value="<?php echo esc_attr($enregistre); ?>" autocomplete="off">
                  <p class="description">
                    Indicatif pays compris, sans zéro initial. Exemple de forme :
                    indicatif puis numéro, chiffres seuls. Laisser vide efface la
                    valeur et remet le site en état « non configuré ».
                  </p>
                </td>
              </tr>
            </table>
            <?php submit_button('Enregistrer'); ?>
          </form>
        </div>
        <?php
    }
}
