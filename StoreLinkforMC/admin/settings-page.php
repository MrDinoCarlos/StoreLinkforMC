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
    // === Username policy (Premium-only or Any) ===
    register_setting('storelinkformc_settings', 'storelinkformc_username_policy', [
        'type' => 'string',
        'sanitize_callback' => function($v){ return in_array($v, ['premium','any'], true) ? $v : 'premium'; },
        'default' => 'premium',
    ]);

    // API Token
    register_setting('storelinkformc_tokens', 'storelinkformc_api_token', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);

    // Section: API Token
    add_settings_section(
        'storelinkformc_section_main',
        'API Token',
        null,
        'storelinkformc_tokens'
    );

    add_settings_field(
        'storelinkformc_api_token',
        'Token',
        'storelinkformc_api_token_render',
        'storelinkformc_tokens',
        'storelinkformc_section_main'
    );

    // Section: Username policy
    add_settings_section(
        'storelinkformc_section_usernames',
        'Minecraft Username Policy',
        function () {
            echo '<p>Choose whether to allow only Mojang (premium) usernames, or any username (non-premium mode).</p>';
        },
        'storelinkformc_settings'
    );

    add_settings_field(
        'storelinkformc_username_policy',
        'Allowed usernames',
        'storelinkformc_username_policy_render',
        'storelinkformc_settings',
        'storelinkformc_section_usernames'
    );
}

function storelinkformc_api_token_render() {
    // Mostrar (o crear si no existe) el token
    $token = get_option('storelinkformc_api_token', '');
    if (!$token) {
        $token = wp_generate_password(32, false);
        update_option('storelinkformc_api_token', $token);
    }

    echo '<input type="text" id="api-token-field" class="regular-text" readonly value="' . esc_attr($token) . '" style="cursor:pointer;">';
    echo '<p class="description">Click to copy the token. Use this token in your Minecraft plugin config.</p>';

    // Formulario: Regenerate Token (admin-post)
    echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '" style="margin-top:12px; display:inline-block;">';
    wp_nonce_field('storelinkformc_token_action', 'storelinkformc_token_nonce');
    echo '<input type="hidden" name="action" value="storelinkformc_regen_token">';
    echo '<button type="submit" class="button">🔁 Regenerate Token</button>';
    echo '</form> ';

    // Formulario: Rebuild Table (admin-post)
    echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '" style="margin-top:12px; display:inline-block; margin-left:8px;">';
    wp_nonce_field('storelinkformc_rebuild_table_action', 'storelinkformc_rebuild_table_nonce');
    echo '<input type="hidden" name="action" value="storelinkformc_rebuild_pending">';
    echo '<button type="submit" class="button button-secondary">♻️ Rebuild Table</button>';
    echo '</form>';
}

function storelinkformc_options_page() { ?>
    <div class="wrap">
        <?php
        // Avisos SOLO en esta página
        if (isset($_GET['storelink_notice'])) {
            $msg = sanitize_text_field($_GET['storelink_notice']);
            if ($msg === 'table_ok') {
                echo '<div class="updated notice"><p>✅ Tables created/updated successfully.</p></div>';
            } elseif ($msg === 'checkout_ok') {
                echo '<div class="updated notice"><p>✅ Checkout replaced by classic shortcode.</p></div>';
            } elseif ($msg === 'token_ok') {
                echo '<div class="updated notice"><p>✅ Regenerated token.</p></div>';
            }
        }
        ?>

        <h1>storelinkformc Settings</h1>

        <?php
        // 1) Caja de TOKEN, fuera del form principal (tiene sus propios forms admin-post)
        do_settings_sections('storelinkformc_tokens');
        ?>

        <hr/>

        <h2><?php esc_html_e('Options', 'storelinkformc'); ?></h2>
        <form method="post" action="options.php">
            <?php
            // 2) Form principal SOLO para opciones (policy, etc.)
            settings_fields('storelinkformc_settings');
            do_settings_sections('storelinkformc_settings');
            submit_button(__('Save Settings', 'storelinkformc'));
            ?>
        </form>

        <h2>Maintenance</h2>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('storelinkformc_rebuild_table_action', 'storelinkformc_rebuild_table_nonce'); ?>
            <input type="hidden" name="action" value="storelinkformc_rebuild_pending">
            <p>
                <button type="submit" class="button button-primary">🛠️ Create/Fix tables (DB)</button>
            </p>
            <p class="description">Run dbDelta to (re)create the pending deliveries table.</p>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('storelinkformc_force_checkout_action', 'storelinkformc_force_checkout_nonce'); ?>
            <input type="hidden" name="action" value="storelinkformc_force_checkout_shortcode">
            <p>
                <button type="submit" class="button">🔁 Force Classic Checkout (shortcode)</button>
            </p>
            <p class="description">Replaces the content of the Checkout page with <code>[woocommerce_checkout]</code>.</p>
        </form>

    </div>
<?php }

add_action('admin_enqueue_scripts', 'storelinkformc_enqueue_admin_scripts');
function storelinkformc_enqueue_admin_scripts($hook) {
    if ($hook !== 'toplevel_page_storelinkformc') return;

    // desde /admin/ apuntamos a ../assets/js/admin.js
    wp_register_script(
        'storelinkformc-admin',
        plugins_url('../assets/js/admin.js', __FILE__),
        [],
        '1.0.0',
        true
    );
    wp_enqueue_script('storelinkformc-admin');
}

add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if ($handle === 'storelinkformc-admin') {
        return str_replace('<script', '<script defer', $tag);
    }
    return $tag;
}, 10, 3);

function storelinkformc_username_policy_render() {
    $v = get_option('storelinkformc_username_policy', 'premium');
    ?>
    <label><input type="radio" name="storelinkformc_username_policy" value="premium" <?php checked($v, 'premium'); ?>> Premium only (validate via Mojang)</label><br>
    <label><input type="radio" name="storelinkformc_username_policy" value="any" <?php checked($v, 'any'); ?>> Any username (non-premium)</label>
    <?php
}

// Admin-post: regenerate token
add_action('admin_post_storelinkformc_regen_token', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('storelinkformc_token_action', 'storelinkformc_token_nonce');

    update_option('storelinkformc_api_token', wp_generate_password(32, false));

    wp_safe_redirect( add_query_arg(
        'storelink_notice',
        'token_ok',
        admin_url('admin.php?page=storelinkformc')
    ) );
    exit;
});

// Admin-post: (re)crear tabla pending_deliveries (usa función central del plugin)
add_action('admin_post_storelinkformc_rebuild_pending', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('storelinkformc_rebuild_table_action', 'storelinkformc_rebuild_table_nonce');

    if (function_exists('storelinkformc_create_or_update_tables')) {
        storelinkformc_create_or_update_tables();
    }

    wp_safe_redirect( add_query_arg(
        'storelink_notice', 'table_ok', admin_url('admin.php?page=storelinkformc')
    ) );
    exit;
});

// Admin-post: forzar checkout clásico
add_action('admin_post_storelinkformc_force_checkout_shortcode', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('storelinkformc_force_checkout_action', 'storelinkformc_force_checkout_nonce');

    if (function_exists('storelinkformc_force_classic_checkout')) {
        storelinkformc_force_classic_checkout(true);
    }

    wp_safe_redirect( add_query_arg(
        'storelink_notice', 'checkout_ok', admin_url('admin.php?page=storelinkformc')
    ) );
    exit;
});
