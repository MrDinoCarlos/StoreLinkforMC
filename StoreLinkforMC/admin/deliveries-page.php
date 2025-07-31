<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', 'storelinkformc_add_deliveries_submenu');

function storelinkformc_add_deliveries_submenu() {
    add_submenu_page(
        'storelinkformc',
        'Pending Deliveries',
        'Deliveries',
        'manage_woocommerce',
        'storelinkformc_deliveries',
        'storelinkformc_render_deliveries_page'
    );
}

function storelinkformc_render_deliveries_page() {
    global $wpdb;

    $table = $wpdb->prefix . 'pending_deliveries';
    $editing_id = isset($_POST['edit_delivery']) ? intval($_POST['edit_delivery']) : 0;

    // 💣 RESET TOTAL DE BASE DE DATOS
    if (
        isset($_POST['reset_database']) &&
        isset($_POST['confirm_reset']) &&
        $_POST['confirm_reset'] === 'yes' &&
        current_user_can('manage_woocommerce')
    ) {
        $wpdb->query("TRUNCATE TABLE $table");
        echo '<div class="updated"><p>💣 Database reset completed. All deliveries deleted.</p></div>';
    }

    // 🔄 Limpieza de duplicados
    if (isset($_POST['cleanup_duplicates']) && current_user_can('manage_woocommerce')) {
        $duplicates = $wpdb->get_results("SELECT player, item, order_id, COUNT(*) as total FROM $table GROUP BY player, item, order_id HAVING total > 1");
        $total_removed = 0;

        foreach ($duplicates as $dup) {
            $entries = $wpdb->get_results($wpdb->prepare("SELECT id FROM $table WHERE player = %s AND item = %s AND order_id = %d ORDER BY id ASC", $dup->player, $dup->item, $dup->order_id));
            $to_delete = array_slice($entries, 1);
            foreach ($to_delete as $entry) {
                $wpdb->delete($table, ['id' => $entry->id]);
                $total_removed++;
            }
        }

        echo '<div class="updated"><p>🧹 Deleted Duplicates: ' . esc_html($total_removed) . '</p></div>';
    }

    // 🧹 Borrar todas las entregas pendientes
    if (isset($_POST['clear_all_deliveries']) && current_user_can('manage_woocommerce')) {
        $wpdb->query("DELETE FROM $table WHERE delivered = 0");
        echo '<div class="updated"><p>All pending deliveries deleted.</p></div>';
    }

    // ✏️ Acciones por entrega
    if (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries') &&
        current_user_can('manage_woocommerce')
    ) {

        $id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;

        if ($id > 0) {
            if (isset($_POST['mark_delivered'])) {
                $wpdb->update($table, ['delivered' => 1], ['id' => $id]);
                echo '<div class="updated"><p>Marked as delivered.</p></div>';

                // ✅ Revisar si todas las entregas del pedido están entregadas y marcar pedido como completado
                $order_id = $wpdb->get_var($wpdb->prepare("SELECT order_id FROM $table WHERE id = %d", $id));

                $undelivered = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0",
                    $order_id
                ));

                if ($undelivered == 0 && $order_id) {
                    $order = wc_get_order($order_id);

                    if ($order && in_array($order->get_status(), ['processing', 'on-hold', 'pending'])) {
                        $order->update_status('completed', '✅ Pedido marcado como completado automáticamente después de la entrega.');
                    }
                }

            } elseif (isset($_POST['mark_undelivered'])) {
                $wpdb->update($table, ['delivered' => 0], ['id' => $id]);
                echo '<div class="updated"><p>Marked as undelivered.</p></div>';
            } elseif (isset($_POST['delete_delivery'])) {
                $wpdb->delete($table, ['id' => $id]);
                echo '<div class="updated"><p>Deleted successfully.</p></div>';
            } elseif (isset($_POST['save_edit'])) {
                $player = sanitize_text_field(wp_unslash($_POST['player'] ?? ''));
                $item = sanitize_text_field(wp_unslash($_POST['item'] ?? ''));
                $amount = max(1, intval($_POST['amount'] ?? 1));

                $wpdb->update($table, [
                    'player' => $player,
                    'item' => $item,
                    'amount' => $amount
                ], ['id' => $id]);

                echo '<div class="updated"><p>Updated successfully.</p></div>';
            }
        }
    }

    $filter_status = sanitize_text_field(wp_unslash($_POST['filter_status'] ?? 'all'));
    $filter_player = sanitize_text_field(wp_unslash($_POST['filter_player'] ?? ''));


	$where_clauses = ["1=1"];
	$params = [];

	if ($filter_status === 'pending') {
    	$where_clauses[] = "delivered = %d";
    	$params[] = 0;
	} elseif ($filter_status === 'delivered') {
    	$where_clauses[] = "delivered = %d";
    	$params[] = 1;
	}

	if (!empty($filter_player)) {
    	$where_clauses[] = "player LIKE %s";
    	$params[] = '%' . $wpdb->esc_like($filter_player) . '%';
	}

	$where_sql = implode(' AND ', $where_clauses);

	    // 🚀 Verifica automáticamente pedidos entregados y actualiza su estado si es necesario
        $order_ids = $wpdb->get_col("SELECT DISTINCT order_id FROM $table");

        foreach ($order_ids as $order_id) {
            $undelivered = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0",
                $order_id
            ));

            if ($undelivered == 0 && $order_id) {
                $order = wc_get_order($order_id);

                if ($order && in_array($order->get_status(), ['processing', 'on-hold', 'pending'])) {
                    $order->update_status('completed', '✅ Pedido marcado como completado automáticamente: todas las entregas realizadas.');
                }
            }
        }

        // 👇 AHORA sí va la consulta
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE $where_sql ORDER BY timestamp DESC",
                ...$params
            )
        );


    echo '<div class="wrap"><h1>Pending Deliveries</h1>';

    echo '<form method="post" style="margin-bottom:15px; display:flex; gap:10px;">';
    wp_nonce_field('storelinkformc_manage_deliveries');
    echo '<input type="submit" name="clear_all_deliveries" class="button button-secondary" value="🗑️ Delete all Pending Deliveries">';
    echo '<input type="submit" name="cleanup_duplicates" class="button button-primary" value="🧹 Detect and delete duplicates">';
    echo '</form>';

    echo '<form method="post" style="margin-bottom:15px;">';
    wp_nonce_field('storelinkformc_manage_deliveries');

    echo '<input type="hidden" name="confirm_reset" value="yes">';
    echo '<input type="submit" name="reset_database" class="button button-danger" value="💣 Reset database">';
    echo '</form>';

    echo '<form method="post" style="margin-bottom:15px; display:flex; gap:10px; align-items:end;">';
    wp_nonce_field('storelinkformc_manage_deliveries');
    echo '<label>Status:
            <select name="filter_status">
                <option value="all"' . selected($filter_status, 'all', false) . '>All</option>
                <option value="pending"' . selected($filter_status, 'pending', false) . '>Pending</option>
                <option value="delivered"' . selected($filter_status, 'delivered', false) . '>Delivered</option>
            </select>
        </label>
        <label>Player:
            <input type="text" name="filter_player" value="' . esc_attr($filter_player) . '">
        </label>
        <button class="button button-primary">🔄 Refresh</button>';
    echo '</form>';

    echo '<table class="widefat striped"><thead>
        <tr><th>ID</th><th>Order</th><th>Player</th><th>Item</th><th>Amount</th><th>Delivered</th><th>Date</th><th>Actions</th></tr>
    </thead><tbody>';

    foreach ($rows as $row) {
        $id = intval($row->id);
        $isEditing = ($editing_id === $id);
        echo '<tr><form method="post">';
        wp_nonce_field('storelinkformc_manage_deliveries');

        echo '<input type="hidden" name="delivery_id" value="' . esc_attr($id) . '">';
        echo '<td>' . esc_html($id) . '</td>';
        echo '<td>' . esc_html($row->order_id) . '</td>';

        if ($isEditing) {
            echo '<td><input name="player" value="' . esc_attr($row->player) . '"></td>';
            echo '<td><input name="item" value="' . esc_attr($row->item) . '"></td>';
            echo '<td><input name="amount" type="number" value="' . esc_attr($row->amount) . '" min="1" style="width:60px;"></td>';
        } else {
            echo '<td>' . esc_html($row->player) . '</td>';
            echo '<td>' . esc_html($row->item) . '</td>';
            echo '<td>' . esc_html($row->amount) . '</td>';
        }

        echo '<td>' . ($row->delivered ? '✅ YES' : '❌ NO') . '</td>';
        echo '<td>' . esc_html($row->timestamp) . '</td><td style="white-space:nowrap;">';

        if ($isEditing) {
            echo '<button class="button button-primary" name="save_edit">Save</button> ';
            echo '<button type="button" class="button" onclick="window.location.href=window.location.href;">Cancel</button>';

        } else {
            echo '<button class="button" name="edit_delivery" value="' . esc_attr($id) . '">✏ Edit</button> ';
            echo '<button class="button" name="' . ($row->delivered ? 'mark_undelivered' : 'mark_delivered') . '">' . ($row->delivered ? '❌ Unmark' : '✔ Mark') . '</button> ';
        }

        echo '</td></form></tr>';
    }

    echo '</tbody></table></div>';
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'storelinkformc_page_storelinkformc_deliveries') return;

    wp_register_script(
        'storelinkformc-deliveries',
        plugins_url('../assets/js/deliveries.js', __FILE__),
        [],
        '1.0.0',
        true
    );
    wp_enqueue_script('storelinkformc-deliveries');
});
