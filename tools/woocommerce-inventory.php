<?php
/**
 * Read-only WooCommerce/TUC inventory. Run only with WP-CLI:
 *   wp --path=/path/to/wordpress eval-file tools/woocommerce-inventory.php
 * Reports aggregate counts and plugin/page metadata only; never order/customer data.
 */

declare(strict_types=1);

if (!class_exists('WP_CLI')) {
    fwrite(STDERR, "Run this inventory with WP-CLI.\n");
    exit(1);
}

$counts_by_status = static function (string $post_type): array {
    $counts = wp_count_posts($post_type);
    if (!$counts) {
        return [];
    }

    $result = [];
    foreach (get_object_vars($counts) as $status => $count) {
        $result[$status] = (int) $count;
    }
    return $result;
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$all_plugins = get_plugins();
$active_files = (array) get_option('active_plugins', []);
if (is_multisite()) {
    $active_files = array_merge($active_files, array_keys((array) get_site_option('active_sitewide_plugins', [])));
}
$active_files = array_values(array_unique($active_files));
$active_plugins = [];
foreach ($active_files as $file) {
    if (!isset($all_plugins[$file])) {
        $active_plugins[] = ['file' => $file, 'name' => '(metadata unavailable)'];
        continue;
    }
    $active_plugins[] = [
        'file'    => $file,
        'name'    => sanitize_text_field((string) $all_plugins[$file]['Name']),
        'version' => sanitize_text_field((string) $all_plugins[$file]['Version']),
    ];
}

$woo_pages = [];
foreach ([
    'shop'     => 'woocommerce_shop_page_id',
    'cart'     => 'woocommerce_cart_page_id',
    'checkout' => 'woocommerce_checkout_page_id',
    'account'  => 'woocommerce_myaccount_page_id',
] as $page_name => $option_name) {
    $page_id = (int) get_option($option_name, 0);
    $page = $page_id > 0 ? get_post($page_id) : null;
    $woo_pages[$page_name] = [
        'id'     => $page_id,
        'exists' => $page instanceof WP_Post,
        'type'   => $page instanceof WP_Post ? $page->post_type : null,
        'status' => $page instanceof WP_Post ? $page->post_status : null,
    ];
}

$orders_by_status = [];
$orders_total = null;
if (function_exists('wc_get_orders') && function_exists('wc_get_order_statuses')) {
    foreach (wc_get_order_statuses() as $status_key => $label) {
        $status = preg_replace('/^wc-/', '', (string) $status_key);
        $page = wc_get_orders([
            'status'   => [$status],
            'limit'    => 1,
            'paginate' => true,
            'return'   => 'ids',
        ]);
        $orders_by_status[$status] = is_object($page) && isset($page->total)
            ? (int) $page->total
            : null;
    }
    $all_orders = wc_get_orders([
        'limit'    => 1,
        'paginate' => true,
        'return'   => 'ids',
    ]);
    $orders_total = is_object($all_orders) && isset($all_orders->total)
        ? (int) $all_orders->total
        : null;
}

$product_types = [];
if (taxonomy_exists('product_type')) {
    $terms = get_terms(['taxonomy' => 'product_type', 'hide_empty' => false]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $product_types[(string) $term->slug] = (int) $term->count;
        }
    }
}
$product_category_count = null;
if (taxonomy_exists('product_cat')) {
    $term_count = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'fields'     => 'count',
    ]);
    $product_category_count = is_wp_error($term_count) ? null : (int) $term_count;
}

$tuc_counts = [];
foreach (['tuc_resto', 'tuc_menu_item', 'tuc_commande'] as $post_type) {
    if (post_type_exists($post_type)) {
        $tuc_counts[$post_type] = $counts_by_status($post_type);
    }
}

$report = [
    'generated_at_utc' => gmdate(DATE_ATOM),
    'woocommerce' => [
        'active' => class_exists('WooCommerce'),
        'version' => defined('WC_VERSION') ? WC_VERSION : null,
        'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
        'taxes_enabled' => get_option('woocommerce_calc_taxes', 'no') === 'yes',
        'hpos_setting' => get_option('woocommerce_custom_orders_table_enabled', null),
        'products_by_status' => post_type_exists('product') ? $counts_by_status('product') : [],
        'product_types' => $product_types,
        'product_category_count' => $product_category_count,
        'products_on_sale' => function_exists('wc_get_product_ids_on_sale') ? count(wc_get_product_ids_on_sale()) : null,
        'variations_by_status' => post_type_exists('product_variation') ? $counts_by_status('product_variation') : [],
        'coupons_by_status' => post_type_exists('shop_coupon') ? $counts_by_status('shop_coupon') : [],
        'order_count_total' => $orders_total,
        'orders_by_status' => $orders_by_status,
        'assigned_pages' => $woo_pages,
    ],
    'tuc_content_by_status' => $tuc_counts,
    'active_plugins' => $active_plugins,
];

WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
