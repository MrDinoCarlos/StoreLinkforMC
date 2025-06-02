<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', 'storelinkformc_add_admin_menu');
add_action('admin_init', 'storelinkformc_settings_init');

function storelinkformc_add_admin_menu() {
    add_menu_page(
        'storelinkformc Settings',
        'storelinkformc',
        'manage_options',
        'storelinkformc',
        'storelinkformc_options_page',
        'dashicons-admin-network'
    );

    add_submenu_page(
        'storelinkformc',
        'Sync Roles',
        'Sync Roles',
        'manage_options',
        'storelinkformc_sync_roles',
        function () {
            require_once plugin_dir_path(__FILE__) . 'sync-roles-page.php';
            storelinkformc_sync_roles_page();
        }
    );
}

function storelinkformc_settings_init() {
    register_setting('storelinkformc_settings', 'storelinkformc_api_token', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);

    add_settings_section(
        'storelinkformc_section_main',
        'API Token',
        null,
        'storelinkformc_settings'
    );

    add_settings_field(
        'storelinkformc_api_token',
        'Token',
        'storelinkformc_api_token_render',
        'storelinkformc_settings',
        'storelinkformc_section_main'
    );
}

function storelinkformc_api_token_render() {
    if (
        isset($_POST['regenerate_token']) &&
        check_admin_referer('storelinkformc_token_action', 'storelinkformc_token_nonce') &&
        current_user_can('manage_options')
    ) {
        $new_token = wp_generate_password(32, false);
        update_option('storelinkformc_api_token', $new_token);
        echo '<div class="updated"><p><strong>Token regenerated successfully.</strong></p></div>';
    }

    if (
        isset($_POST['rebuild_pending_table']) &&
        check_admin_referer('storelinkformc_rebuild_table_action', 'storelinkformc_rebuild_table_nonce') &&
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

    $token = get_option('storelinkformc_api_token', '');
    if (!$token) {
        $token = wp_generate_password(32, false);
        update_option('storelinkformc_api_token', $token);
    }

    echo '<input type="text" id="api-token-field" class="regular-text" readonly value="' . esc_attr($token) . '" style="cursor:pointer;">';
    echo '<p class="description">Click to copy the token. Use this token in your Minecraft plugin config.</p>';

    echo '<form method="post" style="margin-top: 15px;">';
    wp_nonce_field('storelinkformc_token_action', 'storelinkformc_token_nonce');
    echo '<input type="hidden" name="regenerate_token" value="1">';
    echo '<button type="submit" class="button">🔁 Regenerate Token</button>';
    echo '</form>';

    echo '<form method="post" style="margin-top:10px;">';
    wp_nonce_field('storelinkformc_rebuild_table_action', 'storelinkformc_rebuild_table_nonce');
    echo '<button type="submit" name="rebuild_pending_table" class="button button-secondary" onclick="return confirm(\'Rebuild the table? This will ERASE current deliveries.\')">♻️ Rebuild Table</button>';
    echo '</form>';

}

function storelinkformc_options_page() {
    ?>
    <div class="wrap">
        <h1>storelinkformc Settings</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('storelinkformc_settings');
            do_settings_sections('storelinkformc_settings');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_enqueue_scripts', 'storelinkformc_enqueue_admin_scripts');
function storelinkformc_enqueue_admin_scripts($hook) {
    if ($hook !== 'toplevel_page_storelinkformc') return;

    wp_register_script('storelinkformc-admin', '', [], null, true);
    wp_enqueue_script('storelinkformc-admin');
    wp_add_inline_script('storelinkformc-admin', "
        document.addEventListener('DOMContentLoaded', function () {
            const tokenField = document.getElementById('api-token-field');
            if (tokenField) {
                tokenField.addEventListener('click', function () {
                    navigator.clipboard.writeText(tokenField.value).then(() => {
                        alert('Token copied to clipboard!');
                    }).catch(() => {
                        alert('Failed to copy token.');
                    });
                });
            }
        });
    ");
}
