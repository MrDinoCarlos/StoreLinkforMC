<?php

add_action('admin_menu', 'woostorelink_add_deliveries_submenu');

function woostorelink_add_deliveries_submenu() {
    add_submenu_page(
        'woostorelink',
        'Pending Deliveries',
        'Deliveries',
        'manage_woocommerce',
        'woostorelink_deliveries',
        'woostorelink_render_deliveries_page'
    );
}

function woostorelink_render_deliveries_page() {
    $config = get_option('woostorelink_db_settings');
    if (!$config) {
        echo '<div class="notice notice-error"><p>Database not configured.</p></div>';
        return;
    }

    // Marcar como entregado
    if (isset($_POST['mark_delivered']) && isset($_POST['delivery_id']) && current_user_can('manage_woocommerce')) {
        $conn = new mysqli(
            $config['host'],
            $config['user'],
            $config['password'],
            $config['database'],
            isset($config['port']) ? intval($config['port']) : 3306
        );

        if (!$conn->connect_error) {
            $stmt = $conn->prepare("UPDATE pending_deliveries SET delivered = 1 WHERE id = ?");
            $stmt->bind_param("i", $_POST['delivery_id']);
            $stmt->execute();
            $stmt->close();
            $conn->close();
            echo '<div class="updated"><p>Delivery marked as delivered.</p></div>';
        }
    }

    // Mostrar la tabla
    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        isset($config['port']) ? intval($config['port']) : 3306
    );

    if ($conn->connect_error) {
        echo '<div class="notice notice-error"><p>Connection error: ' . esc_html($conn->connect_error) . '</p></div>';
        return;
    }

    $result = $conn->query("SELECT * FROM pending_deliveries ORDER BY timestamp DESC");

    echo '<div class="wrap"><h1>Pending Deliveries</h1>';
    echo '<table class="widefat striped"><thead>
        <tr><th>ID</th><th>Order</th><th>Player</th><th>Item</th><th>Amount</th><th>Delivered</th><th>Date</th><th>Action</th></tr>
    </thead><tbody>';

    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . esc_html($row['id']) . '</td>';
        echo '<td>' . esc_html($row['order_id']) . '</td>';
        echo '<td>' . esc_html($row['player']) . '</td>';
        echo '<td>' . esc_html($row['item']) . '</td>';
        echo '<td>' . esc_html($row['amount']) . '</td>';
        echo '<td>' . ($row['delivered'] ? '✅ YES' : '❌ NO') . '</td>';
        echo '<td>' . esc_html($row['timestamp']) . '</td>';
        echo '<td>';
        if (!$row['delivered']) {
            echo '<form method="post" style="margin:0;">
                    <input type="hidden" name="delivery_id" value="' . esc_attr($row['id']) . '">
                    <input type="submit" class="button" name="mark_delivered" value="Mark as Delivered">
                  </form>';
        } else {
            echo '-';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    $result->free();
    $conn->close();
}
