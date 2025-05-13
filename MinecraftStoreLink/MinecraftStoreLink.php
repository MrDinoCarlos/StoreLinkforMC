<?php
/*
Plugin Name: MinecraftStoreLink
Plugin URI: https://nocticraft.com/minecraftstorelink
Description: Connects WooCommerce to Minecraft to deliver items after purchase.
Version: 1.0.13
Author: MrDinoCarlos
Author URI: https://discord.gg/ddyfucfZpy
License: GPL2
*/

if (!defined('ABSPATH')) exit;

if (!defined('MINECRAFTSTORELINK_PRO')) {
    define('MINECRAFTSTORELINK_PRO', false);
}

require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/products-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/deliveries-page.php';

add_action('woocommerce_order_status_processing', 'minecraftstorelink_create_pending_delivery');
add_action('woocommerce_order_status_completed',  'minecraftstorelink_create_pending_delivery');

function minecraftstorelink_create_pending_delivery($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) return;

    global $wpdb;

    $player_name = sanitize_text_field($order->get_billing_first_name());
    if (empty($player_name)) return;

    $allowed_products = get_option('minecraftstorelink_sync_products', []);
    if (empty($allowed_products)) return;

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        if (!in_array($product_id, $allowed_products)) continue;

        $product_name = strtolower($item->get_name());
        $quantity     = $item->get_quantity();

        // 🚫 Verifica si ya existe una entrega con los mismos datos
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pending_deliveries
             WHERE order_id = %d AND player = %s AND item = %s",
            $order_id, $player_name, $product_name
        ));

        if ($exists) continue; // ya está insertado

        $wpdb->insert(
            "{$wpdb->prefix}pending_deliveries",
            [
                'order_id'  => $order_id,
                'player'    => $player_name,
                'item'      => $product_name,
                'amount'    => $quantity,
                'delivered' => 0,
                'timestamp' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%d', '%d', '%s']
        );
    }
}


add_action('rest_api_init', function () {
    register_rest_route('minecraftstorelink/v1', '/pending', [
        'methods'             => 'GET',
        'callback'            => 'minecraftstorelink_api_get_pending',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('minecraftstorelink/v1', '/mark-delivered', [
        'methods'             => 'POST',
        'callback'            => 'minecraftstorelink_api_mark_delivered',
        'permission_callback' => '__return_true',
    ]);
});

function minecraftstorelink_api_get_pending($request) {
    global $wpdb;

    $token  = sanitize_text_field($request['token']);
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
        $row['id'] = intval($row['id']);
        $row['order_id'] = intval($row['order_id']);
        $row['amount'] = intval($row['amount']);
    }

    if (!MINECRAFTSTORELINK_PRO) {
        $results = array_slice($results, 0, 3);
    }

    return new WP_REST_Response(['deliveries' => $results], 200);
}

function minecraftstorelink_api_mark_delivered($request) {
    global $wpdb;

    $token    = sanitize_text_field($request->get_param('token'));
    $ids_raw  = $request->get_param('ids');
    $valid_token = get_option('minecraftstorelink_api_token');

    if ($token !== $valid_token) {
        return new WP_REST_Response(['success' => false, 'error' => 'Invalid token'], 403);
    }

    if (empty($ids_raw)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Missing delivery ID(s)'], 400);
    }

    $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

    if (empty($ids)) {
        return new WP_REST_Response(['success' => false, 'error' => 'No valid delivery IDs provided'], 400);
    }

    $table = $wpdb->prefix . 'pending_deliveries';
    $success = 0;

    foreach ($ids as $id) {
        $updated = $wpdb->update(
            $table,
            ['delivered' => 1],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        if ($updated !== false) {
            $success++;
        }
    }

    return new WP_REST_Response([
        'success'        => true,
        'updated_count'  => $success,
        'ids_processed'  => $ids
    ], 200);
}
