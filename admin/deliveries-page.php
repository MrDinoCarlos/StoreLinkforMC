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

    // Procesar acciones
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['mark_delivered']) && isset($_POST['delivery_id'])) {
            $stmt = $conn->prepare("UPDATE pending_deliveries SET delivered = 1 WHERE id = ?");
            $stmt->bind_param("i", $_POST['delivery_id']);
            $stmt->execute();
            $stmt->close();
            echo '<div class="updated"><p>Marked as delivered.</p></div>';
        }

        if (isset($_POST['mark_undelivered']) && isset($_POST['delivery_id'])) {
            $stmt = $conn->prepare("UPDATE pending_deliveries SET delivered = 0 WHERE id = ?");
            $stmt->bind_param("i", $_POST['delivery_id']);
            $stmt->execute();
            $stmt->close();
            echo '<div class="updated"><p>Marked as undelivered.</p></div>';
        }

        if (isset($_POST['delete_delivery']) && isset($_POST['delivery_id'])) {
            $stmt = $conn->prepare("DELETE FROM pending_deliveries WHERE id = ?");
            $stmt->bind_param("i", $_POST['delivery_id']);
            $stmt->execute();
            $stmt->close();
            echo '<div class="updated"><p>Deleted successfully.</p></div>';
        }

        if (isset($_POST['save_edit']) && isset($_POST['delivery_id'])) {
            $stmt = $conn->prepare("UPDATE pending_deliveries SET player = ?, item = ?, amount = ? WHERE id = ?");
            $stmt->bind_param("ssii", $_POST['player'], $_POST['item'], $_POST['amount'], $_POST['delivery_id']);
            $stmt->execute();
            $stmt->close();
            echo '<div class="updated"><p>Updated successfully.</p></div>';
        }
    }

    // Filtros
    $filter_status = $_POST['filter_status'] ?? 'all';
    $filter_player = $_POST['filter_player'] ?? '';
    $editing_id = $_POST['edit_delivery'] ?? 0;

    // Construir query
    $sql = "SELECT * FROM pending_deliveries WHERE 1=1";
    if ($filter_status === 'pending') {
        $sql .= " AND delivered = 0";
    } elseif ($filter_status === 'delivered') {
        $sql .= " AND delivered = 1";
    }
    if (!empty($filter_player)) {
        $like = '%' . $conn->real_escape_string($filter_player) . '%';
        $sql .= " AND player LIKE '$like'";
    }
    $sql .= " ORDER BY timestamp DESC";

    $result = $conn->query($sql);

    echo '<div class="wrap"><h1>Pending Deliveries</h1>';

    // Filtro
    echo '<form method="post" style="margin-bottom:15px; display:flex; gap:10px; align-items: center;">
        <label>Status:
            <select name="filter_status">
                <option value="all"' . selected($filter_status, 'all', false) . '>All</option>
                <option value="pending"' . selected($filter_status, 'pending', false) . '>Pending</option>
                <option value="delivered"' . selected($filter_status, 'delivered', false) . '>Delivered</option>
            </select>
        </label>
        <label>Player:
            <input type="text" name="filter_player" value="' . esc_attr($filter_player) . '">
        </label>
        <button class="button">🔄 Refresh Table</button>
    </form>';

    echo '<table class="widefat striped"><thead>
        <tr><th>ID</th><th>Order</th><th>Player</th><th>Item</th><th>Amount</th><th>Delivered</th><th>Date</th><th>Actions</th></tr>
    </thead><tbody>';

    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $isEditing = ($editing_id == $id);
        echo '<tr><form method="post">';
        echo '<input type="hidden" name="delivery_id" value="' . esc_attr($id) . '">';
        echo '<td>' . esc_html($id) . '</td>';
        echo '<td>' . esc_html($row['order_id']) . '</td>';

        if ($isEditing) {
            echo '<td><input name="player" value="' . esc_attr($row['player']) . '"></td>';
            echo '<td><input name="item" value="' . esc_attr($row['item']) . '"></td>';
            echo '<td><input name="amount" type="number" value="' . esc_attr($row['amount']) . '" min="1" style="width:60px;"></td>';
        } else {
            echo '<td>' . esc_html($row['player']) . '</td>';
            echo '<td>' . esc_html($row['item']) . '</td>';
            echo '<td>' . esc_html($row['amount']) . '</td>';
        }

        echo '<td>' . ($row['delivered'] ? '✅ YES' : '❌ NO') . '</td>';
        echo '<td>' . esc_html($row['timestamp']) . '</td>';

        echo '<td style="white-space:nowrap;">';
        if ($isEditing) {
            echo '<button class="button button-primary" name="save_edit">Save</button> ';
            echo '<button class="button" onclick="history.go(0);return false;">Cancel</button>';
        } else {
            echo '<button class="button" name="edit_delivery" value="' . esc_attr($id) . '">✏ Edit</button> ';
            if (!$row['delivered']) {
                echo '<button class="button" name="mark_delivered">✔ Mark</button> ';
            } else {
                echo '<button class="button" name="mark_undelivered">❌ Unmark</button> ';
            }
            echo '<button class="button button-secondary" name="delete_delivery" onclick="return confirm(\'Delete this delivery?\')">🗑 Delete</button>';
        }
        echo '</td>';
        echo '</form></tr>';
    }

    echo '</tbody></table></div>';
    $result->free();
    $conn->close();
}
