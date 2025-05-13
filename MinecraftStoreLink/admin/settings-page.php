<?php

add_action('admin_menu', 'minecraftstorelink_add_admin_menu');
add_action('admin_init', 'minecraftstorelink_settings_init');

function minecraftstorelink_add_admin_menu() {
    add_menu_page(
        'MinecraftStoreLink Settings',
        'MinecraftStoreLink',
        'manage_options',
        'minecraftstorelink',
        'minecraftstorelink_options_page',
        'dashicons-admin-network'
    );
}

function minecraftstorelink_settings_init() {
    register_setting('minecraftstorelink_settings', 'minecraftstorelink_api_token', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);

    add_settings_section(
        'minecraftstorelink_section_main',
        'API Token',
        null,
        'minecraftstorelink_settings'
    );

    add_settings_field(
        'minecraftstorelink_api_token',
        'Token',
        'minecraftstorelink_api_token_render',
        'minecraftstorelink_settings',
        'minecraftstorelink_section_main'
    );
}

function minecraftstorelink_api_token_render() {
    if (isset($_POST['regenerate_token']) && current_user_can('manage_options')) {
        $new_token = wp_generate_password(32, false);
        update_option('minecraftstorelink_api_token', $new_token);
        echo '<div class="updated"><p><strong>Token regenerated successfully.</strong> Don’t forget to update it in your Minecraft plugin config.</p></div>';
    }

    global $wpdb;
    $table = $wpdb->prefix . 'pending_deliveries';

    // Show table structure
    if (isset($_POST['show_table_info'])) {
        $structure = $wpdb->get_results("DESCRIBE $table");
        echo '<div class="updated"><p><strong>🧬 Current table structure:</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>';
        foreach ($structure as $col) {
            echo '<tr>';
            echo '<td>' . esc_html($col->Field) . '</td>';
            echo '<td>' . esc_html($col->Type) . '</td>';
            echo '<td>' . esc_html($col->Null) . '</td>';
            echo '<td>' . esc_html($col->Key) . '</td>';
            echo '<td>' . esc_html($col->Default) . '</td>';
            echo '<td>' . esc_html($col->Extra) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // Rebuild table
    if (isset($_POST['rebuild_pending_table']) && current_user_can('manage_options')) {
        $sql = "
            DROP TABLE IF EXISTS $table;
            CREATE TABLE $table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT,
                player VARCHAR(255),
                item VARCHAR(255),
                amount INT DEFAULT 1,
                delivered TINYINT(1) DEFAULT 0,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            ) {$wpdb->get_charset_collate()};
        ";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        echo '<div class="updated"><p>✅ <code>pending_deliveries</code> table rebuilt successfully.</p></div>';
    }

    $token = get_option('minecraftstorelink_api_token', '');
    if (!$token) {
        $token = wp_generate_password(32, false);
        update_option('minecraftstorelink_api_token', $token);
    }

    echo '<input type="text" id="api-token-field" class="regular-text" readonly value="' . esc_attr($token) . '" style="cursor:pointer;">';
    echo '<p class="description">Click to copy the token. Use this token in your Minecraft plugin config.</p>';

    echo '
        <form method="post" style="margin-top: 15px;">
            <input type="hidden" name="regenerate_token" value="1">
            <button type="submit" id="regen-btn" class="button">🔁 Regenerate Token</button>
        </form>

        <form method="post" style="margin-top:10px;">
            <button type="submit" name="show_table_info" class="button">🔍 View Table Structure</button>
            <button type="submit" name="rebuild_pending_table" class="button button-secondary" onclick="return confirm(\'Are you sure you want to RECREATE the table? This will erase all current deliveries.\')">♻️ Rebuild Table</button>
        </form>

        <div style="margin-top:20px; padding:10px; background:#fff3cd; border:1px solid #ffeeba;">
            <strong>ℹ️ Tip:</strong> To inspect or manually edit the database, install a plugin like <strong>WP phpMyAdmin</strong> or <strong>Adminer</strong>.
        </div>

        <script>
        document.addEventListener("DOMContentLoaded", function () {
            const tokenField = document.getElementById("api-token-field");
            tokenField.addEventListener("click", function () {
                navigator.clipboard.writeText(tokenField.value).then(() => {
                    alert("Token copied to clipboard!");
                }).catch(() => {
                    alert("Failed to copy token.");
                });
            });
        });
        </script>
    ';
}

function minecraftstorelink_options_page() {
    ?>
    <div class="wrap">
        <h1>MinecraftStoreLink Settings</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('minecraftstorelink_settings');
            do_settings_sections('minecraftstorelink_settings');
            submit_button('Save Settings');
            ?>
        </form>

        <hr>
        <h2>Admin Tools</h2>

        <?php if (isset($_POST['flush_cache']) && current_user_can('manage_options')):
            wp_cache_flush();
            echo '<div class="updated"><p><strong>✔ WordPress cache flushed.</strong> REST API will now return fresh data.</p></div>';
        endif; ?>

        <?php if (isset($_POST['reset_table']) && current_user_can('manage_options')):
            global $wpdb;
            $table = $wpdb->prefix . 'pending_deliveries';

            $wpdb->query("DROP TABLE IF EXISTS $table");
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                player VARCHAR(50) NOT NULL,
                item VARCHAR(100) NOT NULL,
                amount INT UNSIGNED DEFAULT 1,
                delivered TINYINT(1) DEFAULT 0,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            echo '<div class="updated"><p><strong>✔ `pending_deliveries` table reset successfully.</strong></p></div>';
        endif; ?>

        <form method="post" style="margin-top: 20px;">
            <input type="submit" name="flush_cache" class="button" value="🧹 Flush WordPress Cache">
            <input type="submit" name="reset_table" class="button button-secondary" value="🧨 Reset pending_deliveries Table" onclick="return confirm('Are you sure? This will erase ALL pending deliveries.')">
        </form>

        <p class="description">Use these tools to make sure the JSON API matches your database state. If you see stuck deliveries, try flushing cache first. Only rebuild the table as a last resort.</p>

        <p><strong>🛠 You can install one of these plugins to access the database manually:</strong></p>
        <ul>
            <li><a href="https://wordpress.org/plugins/wp-database-browser/" target="_blank">WP Database Browser</a></li>
            <li><a href="https://wordpress.org/plugins/advanced-database-cleaner/" target="_blank">Advanced Database Cleaner</a></li>
        </ul>
    </div>
    <?php
}
