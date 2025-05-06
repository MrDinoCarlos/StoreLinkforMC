<?php

add_action('admin_menu', 'woostorelink_add_admin_menu');
add_action('admin_init', 'woostorelink_settings_init');

function woostorelink_add_admin_menu() {
    add_menu_page(
        'WooStoreLink Settings',
        'WooStoreLink',
        'manage_options',
        'woostorelink',
        'woostorelink_options_page',
        'dashicons-admin-network'
    );
}

function woostorelink_settings_init() {
    register_setting('woostorelink_settings', 'woostorelink_db_settings');

    add_settings_section(
        'woostorelink_section_main',
        'Database Configuration',
        null,
        'woostorelink_settings'
    );

    $fields = [
        'host'     => 'MySQL Host',
        'port'     => 'Port',
        'database' => 'Database',
        'user'     => 'Username',
        'password' => 'Password',
        'timezone' => 'Timezone (e.g. Europe/Madrid)'
    ];

    foreach ($fields as $id => $label) {
        add_settings_field(
            'woostorelink_' . $id,
            $label,
            function () use ($id) {
                $options = get_option('woostorelink_db_settings');
                $value = isset($options[$id]) ? esc_attr($options[$id]) : '';
                $type = ($id === 'password') ? 'password' : 'text';

                if ($id === 'password') {
                    echo "<input id='woostorelink_pwd' type='{$type}' name='woostorelink_db_settings[{$id}]' value='{$value}' class='regular-text'>";
                    echo " <button type='button' onclick=\"togglePassword()\">👁️</button>";
                    echo "<script>
                            function togglePassword() {
                                const input = document.getElementById('woostorelink_pwd');
                                input.type = input.type === 'password' ? 'text' : 'password';
                            }
                          </script>";
                } else {
                    echo "<input type='{$type}' name='woostorelink_db_settings[{$id}]' value='{$value}' class='regular-text'>";
                }
            },
            'woostorelink_settings',
            'woostorelink_section_main'
        );
    }
}

function woostorelink_options_page() {
    global $wpdb;
    $config = get_option('woostorelink_db_settings', []);
    $timezone = $config['timezone'] ?? (get_option('timezone_string') ?: 'UTC');
    $table = $wpdb->prefix . 'pending_deliveries';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

    // Botón de recreación de tabla en base remota
    if (isset($_POST['woostorelink_recreate_table']) && current_user_can('manage_options')) {
        if (function_exists('woostorelink_create_remote_table') && woostorelink_create_remote_table()) {
            echo '<div class="updated"><p><strong>WooStoreLink:</strong> Remote table created successfully.</p></div>';
        } else {
            echo '<div class="error"><p><strong>WooStoreLink:</strong> Failed to create remote table. Check your credentials and permissions.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>WooStoreLink Configuration</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('woostorelink_settings');
            do_settings_sections('woostorelink_settings');
            submit_button('Save settings');
            ?>
        </form>

        <h2>Connection details for Minecraft plugin</h2>
        <div style="background:#f5f5f5; padding:10px; font-family:monospace;">
host: <?php echo esc_html($config['host'] ?? ''); ?><br>
port: <?php echo esc_html($config['port'] ?? '3306'); ?><br>
database: <?php echo esc_html($config['database'] ?? ''); ?><br>
user: <?php echo esc_html($config['user'] ?? ''); ?><br>
password: ******** <button type="button" onclick="togglePasswordView()">Show</button><br>
table: <?php echo esc_html($table); ?> (<?php echo $table_exists ? '✅ Found (local WP DB)' : '❌ Not Found (local WP DB)'; ?>)<br>
timezone: <?php echo esc_html($timezone); ?>
        </div>

        <script>
        function togglePasswordView() {
            const btn = event.target;
            const passLine = btn.previousSibling;
            if (btn.textContent === 'Show') {
                passLine.textContent = '<?php echo esc_js($config['password'] ?? ''); ?>';
                btn.textContent = 'Hide';
            } else {
                passLine.textContent = '********';
                btn.textContent = 'Show';
            }
        }
        </script>

        <hr>
        <h2>Advanced Tools</h2>
        <form method="post">
            <?php submit_button('Recreate Database Table', 'secondary', 'woostorelink_recreate_table'); ?>
        </form>
    </div>
    <?php
}
