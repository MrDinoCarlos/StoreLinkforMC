<?php
/*
Plugin Name: StoreLink for Minecraft by MrDino
Plugin URI: https://nocticraft.com/minecraftstorelink
Description: Connects WooCommerce to Minecraft to deliver items after purchase.
Version: 1.0.28
Requires PHP: 8.1
Requires at least: 6.0
Author: MrDinoCarlos
Author URI: https://discord.gg/ddyfucfZpy
License: GPL2
*/

if (!defined('ABSPATH')) exit;
if (!defined('STORELINKFORMC_PRO')) define('STORELINKFORMC_PRO', false);

// 📂 Incluir páginas administrativas
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


if (!function_exists('storelinkformc_force_link_enabled')) {
    function storelinkformc_force_link_enabled(): bool {
        return get_option('storelinkformc_force_link', 'yes') === 'yes';
    }
}

// === INSTALL / SELF-HEAL =====================================================
register_activation_hook(__FILE__, 'storelinkformc_install');

function storelinkformc_install() {
    storelinkformc_create_or_update_tables();
    storelinkformc_force_classic_checkout(true); // true = forzar en activación
}

add_action('admin_init', function () {
    // Autocuración silenciosa si alguien migró sin activar correctamente
    global $wpdb;
    $table = $wpdb->prefix . 'pending_deliveries';
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) );
    if ($exists !== $table) {
        storelinkformc_create_or_update_tables();
    }
});

/**
 * Crea/actualiza tablas necesarias del plugin.
 */
function storelinkformc_create_or_update_tables() {
    global $wpdb;
    $table   = $wpdb->prefix . 'pending_deliveries';
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
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY player (player),
            KEY delivered (delivered)
        ) $charset;
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

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
    $has_shortcode = (false !== strpos($post->post_content, $shortcode));
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
        <a href="' . esc_url($url_force) . '" class="button button-secondary" style="margin-left:8px;">Forzar ahora</a></p>
    </div>';
}

add_action('admin_enqueue_scripts', function ($hook) {
    // Cargar solo en admin (es liviano y se ata a jQuery de WP)
    wp_enqueue_script('jquery');
    $js = <<<JS
jQuery(document).on('click', '.storelinkformc-dismissable .notice-dismiss', function() {
  var \$n = jQuery(this).closest('.storelinkformc-dismissable');
  jQuery.post(ajaxurl, {
    action: 'storelinkformc_dismiss_checkout_notice',
    _ajax_nonce: \$n.data('nonce')
  });
});
JS;
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
    $order = wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) return;

    global $wpdb;
    $user_id = $order->get_user_id();

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

    $allowed_products = get_option('storelinkformc_sync_products', []);
    $product_roles    = get_option('storelinkformc_product_roles_map', []);
    $user             = new WP_User($user_id);

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();

        // Roles
        if (isset($product_roles[$product_id])) {
            $role = sanitize_text_field($product_roles[$product_id]);
            if (!user_can($user_id, $role)) $user->add_role($role);
        }

        if (!in_array($product_id, $allowed_products)) continue;

        $product_name = sanitize_text_field(strtolower($item->get_name()));
        $quantity     = (int) $item->get_quantity();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pending_deliveries WHERE order_id=%d AND player=%s AND item=%s",
            $order_id, $player_name, $product_name
        ));
        if ($exists) continue;

        $wpdb->insert("{$wpdb->prefix}pending_deliveries", [
            'order_id'  => $order_id,
            'player'    => $player_name,
            'item'      => $product_name,
            'amount'    => $quantity,
            'delivered' => 0,
            'timestamp' => current_time('mysql')
        ], ['%d','%s','%s','%d','%d','%s']);
    }
}


// ❌ Eliminar roles si el pedido falla, se cancela o se reembolsa
add_action('woocommerce_order_status_cancelled', 'storelinkformc_remove_roles_for_order');
add_action('woocommerce_order_status_refunded', 'storelinkformc_remove_roles_for_order');
add_action('woocommerce_order_status_failed', 'storelinkformc_remove_roles_for_order');

function storelinkformc_remove_roles_for_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $user_id = $order->get_user_id();
    $product_roles = get_option('storelinkformc_product_roles_map', []);
    $user = new WP_User($user_id);

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        if (isset($product_roles[$product_id])) {
            $role = sanitize_text_field($product_roles[$product_id]);
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
        $fields['minecraft_player'] = ['label' => 'Minecraft Username', 'value' => $player];
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
    $player = sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true));

    ob_start(); ?>

    <div class="storelinkformc-sync-wrapper">
        <h2>Minecraft Account Link</h2>

        <?php if ($player): ?>
            <p>✅ Your account is linked to: <strong><?php echo esc_html($player); ?></strong></p>
            <button id="storelinkformc-unlink-button" class="button button-danger">Unlink Minecraft Account</button>
        <?php else: ?>
            <p>⛔ You don’t have a Minecraft account linked. Go to the server and type /wsl wp-link (email) and verify with /wsl wp-verify (code)</p>
        <?php endif; ?>
    </div>

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

        wp_localize_script('storelinkformc-unlink-js', 'storelinkformc_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('storelinkformc_unlink_action'),
        ));
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

    wp_localize_script('storelinkformc-checkout', 'storelinkformc_checkout_vars', [
        'linked_player' => $linked_player,
        'force_link'    => storelinkformc_force_link_enabled() ? 'yes' : 'no',
    ]);

}


add_action('wp_ajax_storelinkformc_unlink_account', 'storelinkformc_handle_unlink');

function storelinkformc_handle_unlink() {
    check_ajax_referer('storelinkformc_unlink_action', 'security');

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error('User not logged in');
    }

    delete_user_meta($user_id, 'minecraft_player');
    wp_send_json_success('Minecraft account unlinked');
}

// Personalize the "order received" message on the thank you page
add_filter('woocommerce_thankyou_order_received_text', 'storelinkformc_thankyou_text', 10, 2);
function storelinkformc_thankyou_text($text, $order) {
    if (!$order instanceof WC_Order) {
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
        // Try to inject after "Gracias." or "Thank you."
        $pattern = '/^(Gracias|Thank you)\./i';
        if (preg_match($pattern, $text)) {
            $replacement = '$1, ' . esc_html($player_to_show) . '.';
            $text = preg_replace($pattern, $replacement, $text, 1);
        } else {
            // Fallback: prepend politely if theme string is different
            $prefix = sprintf(__('Thank you, %s.', 'storelinkformc'), esc_html($player_to_show));
            $text = $prefix . ' ' . $text;
        }
    }

    // 2) If order includes synced products, add delivery hint
    $allowed_products = get_option('storelinkformc_sync_products', []);
    $has_synced = false;
    if (is_array($allowed_products) && !empty($allowed_products)) {
        foreach ($order->get_items() as $item) {
            $pid = $item->get_product_id();
            if (in_array($pid, $allowed_products, true)) {
                $has_synced = true;
                break;
            }
        }
    }

    if ($has_synced) {
        if ($gift && !empty($recipient)) {
            $extra = sprintf(
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
