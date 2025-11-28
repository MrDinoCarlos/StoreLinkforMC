<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'storelinkformc_add_deliveries_submenu');

function storelinkformc_add_deliveries_submenu() {
    add_submenu_page(
        'storelinkformc',
        __('Pending Deliveries', 'StoreLinkforMC'),
        __('Deliveries', 'StoreLinkforMC'),
        'manage_woocommerce',
        'storelinkformc_deliveries',
        'storelinkformc_render_deliveries_page'
    );
}

function storelinkformc_render_deliveries_page() {
    global $wpdb;

    $table = $wpdb->prefix . 'pending_deliveries';

    $editing_id = 0;
    if (isset($_POST['edit_delivery'])) {
        $editing_id = (int) wp_unslash($_POST['edit_delivery']);
    }

    // 💣 RESET TOTAL DE BASE DE DATOS
    if (
        isset($_POST['reset_database'], $_POST['confirm_reset'], $_POST['_wpnonce']) &&
        'yes' === $_POST['confirm_reset'] &&
        current_user_can('manage_woocommerce') &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries')
    ) {
        $wpdb->query("TRUNCATE TABLE $table");
        echo '<div class="updated notice"><p>' . esc_html__('💣 Database reset completed. All deliveries deleted.', 'StoreLinkforMC') . '</p></div>';
    }

    // 🔄 Limpieza de duplicados
    if (
        isset($_POST['cleanup_duplicates'], $_POST['_wpnonce']) &&
        current_user_can('manage_woocommerce') &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries')
    ) {
        $duplicates = $wpdb->get_results(
            "SELECT player, item, order_id, COUNT(*) as total
             FROM $table
             GROUP BY player, item, order_id
             HAVING total > 1"
        );
        $total_removed = 0;

        foreach ($duplicates as $dup) {
            $entries = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM $table WHERE player = %s AND item = %s AND order_id = %d ORDER BY id ASC",
                    $dup->player,
                    $dup->item,
                    $dup->order_id
                )
            );
            $to_delete = array_slice($entries, 1);
            foreach ($to_delete as $entry) {
                $wpdb->delete($table, ['id' => (int) $entry->id]);
                $total_removed++;
            }
        }

        echo '<div class="updated notice"><p>' .
             sprintf(
                 /* translators: %d is the number of deleted duplicates. */
                 esc_html__('🧹 Deleted duplicates: %d', 'StoreLinkforMC'),
                 (int) $total_removed
             ) .
             '</p></div>';
    }

    // 🧹 Borrar todas las entregas pendientes
    if (
        isset($_POST['clear_all_deliveries'], $_POST['_wpnonce']) &&
        current_user_can('manage_woocommerce') &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries')
    ) {
        $wpdb->query("DELETE FROM $table WHERE delivered = 0");
        echo '<div class="updated notice"><p>' . esc_html__('All pending deliveries deleted.', 'StoreLinkforMC') . '</p></div>';
    }

    // ✏️ Acciones por entrega (marcar, editar, borrar)
    if (
        isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries') &&
        current_user_can('manage_woocommerce')
    ) {
        $id = isset($_POST['delivery_id']) ? (int) wp_unslash($_POST['delivery_id']) : 0;

        if ($id > 0) {
            if (isset($_POST['mark_delivered'])) {
                $wpdb->update($table, ['delivered' => 1], ['id' => $id]);
                echo '<div class="updated notice"><p>' . esc_html__('Marked as delivered.', 'StoreLinkforMC') . '</p></div>';

                // ✅ Revisar si todas las entregas del pedido están entregadas y marcar pedido como completado
                $order_id = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT order_id FROM $table WHERE id = %d", $id)
                );

                $undelivered = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0",
                        $order_id
                    )
                );

                if (0 === $undelivered && $order_id) {
                    $order = wc_get_order($order_id);

                    if ($order && in_array($order->get_status(), ['processing', 'on-hold', 'pending'], true)) {
                        $order->update_status(
                            'completed',
                            __('✅ Order automatically marked as completed after all deliveries were sent.', 'StoreLinkforMC')
                        );
                    }
                }
            } elseif (isset($_POST['mark_undelivered'])) {
                $wpdb->update($table, ['delivered' => 0], ['id' => $id]);
                echo '<div class="updated notice"><p>' . esc_html__('Marked as undelivered.', 'StoreLinkforMC') . '</p></div>';

            } elseif (isset($_POST['save_edit'])) {
                $player_raw = $_POST['player'] ?? '';
                $item_raw   = $_POST['item'] ?? '';
                $amount_raw = $_POST['amount'] ?? 1;

                $player = sanitize_text_field(wp_unslash($player_raw));
                $item   = sanitize_text_field(wp_unslash($item_raw));
                $amount = max(1, (int) wp_unslash($amount_raw));

                $wpdb->update(
                    $table,
                    [
                        'player' => $player,
                        'item'   => $item,
                        'amount' => $amount,
                    ],
                    ['id' => $id]
                );

                echo '<div class="updated notice"><p>' . esc_html__('Updated successfully.', 'StoreLinkforMC') . '</p></div>';
            }

            // 🗑 Delete delivery + WooCommerce order
            if (isset($_POST['delete_delivery'])) {
                // 1) Read the order_id for this delivery
                $order_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT order_id FROM $table WHERE id = %d",
                        $id
                    )
                );

                // 2) Delete WooCommerce order (permanent). Use false if you prefer trash.
                if ($order_id && function_exists('wc_get_order')) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $deleted = $order->delete(false); // true = permanently delete, false = move to trash
                        if (is_wp_error($deleted)) {
                            /* translators: 1: WooCommerce order ID, 2: error message. */
                            $notice = sprintf(
                                esc_html__('Could not delete WooCommerce order #%1$d: %2$s', 'storelinkformc'),
                                (int) $order_id,
                                $deleted->get_error_message()
                            );
                            echo '<div class="notice notice-error"><p>' . $notice . '</p></div>';
                        }
                    }
                }

                // 3) Delete the delivery row from plugin table
                $deleted_row = $wpdb->delete($table, ['id' => $id], ['%d']);
                if ($deleted_row === false) {
                    echo '<div class="notice notice-error"><p>' .
                         sprintf(
                             /* translators: %d: delivery ID. */
                             esc_html__('Could not delete delivery record (ID %d).', 'storelinkformc'),
                             (int) $id
                         ) .
                         '</p></div>';
                } else {
                    echo '<div class="updated"><p>' . esc_html__('Delivery record and (if it existed) the WooCommerce order were deleted.', 'storelinkformc') . '</p></div>';
                }
            }
        }
    }

    $filter_status_raw = $_POST['filter_status'] ?? 'all';
    $filter_player_raw = $_POST['filter_player'] ?? '';

    $filter_status = sanitize_text_field(wp_unslash($filter_status_raw));
    $filter_player = sanitize_text_field(wp_unslash($filter_player_raw));

    $where_clauses = ['1=1'];
    $params        = [];

    if ('pending' === $filter_status) {
        $where_clauses[] = 'delivered = %d';
        $params[]        = 0;
    } elseif ('delivered' === $filter_status) {
        $where_clauses[] = 'delivered = %d';
        $params[]        = 1;
    }

    if (!empty($filter_player)) {
        $where_clauses[] = 'player LIKE %s';
        $params[]        = '%' . $wpdb->esc_like($filter_player) . '%';
    }

    $where_sql = implode(' AND ', $where_clauses);

    // 🚀 Verifica automáticamente pedidos entregados y actualiza su estado si es necesario
    $order_ids = $wpdb->get_col("SELECT DISTINCT order_id FROM $table");

    foreach ($order_ids as $order_id) {
        $order_id    = (int) $order_id;
        $undelivered = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0",
                $order_id
            )
        );

        if (0 === $undelivered && $order_id) {
            $order = wc_get_order($order_id);

            if ($order && in_array($order->get_status(), ['processing', 'on-hold', 'pending'], true)) {
                $order->update_status(
                    'completed',
                    __('✅ Order automatically marked as completed: all deliveries have been sent.', 'StoreLinkforMC')
                );
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

    echo '<div class="wrap"><h1>' . esc_html__('Pending Deliveries', 'StoreLinkforMC') . '</h1>';

    // Form: Delete all pending + delete duplicates
    echo '<form method="post" style="margin-bottom:15px; display:flex; gap:10px;">';
    wp_nonce_field('storelinkformc_manage_deliveries');
    echo '<input type="submit" name="clear_all_deliveries" class="button button-secondary" value="' . esc_attr__('🗑️ Delete all Pending Deliveries', 'StoreLinkforMC') . '">';
    echo '<input type="submit" name="cleanup_duplicates" class="button button-primary" value="' . esc_attr__('🧹 Detect and delete duplicates', 'StoreLinkforMC') . '">';
    echo '</form>';

    // Form: Reset database
    echo '<form method="post" style="margin-bottom:15px;">';
    wp_nonce_field('storelinkformc_manage_deliveries');
    echo '<input type="hidden" name="confirm_reset" value="yes">';
    echo '<input type="submit" name="reset_database" class="button button-danger" value="' . esc_attr__('💣 Reset database', 'StoreLinkforMC') . '">';
    echo '</form>';

    // Form: Filters
    echo '<form method="post" style="margin-bottom:15px; display:flex; gap:10px; align-items:end;">';
    wp_nonce_field('storelinkformc_manage_deliveries');

    echo '<label>' . esc_html__('Status:', 'StoreLinkforMC') . '
            <select name="filter_status">
                <option value="all"' . selected($filter_status, 'all', false) . '>' . esc_html__('All', 'StoreLinkforMC') . '</option>
                <option value="pending"' . selected($filter_status, 'pending', false) . '>' . esc_html__('Pending', 'StoreLinkforMC') . '</option>
                <option value="delivered"' . selected($filter_status, 'delivered', false) . '>' . esc_html__('Delivered', 'StoreLinkforMC') . '</option>
            </select>
        </label>
        <label>' . esc_html__('Player:', 'StoreLinkforMC') . '
            <input type="text" name="filter_player" value="' . esc_attr($filter_player) . '">
        </label>
        <button class="button button-primary">🔄 ' . esc_html__('Refresh', 'StoreLinkforMC') . '</button>';
    echo '</form>';

    echo '<table class="widefat striped"><thead>
        <tr>
            <th>' . esc_html__('ID', 'StoreLinkforMC') . '</th>
            <th>' . esc_html__('Order', 'StoreLinkforMC') . '</th>
            <th>' . esc_html__('Player', 'StoreLinkforMC') . '</th>
            <th>' . esc_html__('Item', 'StoreLinkforMC') . '</th>
            <th>' . esc_html__('Amount', 'StoreLinkforMC') . '</th>
            <th>' . esc_html__('Delivered', 'StoreLinkforMC') . '</th>
            <th>' . esc_html__('Date', 'StoreLinkforMC') . '</th>
            <th>' . esc_html__('Actions', 'StoreLinkforMC') . '</th>
        </tr>
    </thead><tbody>';

    foreach ($rows as $row) {
        $id        = (int) $row->id;
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

        echo '<td>' . ($row->delivered ? '✅ ' . esc_html__('YES', 'StoreLinkforMC') : '❌ ' . esc_html__('NO', 'StoreLinkforMC')) . '</td>';
        echo '<td>' . esc_html($row->timestamp) . '</td><td style="white-space:nowrap;">';

        if ($isEditing) {
            echo '<button class="button button-primary" name="save_edit">' . esc_html__('Save', 'StoreLinkforMC') . '</button> ';
            echo '<button type="button" class="button" onclick="window.location.href=window.location.href;">' . esc_html__('Cancel', 'StoreLinkforMC') . '</button>';

        } else {
            echo '<button class="button" name="edit_delivery" value="' . esc_attr($id) . '">✏ ' . esc_html__('Edit', 'StoreLinkforMC') . '</button> ';
            echo '<button class="button" name="' . ($row->delivered ? 'mark_undelivered' : 'mark_delivered') . '">'
                . ($row->delivered ? '❌ ' . esc_html__('Unmark', 'StoreLinkforMC') : '✔ ' . esc_html__('Mark', 'StoreLinkforMC'))
                . '</button> ';

            $confirm_msg = esc_js(
                __('This will permanently delete the delivery record and the WooCommerce order. Continue?', 'storelinkformc')
            );
            echo '<button class="button button-secondary" name="delete_delivery" value="' . esc_attr($id) . '" onclick="return confirm(\'' . $confirm_msg . '\');">🗑 Delete</button> ';

        }

        echo '</td></form></tr>';
    }

    echo '</tbody></table></div>';
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'storelinkformc_page_storelinkformc_deliveries') {
        return;
    }

    wp_register_script(
        'storelinkformc-deliveries',
        plugins_url('../assets/js/deliveries.js', __FILE__),
        [],
        '1.0.0',
        true
    );
    wp_enqueue_script('storelinkformc-deliveries');
});
