<?php
/*
Plugin Name: MinecraftStoreLink
Plugin URI: https://nocticraft.com/minecraftstorelink
Description: Connects WooCommerce to Minecraft to deliver items after purchase.
Version: 1.0.14
Author: MrDinoCarlos
Author URI: https://discord.gg/ddyfucfZpy
License: GPL2
*/

if (!defined('ABSPATH')) exit;
if (!defined('MINECRAFTSTORELINK_PRO')) define('MINECRAFTSTORELINK_PRO', false);

// Include admin pages
require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/products-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/deliveries-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/checkout-fields-page.php';
require_once plugin_dir_path(__FILE__) . 'linking-api.php';

// ⛏ Crear entrega pendiente al procesar o completar pedido
add_action('woocommerce_order_status_processing', 'minecraftstorelink_create_pending_delivery');
add_action('woocommerce_order_status_completed', 'minecraftstorelink_create_pending_delivery');

function minecraftstorelink_create_pending_delivery($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) return;

    global $wpdb;
    $user_id = $order->get_user_id();
    $player_name = $user_id ? get_user_meta($user_id, 'minecraft_player', true) : '';
    if (empty($player_name)) return;

    $allowed_products = get_option('minecraftstorelink_sync_products', []);
    if (empty($allowed_products)) return;

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        if (!in_array($product_id, $allowed_products)) continue;

        $product_name = strtolower($item->get_name());
        $quantity = $item->get_quantity();

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

// 📧 Mostrar nombre de Minecraft en emails
add_filter('woocommerce_email_order_meta_fields', function ($fields, $sent_to_admin, $order) {
    $user_id = $order->get_user_id();
    if ($user_id) {
        $player = get_user_meta($user_id, 'minecraft_player', true);
        if ($player) {
            $fields['minecraft_player'] = ['label' => 'Minecraft Username', 'value' => $player];
        }
    }
    return $fields;
}, 10, 3);

// 🛠 Mostrar en admin > pedidos
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    $user_id = $order->get_user_id();
    if ($user_id) {
        $player = get_user_meta($user_id, 'minecraft_player', true);
        if ($player) {
            echo '<p><strong>Minecraft Username:</strong> ' . esc_html($player) . '</p>';
        }
    }
});
// Mostrar Minecraft Username en correos electrónicos
add_filter('woocommerce_email_order_meta_fields', function ($fields, $sent_to_admin, $order) {
    $user_id = $order->get_user_id();
    if ($user_id) {
        $player = get_user_meta($user_id, 'minecraft_player', true);
        if ($player) {
            $fields['minecraft_player'] = [
                'label' => __('Minecraft Username', 'minecraftstorelink'),
                'value' => $player,
            ];
        }
    }
    return $fields;
}, 10, 3);
// Register REST routes for pending deliveries and mark delivered
add_action('rest_api_init', function () {
    register_rest_route('minecraftstorelink/v1', '/pending', [
        'methods'  => 'GET',
        'callback' => 'minecraftstorelink_api_get_pending',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('minecraftstorelink/v1', '/mark-delivered', [
        'methods'  => 'POST',
        'callback' => 'minecraftstorelink_api_mark_delivered',
        'permission_callback' => '__return_true',
    ]);
});

function minecraftstorelink_api_get_pending($request) {
    global $wpdb;
    $token = sanitize_text_field($request['token']);
    $player = sanitize_text_field($request['player']);
    $valid_token = get_option('minecraftstorelink_api_token');

    if ($token !== $valid_token) {
        return new WP_REST_Response(['error' => 'Invalid token'], 403);
    }

    if (empty($player)) {
        return new WP_REST_Response(['error' => 'Missing player name'], 400);
    }

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT id, order_id, item, amount
         FROM {$wpdb->prefix}pending_deliveries
         WHERE player = %s AND delivered = 0
         ORDER BY timestamp ASC",
        $player
    ), ARRAY_A);

    foreach ($results as &$row) {
        $row['id'] = (int) $row['id'];
        $row['order_id'] = (int) $row['order_id'];
        $row['amount'] = (int) $row['amount'];
    }

    return new WP_REST_Response(['deliveries' => $results], 200);
}

function minecraftstorelink_api_mark_delivered($request) {
    global $wpdb;
    $token = sanitize_text_field($request->get_param('token'));
    $ids = array_filter(array_map('intval', explode(',', $request->get_param('ids'))));
    $valid_token = get_option('minecraftstorelink_api_token');

    if ($token !== $valid_token) {
        return new WP_REST_Response(['success' => false, 'error' => 'Invalid token'], 403);
    }

    if (empty($ids)) {
        return new WP_REST_Response(['success' => false, 'error' => 'No valid delivery IDs provided'], 400);
    }

    $table = $wpdb->prefix . 'pending_deliveries';
    $success = 0;
    foreach ($ids as $id) {
        $updated = $wpdb->update($table, ['delivered' => 1], ['id' => $id]);
        if ($updated !== false) $success++;
    }

    return new WP_REST_Response([
        'success' => true,
        'updated_count' => $success,
        'ids_processed' => $ids
    ], 200);
}
