<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Point unique d’autorisation métier, scoppé au restaurant publié ou au brouillon de son responsable. */
final class TUC_Acces
{
    public static function peut(string $capability, ?int $restaurant_id = null, ?int $user_id = null): bool
    {
        $user_id = $user_id ?? get_current_user_id();
        if ($user_id <= 0 || $capability === '') {
            return false;
        }

        if ($restaurant_id === null) {
            if (!class_exists('TUC_Caisse')) {
                return false;
            }
            $restaurant = TUC_Caisse::mon_resto();
            $restaurant_id = $restaurant instanceof WP_Post ? $restaurant->ID : 0;
        }
        if ($restaurant_id <= 0) {
            return false;
        }

        $restaurant = get_post($restaurant_id);
        if (!$restaurant instanceof WP_Post || $restaurant->post_type !== TUC_CPT::RESTO) {
            return false;
        }
        $ownsDraft = $restaurant->post_status === 'draft'
            && (int) get_user_meta($user_id, 'tuc_resto_id', true) === $restaurant_id
            && user_can($user_id, TUC_Roles::CAP_MANAGE_RESTAURANT);
        if ($restaurant->post_status !== 'publish' && !$ownsDraft) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        return user_can($user_id, $capability)
            && (int) get_user_meta($user_id, 'tuc_resto_id', true) === $restaurant_id;
    }
}
