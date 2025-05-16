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
    $token = get_option('minecraftstorelink_api_token', '');
    if (!$token) {
        $token = wp_generate_password(32, false);
        update_option('minecraftstorelink_api_token', $token);
    }

    echo '<input type="text" id="api-token-field" class="regular-text" readonly value="' . esc_attr($token) . '" style="cursor:pointer;">';
    echo '<p class="description">Click to copy the token. Use this token in your Minecraft plugin config.</p>';

    echo '
        <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top: 15px;">
            <input type="hidden" name="action" value="minecraftstorelink_regen_token">
            ' . wp_nonce_field('minecraftstorelink_token_nonce', '_wpnonce', true, false) . '
            <button type="submit" class="button">🔁 Regenerate Token</button>
        </form>

        <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">
            <input type="hidden" name="action" value="minecraftstorelink_rebuild_table">
            ' . wp_nonce_field('minecraftstorelink_table_nonce', '_wpnonce', true, false) . '
            <button type="submit" class="button button-secondary" onclick="return confirm(\'Are you sure? This will erase all pending deliveries.\')">♻️ Rebuild Table</button>
        </form>

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
    </div>
    <?php
}

add_action('admin_post_minecraftstorelink_regen_token', 'minecraftstorelink_handle_regen_token');
function minecraftstorelink_handle_regen_token() {
    if (!current_user_can('manage_options') || !check_admin_referer('minecraftstorelink_token_nonce')) {
        wp_die('Unauthorized');
    }

    $new_token = wp_generate_password(32, false);
    update_option('minecraftstorelink_api_token', $new_token);
    wp_redirect(admin_url('admin.php?page=minecraftstorelink&token_regenerated=1'));
    exit;
}

add_action('admin_post_minecraftstorelink_rebuild_table', 'minecraftstorelink_handle_rebuild_table');
function minecraftstorelink_handle_rebuild_table() {
    if (!current_user_can('manage_options') || !check_admin_referer('minecraftstorelink_table_nonce')) {
        wp_die('Unauthorized');
    }

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

    wp_redirect(admin_url('admin.php?page=minecraftstorelink&table_rebuilt=1'));
    exit;
}
