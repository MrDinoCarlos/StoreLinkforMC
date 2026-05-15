<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'storelinkformc_add_deliveries_submenu');

function storelinkformc_add_deliveries_submenu() {
    add_submenu_page(
        'storelinkformc',
        __('Pending Deliveries', 'storelinkformc'),
        __('Deliveries', 'storelinkformc'),
        'manage_woocommerce',
        'storelinkformc_deliveries',
        'storelinkformc_render_deliveries_page'
    );
}

function storelinkformc_render_deliveries_page() {
    global $wpdb;

    $table = esc_sql($wpdb->prefix . 'pending_deliveries');

    // Detect optional columns (backwards compatible)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $has_expired_col = (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'expired'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $has_expires_col = (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'expires_at'));

    $editing_id = 0;
    if (
        isset($_POST['edit_delivery'], $_POST['_wpnonce']) &&
        current_user_can('manage_woocommerce') &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries')
    ) {
        $editing_id = absint(wp_unslash($_POST['edit_delivery']));
    }

    // 💣 RESET TOTAL DE BASE DE DATOS
    if (
        isset($_POST['reset_database'], $_POST['confirm_reset'], $_POST['_wpnonce']) &&
        'yes' === sanitize_text_field(wp_unslash($_POST['confirm_reset'])) &&
        current_user_can('manage_woocommerce') &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries')
    ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("TRUNCATE TABLE $table");
        echo '<div class="updated notice"><p>' . esc_html__('💣 Database reset completed. All deliveries deleted.', 'storelinkformc') . '</p></div>';
    }

    // 🔄 Limpieza de duplicados
    if (
        isset($_POST['cleanup_duplicates'], $_POST['_wpnonce']) &&
        current_user_can('manage_woocommerce') &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries')
    ) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $duplicates = $wpdb->get_results(
            "SELECT player, item, order_id, COUNT(*) as total
             FROM $table
             GROUP BY player, item, order_id
             HAVING total > 1"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $total_removed = 0;

        foreach ($duplicates as $dup) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $entries = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM $table WHERE player = %s AND item = %s AND order_id = %d ORDER BY id ASC",
                    $dup->player,
                    $dup->item,
                    (int) $dup->order_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            $to_delete = array_slice($entries, 1);
            foreach ($to_delete as $entry) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->delete($table, ['id' => (int) $entry->id], ['%d']);
                $total_removed++;
            }
        }

        echo '<div class="updated notice"><p>' .
            sprintf(
                /* translators: %d is the number of deleted duplicates. */
                esc_html__('🧹 Deleted duplicates: %d', 'storelinkformc'),
                (int) $total_removed
            ) .
            '</p></div>';
    }

    // 🧹 Borrar todas las entregas pendientes (solo delivered=0)
    if (
        isset($_POST['clear_all_deliveries'], $_POST['_wpnonce']) &&
        current_user_can('manage_woocommerce') &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries')
    ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DELETE FROM $table WHERE delivered = 0");
        echo '<div class="updated notice"><p>' . esc_html__('All pending deliveries deleted.', 'storelinkformc') . '</p></div>';
    }

    // ✏️ Acciones por entrega (marcar, editar, borrar)
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    if (
        'POST' === $request_method &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries') &&
        current_user_can('manage_woocommerce')
    ) {
        $id = isset($_POST['delivery_id']) ? absint(wp_unslash($_POST['delivery_id'])) : 0;

        if ($id > 0) {

            // ✅ Mark delivered
            if (isset($_POST['mark_delivered'])) {
                $data = ['delivered' => 1];

                // Si existía flag expired, al entregar lo limpiamos
                if ($has_expired_col) {
                    $data['expired'] = 0;
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update($table, $data, ['id' => $id], ['%d'], ['%d']);
                echo '<div class="updated notice"><p>' . esc_html__('Marked as delivered.', 'storelinkformc') . '</p></div>';

                // ✅ Revisar si todas las entregas del pedido están listas y completar el pedido
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $order_id = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT order_id FROM $table WHERE id = %d", $id)
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

                if ($order_id && function_exists('wc_get_order')) {
                    if ($has_expired_col && $has_expires_col) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expired = 0 OR expired IS NULL) AND (expires_at IS NULL OR expires_at >= %s)", $order_id, current_time('mysql')));
                    } elseif ($has_expired_col) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expired = 0 OR expired IS NULL)", $order_id));
                    } elseif ($has_expires_col) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expires_at IS NULL OR expires_at >= %s)", $order_id, current_time('mysql')));
                    } else {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0", $order_id));
                    }

                    if (0 === $undelivered) {
                        $order = wc_get_order($order_id);
                        if ($order && in_array($order->get_status(), ['processing', 'on-hold', 'pending'], true)) {
                            $order->update_status(
                                'completed',
                                __('✅ Order automatically marked as completed after all deliveries were sent.', 'storelinkformc')
                            );
                        }
                    }
                }

            // ✅ Mark undelivered
            } elseif (isset($_POST['mark_undelivered'])) {

                // Solo cambiamos delivered, NO tocamos expired aquí
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update($table, ['delivered' => 0], ['id' => $id], ['%d'], ['%d']);
                echo '<div class="updated notice"><p>' . esc_html__('Marked as undelivered.', 'storelinkformc') . '</p></div>';

            // ✅ Un-expire (restaurar vencida)
            } elseif (isset($_POST['unexpire_delivery'])) {

                $data = [];

                // Quita flag expired si existe
                if ($has_expired_col) {
                    $data['expired'] = 0;
                }

                // Si existe expires_at, renueva el vencimiento usando el setting del plugin
                if ($has_expires_col) {
                    $opt = get_option('storelinkformc_delivery_expiration', ['seconds' => 2592000]);
                    $seconds = (is_array($opt) && !empty($opt['seconds'])) ? (int) $opt['seconds'] : 2592000;

                    $new_ts = current_time('timestamp') + max(1, $seconds);

                    // Guardamos en formato MySQL (hora del WP site)
                    $data['expires_at'] = gmdate('Y-m-d H:i:s', $new_ts + (get_option('gmt_offset') * HOUR_IN_SECONDS));
                }

                if (!empty($data)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update($table, $data, ['id' => $id], null, ['%d']);
                }

                echo '<div class="updated notice"><p>' . esc_html__('Delivery restored (un-expired).', 'storelinkformc') . '</p></div>';

            // ✅ Save edit
            } elseif (isset($_POST['save_edit'])) {

                $player = isset($_POST['player']) ? sanitize_text_field(wp_unslash($_POST['player'])) : '';
                $item   = isset($_POST['item']) ? sanitize_text_field(wp_unslash($_POST['item'])) : '';
                $amount = isset($_POST['amount']) ? max(1, absint(wp_unslash($_POST['amount']))) : 1;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $table,
                    [
                        'player' => $player,
                        'item'   => $item,
                        'amount' => $amount,
                    ],
                    ['id' => $id],
                    ['%s', '%s', '%d'],
                    ['%d']
                );

                echo '<div class="updated notice"><p>' . esc_html__('Updated successfully.', 'storelinkformc') . '</p></div>';
            }

            // 🗑 Delete delivery + WooCommerce order
            if (isset($_POST['delete_delivery'])) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $order_id = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT order_id FROM $table WHERE id = %d", $id)
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

                // Delete WooCommerce order
                if ($order_id && function_exists('wc_get_order')) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $deleted = $order->delete(false); // false = trash
                        if (is_wp_error($deleted)) {
                            $notice = sprintf(
                                /* translators: 1: WooCommerce order ID, 2: error message. */
                                esc_html__('Could not delete WooCommerce order #%1$d: %2$s', 'storelinkformc'),
                                (int) $order_id,
                                $deleted->get_error_message()
                            );
                            echo '<div class="notice notice-error"><p>' . esc_html($notice) . '</p></div>';
                        }
                    }
                }

                // Delete delivery row
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

    // ---- Filtros UI ----
    $filter_status = isset($_POST['filter_status']) ? sanitize_text_field(wp_unslash($_POST['filter_status'])) : 'all';
    $filter_player = isset($_POST['filter_player']) ? sanitize_text_field(wp_unslash($_POST['filter_player'])) : '';

    // 🚀 Verifica automáticamente pedidos entregados y actualiza su estado si es necesario
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $order_ids = $wpdb->get_col("SELECT DISTINCT order_id FROM $table");

    foreach ($order_ids as $order_id) {
        $order_id = (int) $order_id;

        if ($has_expired_col && $has_expires_col) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expired = 0 OR expired IS NULL) AND (expires_at IS NULL OR expires_at >= %s)", $order_id, current_time('mysql')));
        } elseif ($has_expired_col) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expired = 0 OR expired IS NULL)", $order_id));
        } elseif ($has_expires_col) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expires_at IS NULL OR expires_at >= %s)", $order_id, current_time('mysql')));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0", $order_id));
        }

        if (0 === $undelivered && $order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && in_array($order->get_status(), ['processing', 'on-hold', 'pending'], true)) {
                $order->update_status(
                    'completed',
                    __('✅ Order automatically marked as completed: all deliveries have been sent.', 'storelinkformc')
                );
            }
        }
    }

    // Query principal de la tabla
    if (!empty($filter_player) && in_array($filter_status, ['pending', 'delivered'], true)) {
        $delivered = ('delivered' === $filter_status) ? 1 : 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE delivered = %d AND player LIKE %s ORDER BY timestamp DESC", $delivered, '%' . $wpdb->esc_like($filter_player) . '%'));
    } elseif (in_array($filter_status, ['pending', 'delivered'], true)) {
        $delivered = ('delivered' === $filter_status) ? 1 : 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE delivered = %d ORDER BY timestamp DESC", $delivered));
    } elseif (!empty($filter_player)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE player LIKE %s ORDER BY timestamp DESC", '%' . $wpdb->esc_like($filter_player) . '%'));
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY timestamp DESC");
    }

    // ---- Render ----
    echo '<div class="wrap"><h1>' . esc_html__('Pending Deliveries', 'storelinkformc') . '</h1>';

    // Form: Delete all pending + delete duplicates
    echo '<form method="post" style="margin-bottom:15px; display:flex; gap:10px;">';
    wp_nonce_field('storelinkformc_manage_deliveries');
    echo '<input type="submit" name="clear_all_deliveries" class="button button-secondary" value="' . esc_attr__('🗑️ Delete all Pending Deliveries', 'storelinkformc') . '">';
    echo '<input type="submit" name="cleanup_duplicates" class="button button-primary" value="' . esc_attr__('🧹 Detect and delete duplicates', 'storelinkformc') . '">';
    echo '</form>';

    // Form: Reset database
    echo '<form method="post" style="margin-bottom:15px;">';
    wp_nonce_field('storelinkformc_manage_deliveries');
    echo '<input type="hidden" name="confirm_reset" value="yes">';
    echo '<input type="submit" name="reset_database" class="button button-danger" value="' . esc_attr__('💣 Reset database', 'storelinkformc') . '">';
    echo '</form>';

    // Form: Filters
    echo '<form method="post" style="margin-bottom:15px; display:flex; gap:10px; align-items:end;">';
    wp_nonce_field('storelinkformc_manage_deliveries');

    echo '<label>' . esc_html__('Status:', 'storelinkformc') . '
            <select name="filter_status">
                <option value="all"' . selected($filter_status, 'all', false) . '>' . esc_html__('All', 'storelinkformc') . '</option>
                <option value="pending"' . selected($filter_status, 'pending', false) . '>' . esc_html__('Pending', 'storelinkformc') . '</option>
                <option value="delivered"' . selected($filter_status, 'delivered', false) . '>' . esc_html__('Delivered', 'storelinkformc') . '</option>
            </select>
        </label>
        <label>' . esc_html__('Player:', 'storelinkformc') . '
            <input type="text" name="filter_player" value="' . esc_attr($filter_player) . '">
        </label>
        <button class="button button-primary">🔄 ' . esc_html__('Refresh', 'storelinkformc') . '</button>';
    echo '</form>';

    echo '<table class="widefat striped"><thead>
        <tr>
            <th>' . esc_html__('ID', 'storelinkformc') . '</th>
            <th>' . esc_html__('Order', 'storelinkformc') . '</th>
            <th>' . esc_html__('Player', 'storelinkformc') . '</th>
            <th>' . esc_html__('Item', 'storelinkformc') . '</th>
            <th>' . esc_html__('Amount', 'storelinkformc') . '</th>
            <th>' . esc_html__('Delivered', 'storelinkformc') . '</th>
            <th>' . esc_html__('Date', 'storelinkformc') . '</th>
            <th>' . esc_html__('Actions', 'storelinkformc') . '</th>
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

        // Status: YES / NO / EXPIRED
        $is_delivered = !empty($row->delivered);

        $is_expired = false;
        if (!$is_delivered) {
            if ($has_expired_col && isset($row->expired) && (int) $row->expired === 1) {
                $is_expired = true;
            } elseif ($has_expires_col && !empty($row->expires_at)) {
                $exp_ts = strtotime((string) $row->expires_at);
                if ($exp_ts && $exp_ts < current_time('timestamp')) {
                    $is_expired = true;
                }
            }
        }

        if ($is_delivered) {
            $status_html = '<span style="color:green;">✅ ' . esc_html__('YES', 'storelinkformc') . '</span>';
        } elseif ($is_expired) {
            $status_html = '<span style="color:#a16207;">⏰ ' . esc_html__('EXPIRED', 'storelinkformc') . '</span>';
        } else {
            $status_html = '<span style="color:red;">❌ ' . esc_html__('NO', 'storelinkformc') . '</span>';
        }

        echo '<td>' . wp_kses($status_html, ['span' => ['style' => true]]) . '</td>';
        echo '<td>' . esc_html($row->timestamp) . '</td><td style="white-space:nowrap;">';

        if ($isEditing) {
            echo '<button class="button button-primary" name="save_edit">' . esc_html__('Save', 'storelinkformc') . '</button> ';
            echo '<button type="button" class="button" onclick="window.location.href=window.location.href;">' . esc_html__('Cancel', 'storelinkformc') . '</button>';
        } else {
            echo '<button class="button" name="edit_delivery" value="' . esc_attr($id) . '">✏ ' . esc_html__('Edit', 'storelinkformc') . '</button> ';

            // Si está expirado y NO está entregado, ocultamos "Mark" para evitar líos: primero hay que Un-expire.
            if (!$is_expired) {
                echo '<button class="button" name="' . ($is_delivered ? 'mark_undelivered' : 'mark_delivered') . '">'
                    . ($is_delivered ? '❌ ' . esc_html__('Unmark', 'storelinkformc') : '✔ ' . esc_html__('Mark', 'storelinkformc'))
                    . '</button> ';
            } else {
                // Si está delivered, nunca debería estar expired; por seguridad, dejamos unmark disponible
                if ($is_delivered) {
                    echo '<button class="button" name="mark_undelivered">❌ ' . esc_html__('Unmark', 'storelinkformc') . '</button> ';
                }
            }

            $confirm_msg = __('This will permanently delete the delivery record and the WooCommerce order. Continue?', 'storelinkformc');
            echo '<button class="button button-secondary" name="delete_delivery" value="' . esc_attr($id) . '" onclick="return confirm(' . esc_attr(wp_json_encode($confirm_msg)) . ');">🗑 Delete</button> ';

            if ($is_expired && !$is_delivered) {
                $confirm_unexpire = __('Restore this expired delivery so it can be delivered again?', 'storelinkformc');
                echo '<button class="button" name="unexpire_delivery" onclick="return confirm(' . esc_attr(wp_json_encode($confirm_unexpire)) . ');">⏳↩ ' . esc_html__('Un-expire', 'storelinkformc') . '</button> ';
            }
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
