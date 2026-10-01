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

    // Bulk actions
    if (
        isset($_POST['apply_bulk_action'], $_POST['bulk_action'], $_POST['_wpnonce']) &&
        current_user_can('manage_woocommerce') &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_manage_deliveries')
    ) {
        $bulk_action = sanitize_key(wp_unslash($_POST['bulk_action']));
        $selected_ids = [];

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (isset($_POST['selected_deliveries']) && is_array($_POST['selected_deliveries'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            foreach (wp_unslash($_POST['selected_deliveries']) as $raw_id) {
                $selected_id = absint($raw_id);
                if ($selected_id) {
                    $selected_ids[] = $selected_id;
                }
            }
        }

        $selected_ids = array_values(array_unique($selected_ids));
        $changed = 0;
        $orders_to_check = [];

        if (empty($selected_ids)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Select at least one delivery before applying a bulk action.', 'storelinkformc') . '</p></div>';
        } else {
            foreach ($selected_ids as $bulk_id) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $bulk_order_id = (int) $wpdb->get_var($wpdb->prepare("SELECT order_id FROM $table WHERE id = %d", $bulk_id));
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

                if ('mark_delivered' === $bulk_action) {
                    $data = ['delivered' => 1];
                    if ($has_expired_col) {
                        $data['expired'] = 0;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $result = $wpdb->update($table, $data, ['id' => $bulk_id], null, ['%d']);
                    if (false !== $result) {
                        $changed++;
                        if ($bulk_order_id) {
                            $orders_to_check[] = $bulk_order_id;
                        }
                    }
                } elseif ('mark_undelivered' === $bulk_action) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $result = $wpdb->update($table, ['delivered' => 0], ['id' => $bulk_id], ['%d'], ['%d']);
                    if (false !== $result) {
                        $changed++;
                    }
                } elseif ('unexpire' === $bulk_action) {
                    $data = [];
                    if ($has_expired_col) {
                        $data['expired'] = 0;
                    }
                    if ($has_expires_col) {
                        $opt = get_option('storelinkformc_delivery_expiration', ['seconds' => 2592000]);
                        $seconds = (is_array($opt) && !empty($opt['seconds'])) ? (int) $opt['seconds'] : 2592000;
                        $new_ts = current_time('timestamp') + max(1, $seconds);
                        $data['expires_at'] = gmdate('Y-m-d H:i:s', $new_ts + (get_option('gmt_offset') * HOUR_IN_SECONDS));
                    }
                    if (!empty($data)) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $result = $wpdb->update($table, $data, ['id' => $bulk_id], null, ['%d']);
                        if (false !== $result) {
                            $changed++;
                        }
                    }
                } elseif ('delete' === $bulk_action) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $result = $wpdb->delete($table, ['id' => $bulk_id], ['%d']);
                    if (false !== $result) {
                        $changed++;
                    }
                }
            }

            foreach (array_unique($orders_to_check) as $order_id_to_check) {
                if ($has_expired_col && $has_expires_col) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expired = 0 OR expired IS NULL) AND (expires_at IS NULL OR expires_at >= %s)", $order_id_to_check, current_time('mysql')));
                } elseif ($has_expired_col) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expired = 0 OR expired IS NULL)", $order_id_to_check));
                } elseif ($has_expires_col) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0 AND (expires_at IS NULL OR expires_at >= %s)", $order_id_to_check, current_time('mysql')));
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $undelivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE order_id = %d AND delivered = 0", $order_id_to_check));
                }

                if (0 === $undelivered && function_exists('wc_get_order')) {
                    $order = wc_get_order($order_id_to_check);
                    if ($order && in_array($order->get_status(), ['processing', 'on-hold', 'pending'], true)) {
                        $order->update_status(
                            'completed',
                            __('Order automatically marked as completed after all deliveries were sent.', 'storelinkformc')
                        );
                    }
                }
            }

            if (in_array($bulk_action, ['mark_delivered', 'mark_undelivered', 'unexpire', 'delete'], true)) {
                echo '<div class="updated notice"><p>' .
                    sprintf(
                        /* translators: %d: number of changed deliveries. */
                        esc_html__('Bulk action completed. Deliveries affected: %d', 'storelinkformc'),
                        (int) $changed
                    ) .
                    '</p></div>';
            }
        }
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
        foreach (['mark_delivered', 'mark_undelivered', 'unexpire_delivery', 'delete_delivery', 'save_edit'] as $action_key) {
            if (!$id && isset($_POST[$action_key])) {
                $id = absint(wp_unslash($_POST[$action_key]));
            }
        }

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

            // Delete only the bridge record. Store orders are never removed here.
            if (isset($_POST['delete_delivery'])) {
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
                    echo '<div class="updated"><p>' . esc_html__('Delivery record deleted. The WooCommerce order was kept.', 'storelinkformc') . '</p></div>';
                }
            }
        }
    }

    // ---- Filtros UI ----
    $filter_status = isset($_POST['filter_status']) ? sanitize_text_field(wp_unslash($_POST['filter_status'])) : 'all';
    $filter_player = isset($_POST['filter_player']) ? sanitize_text_field(wp_unslash($_POST['filter_player'])) : '';

    // Keep the screen responsive on large stores. Filters are applied before the cap.
    if (!empty($filter_player) && in_array($filter_status, ['pending', 'delivered'], true)) {
        $delivered = ('delivered' === $filter_status) ? 1 : 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE delivered = %d AND player LIKE %s ORDER BY timestamp DESC LIMIT 250", $delivered, '%' . $wpdb->esc_like($filter_player) . '%'));
    } elseif (in_array($filter_status, ['pending', 'delivered'], true)) {
        $delivered = ('delivered' === $filter_status) ? 1 : 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE delivered = %d ORDER BY timestamp DESC LIMIT 250", $delivered));
    } elseif (!empty($filter_player)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE player LIKE %s ORDER BY timestamp DESC LIMIT 250", '%' . $wpdb->esc_like($filter_player) . '%'));
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY timestamp DESC LIMIT 250");
    }

    $total_count = count($rows);
    $pending_count = 0;
    $delivered_count = 0;
    $expired_count = 0;
    foreach ($rows as $row) {
        $row_delivered = !empty($row->delivered);
        $row_expired = false;
        if (!$row_delivered) {
            if ($has_expired_col && isset($row->expired) && (int) $row->expired === 1) {
                $row_expired = true;
            } elseif ($has_expires_col && !empty($row->expires_at)) {
                $exp_ts = strtotime((string) $row->expires_at);
                $row_expired = $exp_ts && $exp_ts < current_time('timestamp');
            }
        }
        if ($row_delivered) {
            $delivered_count++;
        } elseif ($row_expired) {
            $expired_count++;
        } else {
            $pending_count++;
        }
    }

    // ---- Render ----
    echo '<div class="wrap storelinkformc-admin">';
    echo '<div class="storelinkformc-admin-header"><div>';
    echo '<span class="storelinkformc-eyebrow">' . esc_html__('Live operations', 'storelinkformc') . '</span>';
    echo '<h1>' . esc_html__('Pending Deliveries', 'storelinkformc') . '</h1>';
    echo '<p class="storelinkformc-admin-subtitle">' . esc_html__('Review Minecraft deliveries, update delivery status, and process many records at once with bulk actions.', 'storelinkformc') . '</p>';
    echo '</div><div class="storelinkformc-admin-stats" aria-hidden="true">';
    echo '<div class="storelinkformc-stat"><strong>' . esc_html((string) $total_count) . '</strong><span>' . esc_html__('visible', 'storelinkformc') . '</span></div>';
    echo '<div class="storelinkformc-stat"><strong>' . esc_html((string) $pending_count) . '</strong><span>' . esc_html__('pending', 'storelinkformc') . '</span></div>';
    echo '<div class="storelinkformc-stat"><strong>' . esc_html((string) $delivered_count) . '</strong><span>' . esc_html__('delivered', 'storelinkformc') . '</span></div>';
    echo '<div class="storelinkformc-stat"><strong>' . esc_html((string) $expired_count) . '</strong><span>' . esc_html__('expired', 'storelinkformc') . '</span></div>';
    echo '</div></div>';

    echo '<form method="post" class="storelinkformc-panel">';
    wp_nonce_field('storelinkformc_manage_deliveries');
    echo '<div class="storelinkformc-panel-header"><div><h2>' . esc_html__('Deliveries queue', 'storelinkformc') . '</h2><p>' . esc_html__('Filter, edit, and apply actions to selected deliveries.', 'storelinkformc') . '</p></div><span class="storelinkformc-live-indicator"><i></i>' . esc_html__('Operational', 'storelinkformc') . '</span></div>';
    echo '<div class="storelinkformc-toolbar">';
    echo '<label>' . esc_html__('Status', 'storelinkformc') . '
            <select name="filter_status">
                <option value="all"' . selected($filter_status, 'all', false) . '>' . esc_html__('All', 'storelinkformc') . '</option>
                <option value="pending"' . selected($filter_status, 'pending', false) . '>' . esc_html__('Pending', 'storelinkformc') . '</option>
                <option value="delivered"' . selected($filter_status, 'delivered', false) . '>' . esc_html__('Delivered', 'storelinkformc') . '</option>
            </select>
        </label>
        <label>' . esc_html__('Player', 'storelinkformc') . '
            <input type="search" name="filter_player" value="' . esc_attr($filter_player) . '" placeholder="' . esc_attr__('Minecraft username', 'storelinkformc') . '">
        </label>
        <button class="button button-primary">' . esc_html__('Refresh', 'storelinkformc') . '</button>';
    echo '</div>';

    echo '<div class="storelinkformc-bulkbar">';
    echo '<select name="bulk_action">
            <option value="">' . esc_html__('Bulk actions', 'storelinkformc') . '</option>
            <option value="mark_delivered">' . esc_html__('Mark delivered', 'storelinkformc') . '</option>
            <option value="mark_undelivered">' . esc_html__('Mark undelivered', 'storelinkformc') . '</option>
            <option value="unexpire">' . esc_html__('Un-expire', 'storelinkformc') . '</option>
            <option value="delete">' . esc_html__('Delete delivery records', 'storelinkformc') . '</option>
        </select>';
    echo '<button class="button" name="apply_bulk_action" value="1">' . esc_html__('Apply', 'storelinkformc') . '</button>';
    echo '<span class="storelinkformc-muted">' . esc_html__('Use the checkboxes to select multiple deliveries.', 'storelinkformc') . '</span>';
    echo '</div>';

    if (empty($rows)) {
        echo '<div class="storelinkformc-panel-body"><div class="storelinkformc-empty">' . esc_html__('No deliveries match the current filters.', 'storelinkformc') . '</div></div>';
    } else {
        echo '<div class="storelinkformc-table-scroll"><table class="widefat fixed storelinkformc-table storelinkformc-deliveries-table"><thead>
            <tr>
                <th class="storelinkformc-check-column"><input type="checkbox" id="storelinkformc-select-all-deliveries"></th>
                <th>' . esc_html__('ID', 'storelinkformc') . '</th>
                <th>' . esc_html__('Order', 'storelinkformc') . '</th>
                <th>' . esc_html__('Player', 'storelinkformc') . '</th>
                <th>' . esc_html__('Item', 'storelinkformc') . '</th>
                <th>' . esc_html__('Amount', 'storelinkformc') . '</th>
                <th>' . esc_html__('Status', 'storelinkformc') . '</th>
                <th>' . esc_html__('Date', 'storelinkformc') . '</th>
                <th class="storelinkformc-actions-column">' . esc_html__('Actions', 'storelinkformc') . '</th>
            </tr>
        </thead><tbody>';

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $isEditing = ($editing_id === $id);
            $is_delivered = !empty($row->delivered);
            $is_expired = false;
            if (!$is_delivered) {
                if ($has_expired_col && isset($row->expired) && (int) $row->expired === 1) {
                    $is_expired = true;
                } elseif ($has_expires_col && !empty($row->expires_at)) {
                    $exp_ts = strtotime((string) $row->expires_at);
                    $is_expired = $exp_ts && $exp_ts < current_time('timestamp');
                }
            }

            if ($is_delivered) {
                $status_html = '<span class="storelinkformc-status storelinkformc-status-delivered">' . esc_html__('Delivered', 'storelinkformc') . '</span>';
            } elseif ($is_expired) {
                $status_html = '<span class="storelinkformc-status storelinkformc-status-expired">' . esc_html__('Expired', 'storelinkformc') . '</span>';
            } else {
                $status_html = '<span class="storelinkformc-status storelinkformc-status-pending">' . esc_html__('Pending', 'storelinkformc') . '</span>';
            }

            echo '<tr>';
            echo '<td><input type="checkbox" class="storelinkformc-delivery-checkbox" name="selected_deliveries[]" value="' . esc_attr($id) . '"></td>';
            echo '<td><span class="storelinkformc-id-pill">' . esc_html((string) $id) . '</span></td>';
            $order_url = '';
            if ($row->order_id && function_exists('wc_get_order')) {
                $order = wc_get_order((int) $row->order_id);
                if ($order && method_exists($order, 'get_edit_order_url')) {
                    $order_url = $order->get_edit_order_url();
                }
            }
            echo '<td>' . ($order_url
                ? '<a class="storelinkformc-order-link" href="' . esc_url($order_url) . '">#' . esc_html((string) $row->order_id) . '<span class="dashicons dashicons-external"></span></a>'
                : '#' . esc_html((string) $row->order_id)) . '</td>';

            if ($isEditing) {
                echo '<td><input name="player" value="' . esc_attr($row->player) . '"></td>';
                echo '<td><input name="item" value="' . esc_attr($row->item) . '"></td>';
                echo '<td><input name="amount" type="number" value="' . esc_attr($row->amount) . '" min="1" style="width:80px;"></td>';
            } else {
                $head_url = 'https://mc-heads.net/avatar/' . rawurlencode((string) $row->player) . '/40';
                echo '<td><div class="storelinkformc-player storelinkformc-player-compact"><img src="' . esc_url($head_url) . '" width="36" height="36" loading="lazy" alt=""><strong>' . esc_html($row->player) . '</strong></div></td>';
                echo '<td><div class="storelinkformc-delivery-item"><strong>' . esc_html($row->item) . '</strong>';
                if (!empty($row->product_id)) {
                    echo '<span>' . esc_html__('Product', 'storelinkformc') . ' #' . esc_html((string) $row->product_id);
                    if (!empty($row->variation_id)) echo ' · ' . esc_html__('Variation', 'storelinkformc') . ' #' . esc_html((string) $row->variation_id);
                    echo '</span>';
                }
                echo '</div></td>';
                echo '<td><span class="storelinkformc-amount">×' . esc_html((string) $row->amount) . '</span></td>';
            }

            echo '<td>' . wp_kses($status_html, ['span' => ['class' => true]]) . '</td>';
            $timestamp = strtotime((string) $row->timestamp);
            $relative = $timestamp ? human_time_diff($timestamp, current_time('timestamp')) : '';
            echo '<td><span class="storelinkformc-date"><strong>' . esc_html($relative ? sprintf(__('%s ago', 'storelinkformc'), $relative) : (string) $row->timestamp) . '</strong><span>' . esc_html((string) $row->timestamp) . '</span></span></td>';
            echo '<td><div class="storelinkformc-row-actions">';

            if ($isEditing) {
                echo '<button class="button button-primary" name="save_edit" value="' . esc_attr($id) . '">' . esc_html__('Save', 'storelinkformc') . '</button>';
                echo '<button type="button" class="button" onclick="window.location.href=window.location.href;">' . esc_html__('Cancel', 'storelinkformc') . '</button>';
            } else {
                echo '<button class="button" name="edit_delivery" value="' . esc_attr($id) . '">' . esc_html__('Edit', 'storelinkformc') . '</button>';

                if (!$is_expired) {
                    echo '<button class="button" name="' . ($is_delivered ? 'mark_undelivered' : 'mark_delivered') . '" value="' . esc_attr($id) . '">'
                        . ($is_delivered ? esc_html__('Unmark', 'storelinkformc') : esc_html__('Mark', 'storelinkformc'))
                        . '</button>';
                } elseif ($is_delivered) {
                    echo '<button class="button" name="mark_undelivered" value="' . esc_attr($id) . '">' . esc_html__('Unmark', 'storelinkformc') . '</button>';
                }

                if ($is_expired && !$is_delivered) {
                    echo '<button class="button" name="unexpire_delivery" value="' . esc_attr($id) . '">' . esc_html__('Un-expire', 'storelinkformc') . '</button>';
                }

                echo '<button class="button button-secondary" name="delete_delivery" value="' . esc_attr($id) . '">' . esc_html__('Delete', 'storelinkformc') . '</button>';
            }

            echo '</div></td></tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</form>';

    echo '<div class="storelinkformc-panel storelinkformc-danger-zone">';
    echo '<div class="storelinkformc-panel-header"><div><h2>' . esc_html__('Maintenance', 'storelinkformc') . '</h2><p>' . esc_html__('Use these actions carefully. They affect many records at once.', 'storelinkformc') . '</p></div></div>';
    echo '<div class="storelinkformc-panel-body">';
    echo '<form method="post" style="display:flex;flex-wrap:wrap;gap:10px;">';
    wp_nonce_field('storelinkformc_manage_deliveries');
    echo '<input type="submit" name="cleanup_duplicates" class="button button-primary" value="' . esc_attr__('Detect and delete duplicates', 'storelinkformc') . '">';
    echo '<input type="submit" name="clear_all_deliveries" class="button button-secondary" value="' . esc_attr__('Delete all pending deliveries', 'storelinkformc') . '">';
    echo '<input type="hidden" name="confirm_reset" value="yes">';
    echo '<input type="submit" name="reset_database" class="button button-secondary" value="' . esc_attr__('Reset database', 'storelinkformc') . '">';
    echo '</form></div></div>';
    echo '</div>';
}
