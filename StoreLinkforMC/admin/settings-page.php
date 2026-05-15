<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'storelinkformc_add_admin_menu');
add_action('admin_init', 'storelinkformc_settings_init');

function storelinkformc_add_admin_menu() {
    add_menu_page(
        'StoreLinkforMC Settings',
        'storelinkformc',
        'manage_options',
        'storelinkformc',
        'storelinkformc_options_page',
        'dashicons-cart'
    );

    add_submenu_page(
        'storelinkformc',
        __('Sync Roles', 'storelinkformc'),
        __('Sync Roles', 'storelinkformc'),
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
    register_setting(
        'storelinkformc_settings',
        'storelinkformc_username_policy',
        [
            'type'              => 'string',
            'sanitize_callback' => function ($v) {
                return in_array($v, ['premium', 'any'], true) ? $v : 'premium';
            },
            'default'           => 'premium',
        ]
    );

    // === Force link or not ===
    register_setting(
        'storelinkformc_settings',
        'storelinkformc_force_link',
        [
            'type'              => 'string',
            'sanitize_callback' => function ($v) {
                return $v === 'no' ? 'no' : 'yes';
            },
            'default'           => 'yes',
        ]
    );

    // === Delivery expiration (unclaimed deliveries) ===
    register_setting(
        'storelinkformc_settings',
        'storelinkformc_delivery_expiration',
        [
            'type'              => 'array',
            'sanitize_callback' => function ($v) {
                $allowed_units = ['seconds','minutes','hours','days','weeks','months','years'];

                $value = 30;
                $unit  = 'days';

                if (is_array($v)) {
                    if (isset($v['value'])) $value = (int) $v['value'];
                    if (isset($v['unit']))  $unit  = sanitize_text_field($v['unit']);
                }

                if ($value < 1) $value = 1;
                if (!in_array($unit, $allowed_units, true)) $unit = 'days';

                $unit_seconds = [
                    'seconds' => 1,
                    'minutes' => 60,
                    'hours'   => 3600,
                    'days'    => 86400,
                    'weeks'   => 604800,
                    'months'  => 2592000,   // 30 days
                    'years'   => 31536000,  // 365 days
                ];

                $seconds = (int) ($value * $unit_seconds[$unit]);

                // Clamp: 1 second .. 999 years
                $max_seconds = 999 * $unit_seconds['years'];
                if ($seconds > $max_seconds) {
                    $seconds = $max_seconds;
                    $unit    = 'years';
                    $value   = 999;
                }

                return [
                    'value'   => $value,
                    'unit'    => $unit,
                    'seconds' => $seconds,
                ];
            },
            'default'           => [
                'value'   => 30,
                'unit'    => 'days',
                'seconds' => 2592000,
            ],
        ]
    );

    $expiration = get_option('storelinkformc_delivery_expiration', null);
    if (null === $expiration || false === $expiration || storelinkformc_is_legacy_short_delivery_expiration($expiration)) {
        update_option(
            'storelinkformc_delivery_expiration',
            [
                'value'   => 30,
                'unit'    => 'days',
                'seconds' => 2592000,
            ]
        );
    }


    // API Token
    register_setting(
        'storelinkformc_tokens',
        'storelinkformc_api_token',
        [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]
    );

    // Section: API Token
    add_settings_section(
        'storelinkformc_section_main',
        __('API Token', 'storelinkformc'),
        null,
        'storelinkformc_tokens'
    );

    add_settings_field(
        'storelinkformc_api_token',
        __('Token', 'storelinkformc'),
        'storelinkformc_api_token_render',
        'storelinkformc_tokens',
        'storelinkformc_section_main'
    );

    // Section: Username policy
    add_settings_section(
        'storelinkformc_section_usernames',
        __('Minecraft Username Policy', 'storelinkformc'),
        function () {
            echo '<p>' . esc_html__('Choose whether to allow only Mojang (premium) usernames, or any username (non-premium mode).', 'storelinkformc') . '</p>';
        },
        'storelinkformc_settings'
    );

    add_settings_field(
        'storelinkformc_force_link',
        __('Force Minecraft account linking', 'storelinkformc'),
        function () {
            $val = get_option('storelinkformc_force_link', 'yes');
            ?>
            <!-- Para que al desmarcar se guarde "no" -->
            <input type="hidden" name="storelinkformc_force_link" value="no" />
            <label>
                <input type="checkbox" name="storelinkformc_force_link" value="yes" <?php checked($val, 'yes'); ?> />
                <?php esc_html_e('Require a linked account or use the “Gift” option (default).', 'storelinkformc'); ?>
            </label>
            <p class="description">
                <?php esc_html_e('If unchecked, checkout will ask directly for a Minecraft username (still validated against the Premium/Any policy).', 'storelinkformc'); ?>
            </p>
            <?php
        },
        'storelinkformc_settings',
        'storelinkformc_section_usernames'
    );

    add_settings_field(
        'storelinkformc_username_policy',
        __('Allowed usernames', 'storelinkformc'),
        'storelinkformc_username_policy_render',
        'storelinkformc_settings',
        'storelinkformc_section_usernames'
    );

    // Section: Deliveries
    add_settings_section(
        'storelinkformc_section_deliveries',
        __('Deliveries', 'storelinkformc'),
        function () {
            echo '<p>' . esc_html__('Configure how long unclaimed deliveries remain available before expiring.', 'storelinkformc') . '</p>';
        },
        'storelinkformc_settings'
    );

    add_settings_field(
        'storelinkformc_delivery_expiration',
        __('Unclaimed delivery expiration', 'storelinkformc'),
        'storelinkformc_delivery_expiration_render',
        'storelinkformc_settings',
        'storelinkformc_section_deliveries'
    );

}

function storelinkformc_is_legacy_short_delivery_expiration($expiration): bool {
    if (is_array($expiration)) {
        $unit    = isset($expiration['unit']) ? sanitize_text_field($expiration['unit']) : '';
        $value   = isset($expiration['value']) ? (int) $expiration['value'] : 0;
        $seconds = isset($expiration['seconds']) ? (int) $expiration['seconds'] : 0;

        return 'seconds' === $unit && $value <= 10 && $seconds <= 10;
    }

    return is_numeric($expiration) && (int) $expiration <= 10;
}

function storelinkformc_api_token_render() {
    // Mostrar (o crear si no existe) el token
    $token = get_option('storelinkformc_api_token', '');
    if (!$token) {
        $token = wp_generate_password(32, false);
        update_option('storelinkformc_api_token', $token);
    }

    echo '<input type="text" id="api-token-field" class="regular-text" readonly value="' . esc_attr($token) . '" style="cursor:pointer;">';
    echo '<p class="description">' . esc_html__('Click to copy the token. Use this token in your Minecraft plugin config.', 'storelinkformc') . '</p>';

    // Formulario: Regenerate Token (admin-post)
    echo '<form method="post" class="storelinkformc-form-regenerate-token" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px; display:inline-block;">';
    wp_nonce_field('storelinkformc_token_action', 'storelinkformc_token_nonce');
    echo '<input type="hidden" name="action" value="storelinkformc_regen_token">';
    echo '<button type="submit" class="button">🔁 ' . esc_html__('Regenerate Token', 'storelinkformc') . '</button>';
    echo '</form> ';

    // Formulario: Rebuild Table (admin-post)
    echo '<form method="post" class="storelinkformc-form-rebuild-table" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px; display:inline-block; margin-left:8px;">';
    wp_nonce_field('storelinkformc_rebuild_table_action', 'storelinkformc_rebuild_table_nonce');
    echo '<input type="hidden" name="action" value="storelinkformc_rebuild_pending">';
    echo '<button type="submit" class="button button-secondary">♻️ ' . esc_html__('Rebuild Table', 'storelinkformc') . '</button>';
    echo '</form>';
}

function storelinkformc_options_page() { ?>
    <div class="wrap">
        <?php
        // Avisos SOLO en esta página
        // Read-only redirect notice from admin-post actions.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['storelink_notice'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $msg = sanitize_text_field(wp_unslash($_GET['storelink_notice']));
            if ($msg === 'table_ok') {
                echo '<div class="updated notice"><p>✅ ' . esc_html__('Tables created/updated successfully.', 'storelinkformc') . '</p></div>';
            } elseif ($msg === 'checkout_ok') {
                echo '<div class="updated notice"><p>✅ ' . esc_html__('Checkout replaced by classic shortcode.', 'storelinkformc') . '</p></div>';
            } elseif ($msg === 'token_ok') {
                echo '<div class="updated notice"><p>✅ ' . esc_html__('Regenerated token.', 'storelinkformc') . '</p></div>';
            }
        }
        ?>

        <h1><?php esc_html_e('StoreLinkforMC Settings', 'storelinkformc'); ?></h1>

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

        <h2><?php esc_html_e('Maintenance', 'storelinkformc'); ?></h2>

        <form method="post" class="storelinkformc-form-maintenance-db" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('storelinkformc_rebuild_table_action', 'storelinkformc_rebuild_table_nonce'); ?>
            <input type="hidden" name="action" value="storelinkformc_rebuild_pending">
            <p>
                <button type="submit" class="button button-primary">🛠️ <?php esc_html_e('Create/Fix tables (DB)', 'storelinkformc'); ?></button>
            </p>
            <p class="description">
                <?php esc_html_e('Run dbDelta to (re)create the pending deliveries table.', 'storelinkformc'); ?>
            </p>
        </form>

        <form method="post" class="storelinkformc-form-maintenance-checkout" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('storelinkformc_force_checkout_action', 'storelinkformc_force_checkout_nonce'); ?>
            <input type="hidden" name="action" value="storelinkformc_force_checkout_shortcode">
            <p>
                <button type="submit" class="button">🔁 <?php esc_html_e('Force Classic Checkout (shortcode)', 'storelinkformc'); ?></button>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: %s is the WooCommerce checkout shortcode. */
                    esc_html__('Replaces the content of the Checkout page with %s.', 'storelinkformc'),
                    '<code>[woocommerce_checkout]</code>'
                );
                ?>
            </p>
        </form>

    </div>
<?php }

add_action('admin_enqueue_scripts', 'storelinkformc_enqueue_admin_scripts');
function storelinkformc_enqueue_admin_scripts($hook) {
    if ($hook !== 'toplevel_page_storelinkformc') {
        return;
    }

    // Ruta correcta al JS desde admin/settings-page.php -> ../assets/js/admin.js
    $rel      = '../assets/js/admin.js';
    $js_path  = plugin_dir_path(__FILE__) . $rel;
    $js_url   = plugins_url($rel, __FILE__);
    $ver      = file_exists($js_path) ? filemtime($js_path) : '1.0.0';

    wp_enqueue_script(
        'storelinkformc-admin',
        $js_url,
        [],
        $ver,
        true
    );

    wp_localize_script('storelinkformc-admin', 'storelinkformcAdmin', [
        'i18n' => [
            'confirm_regen_token'    => __('Are you sure you want to regenerate the API token? This will invalidate the current token and your Minecraft plugin will stop working until you update it.', 'storelinkformc'),
            'confirm_rebuild_table'  => __('Are you sure you want to rebuild the tables? This may take a moment.', 'storelinkformc'),
            'confirm_force_checkout' => __('Are you sure you want to force the classic checkout? This will replace the Checkout page content with [woocommerce_checkout].', 'storelinkformc'),
            'token_copied'           => __('Token copied to clipboard!', 'storelinkformc'),
            'token_copy_failed'      => __('Failed to copy token.', 'storelinkformc'),
        ],
    ]);
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
    <label>
        <input type="radio" name="storelinkformc_username_policy" value="premium" <?php checked($v, 'premium'); ?> />
        <?php esc_html_e('Premium only (validate via Mojang)', 'storelinkformc'); ?>
    </label><br>
    <label>
        <input type="radio" name="storelinkformc_username_policy" value="any" <?php checked($v, 'any'); ?> />
        <?php esc_html_e('Any username (non-premium)', 'storelinkformc'); ?>
    </label>
    <?php
}

function storelinkformc_delivery_expiration_render() {
    $opt = get_option('storelinkformc_delivery_expiration', [
        'value' => 30,
        'unit'  => 'days',
    ]);

    $value = is_array($opt) && isset($opt['value']) ? (int) $opt['value'] : 30;
    $unit  = is_array($opt) && isset($opt['unit']) ? sanitize_text_field($opt['unit']) : 'days';

    $units = [
        'seconds' => __('Seconds', 'storelinkformc'),
        'minutes' => __('Minutes', 'storelinkformc'),
        'hours'   => __('Hours', 'storelinkformc'),
        'days'    => __('Days', 'storelinkformc'),
        'weeks'   => __('Weeks', 'storelinkformc'),
        'months'  => __('Months', 'storelinkformc'),
        'years'   => __('Years', 'storelinkformc'),
    ];

    ?>
    <input
        type="number"
        min="1"
        max="999"
        name="storelinkformc_delivery_expiration[value]"
        value="<?php echo esc_attr($value); ?>"
        style="width:90px;"
    />
    <select name="storelinkformc_delivery_expiration[unit]">
        <?php foreach ($units as $k => $label): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($unit, $k); ?>>
                <?php echo esc_html($label); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description">
        <?php esc_html_e('Default is 30 days. Minimum 1 second. Maximum 999 years.', 'storelinkformc'); ?>
    </p>
    <?php
}


// Admin-post: regenerate token
add_action('admin_post_storelinkformc_regen_token', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'storelinkformc'));
    }
    check_admin_referer('storelinkformc_token_action', 'storelinkformc_token_nonce');

    update_option('storelinkformc_api_token', wp_generate_password(32, false));

    wp_safe_redirect(
        add_query_arg(
            'storelink_notice',
            'token_ok',
            admin_url('admin.php?page=storelinkformc')
        )
    );
    exit;
});

// Admin-post: (re)crear tabla pending_deliveries (usa función central del plugin)
add_action('admin_post_storelinkformc_rebuild_pending', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'storelinkformc'));
    }
    check_admin_referer('storelinkformc_rebuild_table_action', 'storelinkformc_rebuild_table_nonce');

    if (function_exists('storelinkformc_create_or_update_tables')) {
        storelinkformc_create_or_update_tables();
    }

    wp_safe_redirect(
        add_query_arg(
            'storelink_notice',
            'table_ok',
            admin_url('admin.php?page=storelinkformc')
        )
    );
    exit;
});

// Admin-post: forzar checkout clásico
add_action('admin_post_storelinkformc_force_checkout_shortcode', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'storelinkformc'));
    }
    check_admin_referer('storelinkformc_force_checkout_action', 'storelinkformc_force_checkout_nonce');

    if (function_exists('storelinkformc_force_classic_checkout')) {
        storelinkformc_force_classic_checkout(true);
    }

    wp_safe_redirect(
        add_query_arg(
            'storelink_notice',
            'checkout_ok',
            admin_url('admin.php?page=storelinkformc')
        )
    );
    exit;
});
