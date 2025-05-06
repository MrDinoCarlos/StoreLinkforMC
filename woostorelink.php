<?php
/*
Plugin Name: WooStoreLink
Plugin URI: https://nocticraft.com/
Description: Connects WooCommerce with your Minecraft server
Version: 0.3.3
Author: MrDinoCarlos
*/

if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, 'woostorelink_create_table');

function woostorelink_create_remote_table() {
    $config = get_option('woostorelink_db_settings');
    if (!$config) return false;

    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        isset($config['port']) ? intval($config['port']) : 3306
    );

    if ($conn->connect_error) return false;

    $sql = "CREATE TABLE IF NOT EXISTS pending_deliveries (
        id INT NOT NULL AUTO_INCREMENT,
        order_id INT NOT NULL,
        player VARCHAR(50) NOT NULL,
        item VARCHAR(100) NOT NULL,
        amount INT NOT NULL,
        delivered TINYINT(1) DEFAULT 0,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $result = $conn->query($sql);
    $conn->close();

    return $result;
}


require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/products-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/deliveries-page.php';

add_action('woocommerce_order_status_completed', 'woostorelink_handle_delivery');

function woostorelink_handle_delivery($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $player = $order->get_billing_first_name();
    if (!$player) return;

    $items = $order->get_items();
    if (empty($items)) return;

    $synced_products = get_option('woostorelink_sync_products', []);
    if (empty($synced_products)) return;

    $config = get_option('woostorelink_db_settings');
    if (!$config || empty($config['host']) || empty($config['user']) || empty($config['password']) || empty($config['database'])) return;

    $timezone = !empty($config['timezone']) ? $config['timezone'] : 'UTC';
    date_default_timezone_set($timezone);

    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        isset($config['port']) ? intval($config['port']) : 3306
    );

    if ($conn->connect_error) return;

    $conn->query("SET time_zone = '" . $conn->real_escape_string($timezone) . "'");

    foreach ($items as $item) {
        $product_id = $item->get_product_id();
        if (!in_array($product_id, $synced_products)) continue;

        $product_name = $item->get_name();
        $amount = $item->get_quantity();

        $stmt = $conn->prepare("INSERT INTO pending_deliveries (player, item, amount, order_id) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssii", $player, $product_name, $amount, $order_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->close();
}

add_action('woocommerce_order_status_cancelled', 'woostorelink_delete_delivery');
add_action('woocommerce_order_status_failed', 'woostorelink_delete_delivery');
add_action('before_delete_post', 'woostorelink_delete_delivery_if_deleted');

function woostorelink_delete_delivery($order_id) {
    $config = get_option('woostorelink_db_settings');
    if (!$config) return;

    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        isset($config['port']) ? intval($config['port']) : 3306
    );

    if ($conn->connect_error) return;

    $stmt = $conn->prepare("DELETE FROM pending_deliveries WHERE order_id = ? AND delivered = 0");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

function woostorelink_delete_delivery_if_deleted($post_id) {
    if (get_post_type($post_id) === 'shop_order') {
        woostorelink_delete_delivery($post_id);
    }
}

add_action('woocommerce_order_fully_refunded', 'woostorelink_delete_delivery_refund_total', 10, 2);
add_action('woocommerce_order_partially_refunded', 'woostorelink_delete_delivery_refund_partial', 10, 2);

function woostorelink_delete_delivery_refund_total($order_id, $refund_id) {
    woostorelink_delete_delivery($order_id);
}

function woostorelink_delete_delivery_refund_partial($order_id, $refund_id) {
    $refund = wc_get_order($refund_id);
    if (!$refund) return;

    $config = get_option('woostorelink_db_settings');
    if (!$config) return;

    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        isset($config['port']) ? intval($config['port']) : 3306
    );

    if ($conn->connect_error) return;

    foreach ($refund->get_items() as $refunded_item) {
        $product_name = $refunded_item->get_name();
        $refunded_amount = $refunded_item->get_quantity();

        $stmt = $conn->prepare("DELETE FROM pending_deliveries WHERE order_id = ? AND item = ? AND delivered = 0 LIMIT ?");
        $stmt->bind_param("isi", $order_id, $product_name, $refunded_amount);
        $stmt->execute();
        $stmt->close();
    }

    $conn->close();
}

function woostorelink_mark_as_delivered($order_id) {
    $config = get_option('woostorelink_db_settings');
    if (!$config) return;

    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        isset($config['port']) ? intval($config['port']) : 3306
    );

    if ($conn->connect_error) return;

    $stmt = $conn->prepare("UPDATE pending_deliveries SET delivered = 1 WHERE order_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->close();
}

add_action('init', 'woostorelink_register_manual_command');

function woostorelink_register_manual_command() {
    if (isset($_GET['woostorelink_create_table']) && current_user_can('manage_options')) {
        woostorelink_create_table();
        echo 'WooStoreLink: Table created successfully.';
        exit;
    }
}
