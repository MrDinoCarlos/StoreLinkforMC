<?php
/*
Plugin Name: StoreLink for Minecraft by MrDino
Plugin URI: https://mrdino.es/woostorelink-plugin/
Description: Connects WooCommerce to Minecraft to deliver items after purchase.
Version: 1.0.35
Requires PHP: 8.1
Requires at least: 6.0
Author: MrDinoCarlos
Author URI: https://discord.gg/ddyfucfZpy
License: GPL2
Text Domain: storelinkformc
Domain Path: /languages
*/

if (defined('STORELINKFORMC_LOADED')) {
    return;
}

define('STORELINKFORMC_LOADED', true);

if (!defined('ABSPATH')) exit;
if (!defined('STORELINKFORMC_PRO')) define('STORELINKFORMC_PRO', false);

// Incluir páginas administrativas
require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/products-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/deliveries-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/checkout-fields-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/sync-roles-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/email-templates-page.php';
require_once plugin_dir_path(__FILE__) . 'linking-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend-mc-order-fields.php';
require_once plugin_dir_path(__FILE__) . 'includes/cache-compat.php';
require_once plugin_dir_path(__FILE__) . 'includes/cloudflare-api.php';
require_once plugin_dir_path(__FILE__) . 'admin/cdn-cache-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend-mc-checkout-avatar.php';


// Load admin SMTP notice (safe include)
$storelinkformc_admin_notice = plugin_dir_path(__FILE__) . 'admin/admin-smtp-notice.php';
if (file_exists($storelinkformc_admin_notice)) {
    require_once $storelinkformc_admin_notice;
}


if (!function_exists('storelinkformc_force_link_enabled')) {
    function storelinkformc_force_link_enabled(): bool {
        return get_option('storelinkformc_force_link', 'yes') === 'yes';
    }
}

if (!function_exists('storelinkformc_get_delivery_expiration_seconds')) {
    /**
     * Returns the configured expiration time (in seconds) for unclaimed deliveries.
     * Default: 30 days.
     */
    function storelinkformc_get_delivery_expiration_seconds(): int {
        $opt = get_option('storelinkformc_delivery_expiration', null);

        // New format: array with 'seconds'
        if (is_array($opt)) {
            if (isset($opt['seconds']) && is_numeric($opt['seconds'])) {
                $seconds = (int) $opt['seconds'];
                $unit    = isset($opt['unit']) ? sanitize_text_field($opt['unit']) : '';
                $value   = isset($opt['value']) ? (int) $opt['value'] : 0;

                if ('seconds' === $unit && $value <= 10 && $seconds <= 10) {
                    $seconds = 30 * 86400;
                }
            } else {
                $value = isset($opt['value']) ? (int) $opt['value'] : 30;
                $unit  = isset($opt['unit']) ? sanitize_text_field($opt['unit']) : 'days';

                $unit_seconds = [
                    'seconds' => 1,
                    'minutes' => 60,
                    'hours'   => 3600,
                    'days'    => 86400,
                    'weeks'   => 604800,
                    // For expiration purposes we use approximations:
                    'months'  => 2592000,   // 30 days
                    'years'   => 31536000,  // 365 days
                ];

                if (!isset($unit_seconds[$unit])) {
                    $unit = 'days';
                }
                if ($value < 1) {
                    $value = 1;
                }

                $seconds = (int) ($value * $unit_seconds[$unit]);
            }
        } elseif (is_numeric($opt)) {
            // Backwards compatibility if a raw seconds value exists.
            $seconds = (int) $opt;
            if ($seconds <= 10) {
                $seconds = 30 * 86400;
            }
        } else {
            // Default: 30 days
            $seconds = 30 * 86400;
        }

        // Clamp: 1 second .. 999 years
        $min_seconds = 1;
        $max_seconds = 999 * 31536000;

        if ($seconds < $min_seconds) $seconds = $min_seconds;
        if ($seconds > $max_seconds) $seconds = $max_seconds;

        return $seconds;
    }
}


if (!function_exists('storelinkformc_cart_has_synced_products')) {
    /**
     * Returns true if the current WooCommerce cart contains
     * at least one product that is synced with StoreLinkMC.
     */
    function storelinkformc_cart_has_synced_products(): bool {
        if (!function_exists('WC')) {
            return false;
        }

        $wc = WC();
        if (!$wc || !isset($wc->cart) || !is_a($wc->cart, 'WC_Cart')) {
            return false;
        }

        $synced_products = array_map('absint', (array) get_option('storelinkformc_sync_products', []));

        if (empty($synced_products)) {
            return false;
        }

        foreach ($wc->cart->get_cart() as $cart_item) {
            $product_id   = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            $variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;

            if ($product_id && in_array($product_id, $synced_products, true)) {
                return true;
            }
            if ($variation_id && in_array($variation_id, $synced_products, true)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Upgrade routine (safe on updates). Ensures new columns exist without needing reactivation.
 */
add_action('plugins_loaded', function () {
    $db_ver = get_option('storelinkformc_db_version', '1.0.0');

    if (version_compare($db_ver, '1.0.35', '<')) {
        if (function_exists('storelinkformc_create_or_update_tables')) {
            storelinkformc_create_or_update_tables();
        }
        update_option('storelinkformc_db_version', '1.0.35');
    }

    // Ensure cron exists even if the plugin was updated without reactivation.
    if (!wp_next_scheduled('storelinkformc_cleanup_expired_deliveries')) {
        wp_schedule_event(time() + 300, 'hourly', 'storelinkformc_cleanup_expired_deliveries');
    }
});

add_action('admin_init', function () {
    if (!get_option('storelinkformc_needs_activation_setup')) {
        return;
    }

    delete_option('storelinkformc_needs_activation_setup');

    if (function_exists('storelinkformc_create_or_update_tables')) {
        storelinkformc_create_or_update_tables();
        update_option('storelinkformc_db_version', '1.0.35');
    }

    storelinkformc_force_classic_checkout(true);

    if (!wp_next_scheduled('storelinkformc_cleanup_expired_deliveries')) {
        wp_schedule_event(time() + 300, 'hourly', 'storelinkformc_cleanup_expired_deliveries');
    }
});


// === INSTALL / SELF-HEAL =====================================================
register_activation_hook(__FILE__, 'storelinkformc_install');

function storelinkformc_install() {
    update_option('storelinkformc_needs_activation_setup', 1);
}
register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('storelinkformc_cleanup_expired_deliveries');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'storelinkformc_cleanup_expired_deliveries');
    }
});


add_action('admin_init', function () {
    global $wpdb;
    $table = esc_sql($wpdb->prefix . 'pending_deliveries');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    $db_ver = get_option('storelinkformc_db_version', '1.0.0');

    if ($exists !== $table || version_compare($db_ver, '1.0.35', '<')) {
        storelinkformc_create_or_update_tables();
        update_option('storelinkformc_db_version', '1.0.35');
    }
});


/**
 * Crea/actualiza tablas necesarias del plugin.
 */
function storelinkformc_create_or_update_tables() {
    global $wpdb;
    $table   = esc_sql($wpdb->prefix . 'pending_deliveries');
    $charset = $wpdb->get_charset_collate();

    // Mantén este esquema en línea con lo que usa el plugin
    $sql = "
        CREATE TABLE $table (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED,
            player VARCHAR(255),
            item VARCHAR(255),
            amount INT DEFAULT 1,
            delivered TINYINT(1) DEFAULT 0,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            expired TINYINT(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY player (player),
            KEY delivered (delivered),
            KEY expires_at (expires_at),
            KEY expired (expired)
        ) $charset;
    ";


    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    storelinkformc_maybe_add_pending_delivery_column($table, 'product_id', 'BIGINT UNSIGNED DEFAULT 0');
    storelinkformc_maybe_add_pending_delivery_column($table, 'variation_id', 'BIGINT UNSIGNED DEFAULT 0');
    storelinkformc_maybe_add_pending_delivery_index($table, 'product_id');
    storelinkformc_maybe_add_pending_delivery_index($table, 'variation_id');
}

function storelinkformc_maybe_add_pending_delivery_column($table, $column, $definition) {
    global $wpdb;

    $table  = esc_sql($table);
    $column = sanitize_key($column);
    if (!$column) {
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
    if ($exists) {
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query("ALTER TABLE {$table} ADD {$column} {$definition}");
}

function storelinkformc_maybe_add_pending_delivery_index($table, $index) {
    global $wpdb;

    $table = esc_sql($table);
    $index = sanitize_key($index);
    if (!$index) {
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index));
    if ($exists) {
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query("ALTER TABLE {$table} ADD INDEX {$index} ({$index})");
}

/**
 * Cron: mark unclaimed deliveries as expired once they pass expires_at.
 */
add_action('storelinkformc_cleanup_expired_deliveries', function () {
    global $wpdb;
    $table = esc_sql($wpdb->prefix . 'pending_deliveries');

    // Only run if columns exist (safe on old DBs)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $col_expired = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'expired'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $col_expires = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'expires_at'));
    if (!$col_expired || !$col_expires) {
        return;
    }

    $now = current_time('mysql');

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE $table
             SET expired = 1
             WHERE delivered = 0
               AND expired = 0
               AND expires_at IS NOT NULL
               AND expires_at < %s",
            $now
        )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
});


/**
 * Reemplaza el Checkout de WooCommerce por el shortcode clásico.
 * @param bool $force Si true, reemplaza siempre aunque tenga contenido.
 */
function storelinkformc_force_classic_checkout($force = false) {
    if ( ! function_exists('wc_get_page_id') ) return;

    $checkout_id = wc_get_page_id('checkout');
    if ( ! $checkout_id || $checkout_id <= 0 ) return;

    $post = get_post($checkout_id);
    if ( ! $post || 'trash' === $post->post_status ) return;

    $shortcode = '[woocommerce_checkout]';

    // ¿Ya está correcto?
    $has_shortcode = (false !== strpos($post->post_content, $shortcode))
        || (function_exists('has_shortcode') && has_shortcode($post->post_content, 'woocommerce_checkout'));
    $has_block     = (function_exists('has_blocks') && has_blocks($post));

    if ($force || $has_block || ! $has_shortcode) {
        // Reemplaza todo el contenido por el shortcode clásico
        $update = [
            'ID'           => $checkout_id,
            'post_content' => $shortcode,
            'post_status'  => 'publish',
        ];

        wp_update_post($update);
        // Limpia caché por si hay plugins de cache
        clean_post_cache($checkout_id);
    }
}


// ⚠️ Aviso si el checkout usa bloques (no compatible) — oculto si ya hay shortcode
add_action('admin_notices', 'storelinkformc_checkout_blocks_notice');
function storelinkformc_checkout_blocks_notice() {
    if ( ! current_user_can('manage_options') ) return;

    // ¿ya lo cerró este usuario?
    if ( get_user_meta(get_current_user_id(), 'storelinkformc_dismiss_checkout_blocks_notice', true) ) return;

    if ( ! function_exists('wc_get_page_id') ) return;

    $checkout_id = wc_get_page_id('checkout');
    if ( ! $checkout_id || $checkout_id <= 0 ) return;

    $post = get_post($checkout_id);
    if ( ! $post || 'trash' === $post->post_status ) return;

    $shortcode = '[woocommerce_checkout]';

    // Detecta correctamente shortcode y bloques
    $has_shortcode = (false !== strpos($post->post_content, $shortcode))
                     || (function_exists('has_shortcode') && has_shortcode($post->post_content, 'woocommerce_checkout'));
    $has_block = function_exists('has_blocks') && has_blocks($post);

    // Si ya está el shortcode y NO hay bloques, todo OK => no mostrar aviso
    if ( $has_shortcode && ! $has_block ) return;

    // Si hay bloques o falta el shortcode, mostrar aviso
    $nonce = wp_create_nonce('storelinkformc_dismiss_notice');
    $url_force = wp_nonce_url(
        admin_url('admin-post.php?action=storelinkformc_force_checkout_shortcode'),
        'storelinkformc_force_checkout_action',
        'storelinkformc_force_checkout_nonce'
    );

    echo '<div class="notice notice-warning is-dismissible storelinkformc-dismissable" data-nonce="' . esc_attr($nonce) . '">
        <p>⚠️ <strong>StoreLink for MC:</strong> The new WooCommerce block-based checkout is not compatible with this plugin.
        Please edit the Checkout page and replace it with the <code>[woocommerce_checkout]</code> shortcode.
        <a href="' . esc_url($url_force) . '" class="button button-secondary" style="margin-left:8px;">Force Now</a></p>
    </div>';
}

add_action('admin_enqueue_scripts', function () {
    // Cargar solo en admin (es liviano y se ata a jQuery de WP)
    wp_enqueue_script('jquery');

    $js  = "jQuery(document).on('click', '.storelinkformc-dismissable .notice-dismiss', function() {\n";
    $js .= "  var \$n = jQuery(this).closest('.storelinkformc-dismissable');\n";
    $js .= "  jQuery.post(ajaxurl, {\n";
    $js .= "    action: 'storelinkformc_dismiss_checkout_notice',\n";
    $js .= "    _ajax_nonce: \$n.data('nonce')\n";
    $js .= "  });\n";
    $js .= "});\n";

    wp_add_inline_script('jquery', $js);
});

add_action('wp_ajax_storelinkformc_dismiss_checkout_notice', function () {
    check_ajax_referer('storelinkformc_dismiss_notice');
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('forbidden', 403);
    }
    update_user_meta(get_current_user_id(), 'storelinkformc_dismiss_checkout_blocks_notice', 1);
    wp_send_json_success();
});

// ⛏ Crear entrega pendiente cuando un pedido se procese o complete
add_action('woocommerce_order_status_processing', 'storelinkformc_create_pending_delivery');
add_action('woocommerce_order_status_completed', 'storelinkformc_create_pending_delivery');

function storelinkformc_create_pending_delivery($order_id) {
    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) return;

    global $wpdb;
    $user_id = $order->get_user_id();

    $table = esc_sql($wpdb->prefix . 'pending_deliveries');

    // Detectar columnas una sola vez por pedido
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $has_expires    = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'expires_at'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $has_expired    = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'expired'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $has_product_id = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'product_id'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $has_variation  = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'variation_id'));


    // NUEVO: leer el meta unificado
    $target_type = get_post_meta($order_id, '_slmc_target_type', true); // 'gift' | 'linked' | 'manual_username'
    $player_name = '';

    if ($target_type === 'gift' || $target_type === 'manual_username') {
        $player_name = sanitize_text_field(get_post_meta($order_id, '_minecraft_username', true));
    } elseif ($target_type === 'linked') {
        $player_name = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    } else {
        // compat legacy
        $gift = get_post_meta($order_id, '_minecraft_gift', true);
        $gift_to = get_post_meta($order_id, '_minecraft_username', true);
        if ($gift === 'yes' && !empty($gift_to)) {
            $player_name = sanitize_text_field($gift_to);
        } else {
            $player_name = sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true));
        }
    }

    if (empty($player_name)) return;

    $allowed_products = array_map('absint', (array) get_option('storelinkformc_sync_products', []));
    $product_roles    = (array) get_option('storelinkformc_product_roles_map', []);
    $user             = new WP_User($user_id);

    foreach ($order->get_items() as $item) {
        if (!is_a($item, 'WC_Order_Item_Product')) {
            continue;
        }

        $product_id   = absint($item->get_product_id());
        $variation_id = absint($item->get_variation_id());
        $sync_id      = ($variation_id && in_array($variation_id, $allowed_products, true)) ? $variation_id : $product_id;
        $role_id      = isset($product_roles[$sync_id]) ? $sync_id : $product_id;

        // Roles
        if ($user_id && isset($product_roles[$role_id])) {
            $role = sanitize_text_field($product_roles[$role_id]);
            if (!user_can($user_id, $role)) {
                $user->add_role($role);
            }
        }

        if (!$sync_id || !in_array($sync_id, $allowed_products, true)) {
            continue;
        }

        $product_name = sanitize_text_field(strtolower($item->get_name()));
        $quantity     = (int) $item->get_quantity();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE order_id=%d AND player=%s AND item=%s",
                $order_id,
                $player_name,
                $product_name
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($exists) {
            continue;
        }

        $data = [
            'order_id'  => $order_id,
            'player'    => $player_name,
            'item'      => $product_name,
            'amount'    => $quantity,
            'delivered' => 0,
            'timestamp' => current_time('mysql'),
        ];

        $formats = ['%d', '%s', '%s', '%d', '%d', '%s'];

        if ($has_product_id) {
            $data['product_id'] = $product_id;
            $formats[] = '%d';
        }

        if ($has_variation) {
            $data['variation_id'] = $variation_id;
            $formats[] = '%d';
        }

        if ($has_expires) {
            $data['expires_at'] = wp_date(
                'Y-m-d H:i:s',
                current_time('timestamp') + storelinkformc_get_delivery_expiration_seconds()
            );
            $formats[] = '%s';
        }

        if ($has_expired) {
            $data['expired'] = 0;
            $formats[] = '%d';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($table, $data, $formats);

    }
}


// ❌ Eliminar roles si el pedido falla, se cancela o se reembolsa
add_action('woocommerce_order_status_cancelled', 'storelinkformc_remove_roles_for_order');
add_action('woocommerce_order_status_refunded', 'storelinkformc_remove_roles_for_order');
add_action('woocommerce_order_status_failed', 'storelinkformc_remove_roles_for_order');

function storelinkformc_remove_roles_for_order($order_id) {
    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) return;

    $user_id = $order->get_user_id();
    $product_roles = (array) get_option('storelinkformc_product_roles_map', []);
    $sync_products = array_map('absint', (array) get_option('storelinkformc_sync_products', []));
    $user = new WP_User($user_id);

    foreach ($order->get_items() as $item) {
        if (!is_a($item, 'WC_Order_Item_Product')) {
            continue;
        }

        $product_id   = absint($item->get_product_id());
        $variation_id = absint($item->get_variation_id());
        $sync_id      = ($variation_id && in_array($variation_id, $sync_products, true)) ? $variation_id : $product_id;
        $role_id      = isset($product_roles[$sync_id]) ? $sync_id : $product_id;

        if (isset($product_roles[$role_id])) {
            $role = sanitize_text_field($product_roles[$role_id]);
            if (user_can($user_id, $role)) {
                $user->remove_role($role);
            }
        }
    }
}

// 📧 Mostrar username de Minecraft en emails
add_filter('woocommerce_email_order_meta_fields', function ($fields, $sent_to_admin, $order) {
    $player = get_post_meta($order->get_id(), '_minecraft_username', true);
    if (!$player) {
        $player = sanitize_text_field(get_user_meta($order->get_user_id(), 'minecraft_player', true));
    }
    if ($player) {
        $fields['minecraft_player'] = [
            'label' => 'Minecraft Username',
            'value' => $player,
        ];
    }
    return $fields;
}, 10, 3);

// 🧾 Mostrar en admin > pedidos
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    $player = get_post_meta($order->get_id(), '_minecraft_username', true);
    if (!$player) {
        $player = sanitize_text_field(get_user_meta($order->get_user_id(), 'minecraft_player', true));
    }
    if ($player) {
        echo '<p><strong>Minecraft Username:</strong> ' . esc_html($player) . '</p>';
    }
});

// 🔗 Shortcode para mostrar estado de vinculación
add_shortcode('storelinkformc_account_sync', 'storelinkformc_render_account_sync_page');
function storelinkformc_render_account_sync_page() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view your Minecraft link status.</p>';
    }

    $user_id = get_current_user_id();
    $player  = sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true));

    $avatar_url = $player
        ? 'https://mc-heads.net/avatar/' . rawurlencode($player) . '/64'
        : 'https://mc-heads.net/avatar/MHF_Question/64';

    ob_start(); ?>
    <div class="storelinkformc-sync-wrapper" style="display:flex;align-items:center;gap:12px;margin:1rem 0;">
        <div class="storelinkformc-avatar" style="width:64px;height:64px;flex:0 0 64px;">
            <img src="<?php echo esc_url($avatar_url); ?>"
                 alt="Minecraft head"
                 title="<?php echo $player ? esc_attr($player) : 'No linked player'; ?>"
                 style="width:64px;height:64px;border-radius:6px;image-rendering:pixelated;display:block;transition:transform 0.2s ease;">
        </div>
        <div class="storelinkformc-info">
            <?php if ($player) : ?>
                <p style="margin:0 0 6px;">
                    ✅ Your account is linked to: <strong><?php echo esc_html($player); ?></strong>
                </p>
                <button id="storelinkformc-unlink-button" class="storelinkformc-danger-btn" type="button">
                    🔓 Unlink Minecraft Account
                </button>
            <?php else : ?>
                <p style="margin:0 0 8px;">
                    ⛔ You don’t have a Minecraft account linked yet.
                </p>
                <div class="storelinkformc-help-box" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;font-size:13px;color:#374151;line-height:1.4;">
                    💡 <strong>How to link your account:</strong><br>
                    Join our Minecraft server and run this command:<br>
                    <code style="background:#111827;color:#f9fafb;padding:2px 6px;border-radius:4px;">/wsl wp-link &lt;your_email&gt;</code><br>
                    (Use the same email you registered on this website)
                </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
    .storelinkformc-danger-btn {
        background: #dc2626;
        color: #fff;
        border: none;
        padding: 6px 14px;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        line-height: 1.2;
        box-shadow: 0 2px 4px rgba(0,0,0,.12);
        transition: background .15s ease, transform .15s ease;
    }
    .storelinkformc-danger-btn:hover {
        background: #b91c1c;
        transform: translateY(-1px);
    }
    .storelinkformc-danger-btn:active {
        transform: translateY(0);
    }
    </style>
    <?php
    return ob_get_clean();
}

function storelinkformc_enqueue_scripts() {
    if (!is_user_logged_in()) return;

    // Solo cargar si el shortcode está presente en la página
    if (is_singular()) {
        global $post;
        if (has_shortcode($post->post_content, 'storelinkformc_account_sync')) {
            wp_enqueue_script(
                'storelinkformc-unlink-js',
                plugin_dir_url(__FILE__) . 'assets/js/unlink-account.js',
                array(),
                filemtime(plugin_dir_path(__FILE__) . 'assets/js/unlink-account.js'),
                true
            );

            wp_localize_script(
                'storelinkformc-unlink-js',
                'storelinkformc_vars',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('storelinkformc_unlink_action'),
                )
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'storelinkformc_enqueue_scripts');

add_action('wp_enqueue_scripts', 'storelinkformc_enqueue_checkout_script');
function storelinkformc_enqueue_checkout_script() {
    if (!function_exists('is_checkout') || !is_checkout()) return;

    wp_register_script(
        'storelinkformc-checkout',
        plugin_dir_url(__FILE__) . 'assets/js/checkout-fields.js',
        [],
        filemtime(plugin_dir_path(__FILE__) . 'assets/js/checkout-fields.js'),
        true
    );
    wp_enqueue_script('storelinkformc-checkout');

    $linked_player = '';
    if (is_user_logged_in()) {
        $linked_player = sanitize_text_field(get_user_meta(get_current_user_id(), 'minecraft_player', true));
    }

    wp_localize_script(
        'storelinkformc-checkout',
        'storelinkformc_checkout_vars',
        [
            'linked_player' => $linked_player,
            'force_link'    => storelinkformc_force_link_enabled() ? 'yes' : 'no',
        ]
    );
}

// Personalize the "order received" message on the thank you page
add_filter('woocommerce_thankyou_order_received_text', 'storelinkformc_thankyou_text', 10, 2);
function storelinkformc_thankyou_text($text, $order) {
    if (!class_exists('WC_Order') || !$order instanceof WC_Order) {
        return $text;
    }

    $order_id   = $order->get_id();
    $user_id    = $order->get_user_id();

    $gift       = get_post_meta($order_id, '_minecraft_gift', true) === 'yes';
    $recipient  = sanitize_text_field(get_post_meta($order_id, '_minecraft_username', true));
    $linked     = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';

    // 1) Insert Minecraft username right after "Gracias"/"Thank you" when available (non-gift)
    $player_to_show = $linked ? $linked : '';
    if ($player_to_show) {
        /* translators: %s: Minecraft username linked to the customer account. */
        $pattern = '/^(Gracias|Thank you)\./i';
        if (preg_match($pattern, $text)) {
            $replacement = '$1, ' . esc_html($player_to_show) . '.';
            $text = preg_replace($pattern, $replacement, $text, 1);
        } else {
            $prefix = sprintf(
                /* translators: %s: Minecraft username linked to the customer account. */
                __('Thank you, %s.', 'storelinkformc'),
                esc_html($player_to_show)
            );
            $text = $prefix . ' ' . $text;
        }
    }

    // 2) If order includes synced products, add delivery hint
    $allowed_products = get_option('storelinkformc_sync_products', []);
    $has_synced = false;
    if (is_array($allowed_products) && !empty($allowed_products)) {
        $allowed_products = array_map('absint', $allowed_products);
        foreach ($order->get_items() as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }

            $pid = absint($item->get_product_id());
            $vid = absint($item->get_variation_id());
            if (in_array($pid, $allowed_products, true) || ($vid && in_array($vid, $allowed_products, true))) {
                $has_synced = true;
                break;
            }
        }
    }

    if ($has_synced) {
        if ($gift && !empty($recipient)) {
            $extra = sprintf(
                /* translators: %s: Minecraft username that will receive the gift on the server. */
                __(' Your item(s) will be delivered on the server to %s as soon as possible.', 'storelinkformc'),
                esc_html($recipient)
            );
        } else {
            $extra = __(' Your item(s) will be delivered on the server as soon as possible.', 'storelinkformc');
        }
        $text .= $extra;
    }
    return $text;
}
