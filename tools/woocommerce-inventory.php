<?php
/**
 * Read-only WooCommerce/TUC inventory. Run only with WP-CLI:
 *   wp --path=/path/to/wordpress eval-file tools/woocommerce-inventory.php
 * Reports aggregate counts and plugin/page metadata only; never order/customer data.
 */

if (!class_exists('WP_CLI')) {
    fwrite(STDERR, "Run this inventory with WP-CLI.\n");
    exit(1);
}

$counts_by_status = static function (string $post_type): array {
    global $wpdb;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_status, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status",
            $post_type
        ),
        ARRAY_A
    );
    if (!is_array($rows)) {
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        $result[(string) $row['post_status']] = (int) $row['total'];
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
$installed_plugins = [];
$woocommerce_plugin_installed = false;
$woocommerce_plugin_version = null;
foreach ($all_plugins as $file => $plugin) {
    $name = sanitize_text_field((string) $plugin['Name']);
    $version = sanitize_text_field((string) $plugin['Version']);
    $is_active = in_array($file, $active_files, true);
    $installed_plugins[] = ['name' => $name, 'version' => $version, 'active' => $is_active];
    if ($is_active) {
        $active_plugins[] = ['name' => $name, 'version' => $version];
    }
    if (strcasecmp($name, 'WooCommerce') === 0) {
        $woocommerce_plugin_installed = true;
        $woocommerce_plugin_version = $version;
    }
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
$order_storage = 'unavailable';
$legacy_order_posts_by_status = $counts_by_status('shop_order');
$legacy_order_posts_total = 0;
foreach ($legacy_order_posts_by_status as $status => $count) {
    if (!in_array($status, ['trash', 'auto-draft', 'inherit'], true)) {
        $legacy_order_posts_total += $count;
    }
}
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
    $order_storage = 'woocommerce_api';
} else {
    global $wpdb;
    $hpos_table = $wpdb->prefix . 'wc_orders';
    $hpos_table_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($hpos_table))
    ) === $hpos_table;
    if ($hpos_table_exists && get_option('woocommerce_custom_orders_table_enabled', 'no') === 'yes') {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT status, COUNT(*) AS total FROM {$hpos_table} WHERE type = %s GROUP BY status", 'shop_order'),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $status = preg_replace('/^wc-/', '', (string) $row['status']);
            $orders_by_status[$status] = (int) $row['total'];
        }
        $orders_total = array_sum($orders_by_status);
        $order_storage = 'hpos_table';
    } else {
        $orders_by_status = $legacy_order_posts_by_status;
        $orders_total = $legacy_order_posts_total;
        $order_storage = 'legacy_posts';
    }
}

$product_types = [];
$product_category_count = null;
global $wpdb;
$term_rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT tt.taxonomy, t.slug, tt.count FROM {$wpdb->term_taxonomy} AS tt INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id WHERE tt.taxonomy IN (%s, %s)",
        'product_type',
        'product_cat'
    ),
    ARRAY_A
);
if (is_array($term_rows)) {
    $product_category_count = 0;
    foreach ($term_rows as $term_row) {
        if ($term_row['taxonomy'] === 'product_type') {
            $product_types[(string) $term_row['slug']] = (int) $term_row['count'];
        } elseif ($term_row['taxonomy'] === 'product_cat') {
            $product_category_count++;
        }
    }
}

$product_counts_by_status = $counts_by_status('product');
$variation_counts_by_status = $counts_by_status('product_variation');
$coupon_counts_by_status = $counts_by_status('shop_coupon');
$product_total = array_sum($product_counts_by_status);
$variation_total = array_sum($variation_counts_by_status);
$coupon_total = array_sum($coupon_counts_by_status);

$woocommerce_settings_option_names = [];
$woocommerce_options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('woocommerce_') . '%'
    )
);
foreach ((array) $woocommerce_options as $option_name) {
    if (preg_match('/^woocommerce_[a-z0-9_-]+_settings$/i', (string) $option_name)) {
        $woocommerce_settings_option_names[] = (string) $option_name;
    }
}
sort($woocommerce_settings_option_names);

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
        'plugin_installed' => $woocommerce_plugin_installed,
        'plugin_version' => $woocommerce_plugin_version,
        'version' => defined('WC_VERSION') ? WC_VERSION : null,
        'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
        'taxes_enabled' => get_option('woocommerce_calc_taxes', 'no') === 'yes',
        'hpos_setting' => get_option('woocommerce_custom_orders_table_enabled', null),
        'order_storage' => $order_storage,
        'products_total' => $product_total,
        'products_by_status' => $product_counts_by_status,
        'product_types' => $product_types,
        'product_category_count' => $product_category_count,
        'products_on_sale' => function_exists('wc_get_product_ids_on_sale') ? count(wc_get_product_ids_on_sale()) : null,
        'variations_total' => $variation_total,
        'variations_by_status' => $variation_counts_by_status,
        'coupons_total' => $coupon_total,
        'coupons_by_status' => $coupon_counts_by_status,
        'settings_option_names' => $woocommerce_settings_option_names,
        'order_count_total' => $orders_total,
        'orders_by_status' => $orders_by_status,
        'legacy_order_posts_total' => $legacy_order_posts_total,
        'legacy_order_posts_by_status' => $legacy_order_posts_by_status,
        'assigned_pages' => $woo_pages,
    ],
    'tuc_content_by_status' => $tuc_counts,
    'active_plugins' => $active_plugins,
    'installed_plugins' => $installed_plugins,
];

WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
