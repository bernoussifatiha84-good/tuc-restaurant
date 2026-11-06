<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** wp tuc-options migrate — dry-run par défaut, sauvegarde par article avant application. */
final class TUC_Options_CLI
{
    /**
     * ## OPTIONS
     *
     * [--apply]
     * : Applique la migration après la revue du dry-run.
     *
     * [--limit=<number>]
     * : Nombre maximum d’articles à parcourir (défaut : 5000).
     *
     * ## EXAMPLES
     *
     *     wp tuc-options migrate
     *     wp tuc-options migrate --apply --limit=5000
     */
    /**
     * ## OPTIONS
     *
     * [--apply]
     * : Restaure les métadonnées originales sauvegardées.
     *
     * [--id=<number>]
     * : Limite l’opération à un article menu.
     *
     * [--limit=<number>]
     * : Nombre maximum d’articles (défaut : 5000).
     */
    public static function rollback(array $args, array $assoc_args): void
    {
        $apply = (bool) WP_CLI\Utils\get_flag_value($assoc_args, 'apply', false);
        $itemId = max(0, (int) ($assoc_args['id'] ?? 0));
        $limit = max(1, min(20000, (int) ($assoc_args['limit'] ?? 5000)));
        $ids = $itemId > 0 ? [$itemId] : get_posts([
            'post_type' => TUC_CPT::ITEM,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $limit,
            'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => 'tuc_options_legacy_backup', 'compare' => 'EXISTS']],
        ]);
        $ready = 0;
        $restored = 0;
        $missing = 0;
        $errors = 0;
        foreach ($ids as $id) {
            $result = TUC_Options::restaurer_legacy_item((int) $id, $apply);
            if (is_wp_error($result)) {
                $errors++;
                WP_CLI::warning(sprintf('Article #%d non restauré : %s', (int) $id, $result->get_error_message()));
            } elseif ($result['state'] === 'ready') {
                $ready++;
            } elseif ($result['state'] === 'restored') {
                $restored++;
            } else {
                $missing++;
            }
        }
        $mode = $apply ? 'RESTAURATION APPLIQUÉE' : 'DRY-RUN — aucune donnée modifiée';
        WP_CLI::log(sprintf('%s | parcourus=%d, prêts=%d, restaurés=%d, sans_sauvegarde=%d, erreurs=%d', $mode, count($ids), $ready, $restored, $missing, $errors));
        if ($errors > 0) {
            WP_CLI::error('Corrigez les erreurs avant de relancer la restauration.');
        }
        WP_CLI::success($apply ? 'Restauration terminée; la sauvegarde par article est conservée.' : 'Aperçu terminé. La commande —apply restaurera les valeurs originales.');
    }

    public static function migrate(array $args, array $assoc_args): void
    {
        $apply = (bool) WP_CLI\Utils\get_flag_value($assoc_args, 'apply', false);
        $limit = max(1, min(20000, (int) ($assoc_args['limit'] ?? 5000)));
        $pageSize = 100;
        $offset = 0;
        $processed = 0;
        $ready = 0;
        $migrated = 0;
        $current = 0;
        $errors = 0;

        while ($processed < $limit) {
            $batch = get_posts([
                'post_type' => TUC_CPT::ITEM,
                'post_status' => ['publish', 'draft', 'pending', 'private'],
                'posts_per_page' => min($pageSize, $limit - $processed),
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
                'no_found_rows' => true,
            ]);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $itemId) {
                $processed++;
                $result = TUC_Options::migrer_item((int) $itemId, $apply);
                if (is_wp_error($result)) {
                    $errors++;
                    WP_CLI::warning(sprintf('Article #%d non migré : %s', (int) $itemId, $result->get_error_message()));
                    continue;
                }
                if ($result['state'] === 'ready') {
                    $ready++;
                } elseif ($result['state'] === 'migrated') {
                    $migrated++;
                } else {
                    $current++;
                }
            }
            $offset += count($batch);
            if (count($batch) < $pageSize) {
                break;
            }
        }

        $mode = $apply ? 'APPLIQUÉE' : 'DRY-RUN — aucune donnée modifiée';
        WP_CLI::log(sprintf('%s | parcourus=%d, à_migrer=%d, migrés=%d, déjà_v2=%d, erreurs=%d', $mode, $processed, $ready, $migrated, $current, $errors));
        if ($errors > 0) {
            WP_CLI::error('Corrigez les définitions invalides et relancez le dry-run avant application.');
        }
        if (!$apply && $ready > 0) {
            WP_CLI::success('Aperçu terminé. Chaque article migré conservera son original dans tuc_options_legacy_backup.');
        } elseif ($apply) {
            WP_CLI::success('Migration terminée; commande relançable sans perte ni double migration.');
        } else {
            WP_CLI::success('Aucune migration restante.');
        }
    }
}
