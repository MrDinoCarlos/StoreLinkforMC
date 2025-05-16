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

    add_submenu_page(
        'minecraftstorelink',
        'Sync Roles',
        'Sync Roles',
        'manage_options',
        'minecraftstorelink_sync_roles',
        function () {
            require_once plugin_dir_path(__FILE__) . 'sync-roles-page.php';
            minecraftstorelink_sync_roles_page();
        }
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
    if (
        isset($_POST['regenerate_token']) &&
        check_admin_referer('minecraftstorelink_token_action', 'minecraftstorelink_token_nonce') &&
        current_user_can('manage_options')
    ) {
        $new_token = wp_generate_password(32, false);
        update_option('minecraftstorelink_api_token', $new_token);
        echo '<div class="updated"><p><strong>Token regenerated successfully.</strong></p></div>';
    }

    if (
        isset($_POST['rebuild_pending_table']) &&
        check_admin_referer('minecraftstorelink_rebuild_table_action', 'minecraftstorelink_rebuild_table_nonce') &&
        current_user_can('manage_options')
    ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pending_deliveries';
        $charset = $wpdb->get_charset_collate();

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
            ) $charset;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        echo '<div class="updated"><p><strong>✅ Table rebuilt successfully.</strong></p></div>';
    }

    $token = get_option('minecraftstorelink_api_token', '');
    if (!$token) {
        $token = wp_generate_password(32, false);
        update_option('minecraftstorelink_api_token', $token);
    }

    echo '<input type="text" id="api-token-field" class="regular-text" readonly value="' . esc_attr($token) . '" style="cursor:pointer;">';
    echo '<p class="description">Click to copy the token. Use this token in your Minecraft plugin config.</p>';

    echo '<form method="post" style="margin-top: 15px;">';
    wp_nonce_field('minecraftstorelink_token_action', 'minecraftstorelink_token_nonce');
    echo '<input type="hidden" name="regenerate_token" value="1">';
    echo '<button type="submit" class="button">🔁 Regenerate Token</button>';
    echo '</form>';

    echo '<form method="post" style="margin-top:10px;">';
    wp_nonce_field('minecraftstorelink_rebuild_table_action', 'minecraftstorelink_rebuild_table_nonce');
    echo '<button type="submit" name="rebuild_pending_table" class="button button-secondary" onclick="return confirm(\'Rebuild the table? This will ERASE current deliveries.\')">♻️ Rebuild Table</button>';
    echo '</form>';

    echo '<script>
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
    </script>';
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
    </div>
    <?php
}
