<?php
/*
Plugin Name: StoreLink for Minecraft by MrDino
Plugin URI: https://nocticraft.com/minecraftstorelink
Description: Connects WooCommerce to Minecraft to deliver items after purchase.
Version: 1.0.26
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
require_once plugin_dir_path(__FILE__) . 'linking-api.php';

// ⚠️ Aviso si el checkout usa bloques (no compatible)
add_action('admin_notices', function () {
    if (function_exists('wc_get_page_id') && has_blocks(get_post(wc_get_page_id('checkout')))) {
        echo '<div class="notice notice-warning"><p>
        ⚠️ <strong>StoreLink for MC:</strong> The new WooCommerce block-based checkout is not compatible with this plugin. Please edit the Checkout page and replace it with the <code>[woocommerce_checkout]</code> shortcode.
        </p></div>';
    }
});


// ⛏ Crear entrega pendiente cuando un pedido se procese o complete
add_action('woocommerce_order_status_processing', 'storelinkformc_create_pending_delivery');
add_action('woocommerce_order_status_completed', 'storelinkformc_create_pending_delivery');

function storelinkformc_create_pending_delivery($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) return;

    global $wpdb;
    $user_id = $order->get_user_id();
    $gift = get_post_meta($order_id, '_minecraft_gift', true);
    $gift_to = get_post_meta($order_id, '_minecraft_username', true);

    if ($gift === 'yes' && !empty($gift_to)) {
        $player_name = sanitize_text_field($gift_to);
    } else {
        $player_name = sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true));
    }

    if (empty($player_name) || !is_string($player_name)) return;

    $allowed_products = get_option('storelinkformc_sync_products', []);
    $product_roles = get_option('storelinkformc_product_roles_map', []);
    $user = new WP_User($user_id);

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();

        // Asignar rol si corresponde
        if (isset($product_roles[$product_id])) {
            $role = sanitize_text_field($product_roles[$product_id]);
            if (!user_can($user_id, $role)) {
                $user->add_role($role);
            }
        }

        if (!in_array($product_id, $allowed_products)) continue;

        $product_name = sanitize_text_field(strtolower($item->get_name()));
        $quantity = intval($item->get_quantity());

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pending_deliveries
             WHERE order_id = %d AND player = %s AND item = %s",
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
        ], ['%d', '%s', '%s', '%d', '%d', '%s']);
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
    $player = sanitize_text_field(get_user_meta($order->get_user_id(), 'minecraft_player', true));
    if ($player) {
        $fields['minecraft_player'] = ['label' => 'Minecraft Username', 'value' => $player];
    }
    return $fields;
}, 10, 3);

// 🧾 Mostrar en admin > pedidos
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    $player = sanitize_text_field(get_user_meta($order->get_user_id(), 'minecraft_player', true));
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
    $selected_fields = get_option('storelinkformc_checkout_fields', []);
    wp_register_script(
        'storelinkformc-checkout',
        plugin_dir_url(__FILE__) . 'assets/js/checkout-fields.js',
        [],
        filemtime(plugin_dir_path(__FILE__) . 'assets/js/checkout-fields.js'),
        true
    );
    wp_enqueue_script('storelinkformc-checkout');
    wp_localize_script('storelinkformc-checkout', 'storelinkformc_allowed_fields', $selected_fields);
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
