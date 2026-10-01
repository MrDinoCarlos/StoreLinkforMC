<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        __('Checkout Fields', 'storelinkformc'),
        __('Checkout Fields', 'storelinkformc'),
        'manage_options',
        'storelinkformc_checkout_fields',
        'storelinkformc_checkout_fields_page'
    );
});

function storelinkformc_checkout_fields_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (
        isset($_POST['storelinkformc_checkout_fields_nonce']) &&
        wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['storelinkformc_checkout_fields_nonce'])),
            'storelinkformc_save_checkout_fields'
        )
    ) {
        $fields = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (isset($_POST['checkout_fields']) && is_array($_POST['checkout_fields'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            foreach (wp_unslash($_POST['checkout_fields']) as $raw_field) {
                $fields[] = sanitize_key($raw_field);
            }
        }

        update_option('storelinkformc_checkout_fields', $fields);
        echo '<div class="updated"><p>' . esc_html__('Settings saved successfully.', 'storelinkformc') . '</p></div>';
    }

    $selected_fields = array_values((array) get_option('storelinkformc_checkout_fields', []));
    $all_fields      = [
        'minecraft_username'   => __('Minecraft Username', 'storelinkformc'),
        'minecraft_gift'       => __('Gift this to another player', 'storelinkformc'),
        'billing_first_name'   => __('Billing First Name', 'storelinkformc'),
        'billing_last_name'    => __('Billing Last Name', 'storelinkformc'),
        'billing_email'        => __('Billing Email', 'storelinkformc'),
        'billing_address_1'    => __('Billing Address Line 1', 'storelinkformc'),
        'billing_city'         => __('Billing City', 'storelinkformc'),
        'billing_postcode'     => __('Billing Postal Code', 'storelinkformc'),
        'billing_country'      => __('Billing Country', 'storelinkformc'),
        'billing_state'        => __('Billing State/Province', 'storelinkformc'),
        'shipping_first_name'  => __('Shipping First Name', 'storelinkformc'),
        'shipping_last_name'   => __('Shipping Last Name', 'storelinkformc'),
        'shipping_address_1'   => __('Shipping Address Line 1', 'storelinkformc'),
        'shipping_city'        => __('Shipping City', 'storelinkformc'),
        'shipping_postcode'    => __('Shipping Postal Code', 'storelinkformc'),
        'shipping_country'     => __('Shipping Country', 'storelinkformc'),
        'shipping_state'       => __('Shipping State/Province', 'storelinkformc'),
    ];
    $field_groups = [
        __('Minecraft', 'storelinkformc') => [
            'minecraft_username',
            'minecraft_gift',
        ],
        __('Billing', 'storelinkformc') => [
            'billing_first_name',
            'billing_last_name',
            'billing_email',
            'billing_address_1',
            'billing_city',
            'billing_postcode',
            'billing_country',
            'billing_state',
        ],
        __('Shipping', 'storelinkformc') => [
            'shipping_first_name',
            'shipping_last_name',
            'shipping_address_1',
            'shipping_city',
            'shipping_postcode',
            'shipping_country',
            'shipping_state',
        ],
    ];
    $field_notes = [
        'minecraft_username'   => __('Shows the linked player or recipient username field.', 'storelinkformc'),
        'minecraft_gift'       => __('Lets customers buy for another Minecraft player.', 'storelinkformc'),
        'billing_first_name'   => __('Customer billing first name.', 'storelinkformc'),
        'billing_last_name'    => __('Customer billing last name.', 'storelinkformc'),
        'billing_email'        => __('Customer email used by WooCommerce.', 'storelinkformc'),
        'billing_address_1'    => __('Primary billing street address.', 'storelinkformc'),
        'billing_city'         => __('Billing city field.', 'storelinkformc'),
        'billing_postcode'     => __('Billing postal or ZIP code.', 'storelinkformc'),
        'billing_country'      => __('Billing country selector.', 'storelinkformc'),
        'billing_state'        => __('Billing state or province selector.', 'storelinkformc'),
        'shipping_first_name'  => __('Shipping recipient first name.', 'storelinkformc'),
        'shipping_last_name'   => __('Shipping recipient last name.', 'storelinkformc'),
        'shipping_address_1'   => __('Primary shipping street address.', 'storelinkformc'),
        'shipping_city'        => __('Shipping city field.', 'storelinkformc'),
        'shipping_postcode'    => __('Shipping postal or ZIP code.', 'storelinkformc'),
        'shipping_country'     => __('Shipping country selector.', 'storelinkformc'),
        'shipping_state'       => __('Shipping state or province selector.', 'storelinkformc'),
    ];
    $selected_count = is_array($selected_fields) ? count($selected_fields) : 0;

    ?>
    <div class="wrap storelinkformc-admin">
        <div class="storelinkformc-admin-header">
            <div>
                <span class="storelinkformc-eyebrow"><?php esc_html_e('Checkout experience', 'storelinkformc'); ?></span>
                <h1><?php esc_html_e('Checkout Fields', 'storelinkformc'); ?></h1>
                <p class="storelinkformc-admin-subtitle">
                    <?php esc_html_e('Choose which fields StoreLink should keep visible during checkout when the cart contains synced Minecraft products.', 'storelinkformc'); ?>
                </p>
            </div>
            <div class="storelinkformc-admin-stats" aria-hidden="true">
                <div class="storelinkformc-stat">
                    <strong><?php echo esc_html((string) $selected_count); ?></strong>
                    <span><?php esc_html_e('selected', 'storelinkformc'); ?></span>
                </div>
                <div class="storelinkformc-stat">
                    <strong><?php echo esc_html((string) count($all_fields)); ?></strong>
                    <span><?php esc_html_e('available', 'storelinkformc'); ?></span>
                </div>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field('storelinkformc_save_checkout_fields', 'storelinkformc_checkout_fields_nonce'); ?>

            <div class="storelinkformc-panel">
                <div class="storelinkformc-panel-header">
                    <div>
                        <h2><?php esc_html_e('Fields to ask during checkout', 'storelinkformc'); ?></h2>
                        <p><?php esc_html_e('Unchecked fields are hidden for StoreLink synced carts. Leave empty to use WooCommerce defaults.', 'storelinkformc'); ?></p>
                    </div>
                </div>
                <div class="storelinkformc-preset-bar">
                    <div>
                        <strong><?php esc_html_e('Quick setup', 'storelinkformc'); ?></strong>
                        <span><?php esc_html_e('Start with a sensible preset, then fine-tune individual fields.', 'storelinkformc'); ?></span>
                    </div>
                    <div class="storelinkformc-preset-actions">
                        <button type="button" class="button button-primary" data-slmc-preset="digital"><?php esc_html_e('Digital store', 'storelinkformc'); ?></button>
                        <button type="button" class="button" data-slmc-preset="minimal"><?php esc_html_e('Minecraft only', 'storelinkformc'); ?></button>
                        <button type="button" class="button" data-slmc-preset="all"><?php esc_html_e('Keep all fields', 'storelinkformc'); ?></button>
                        <button type="button" class="button" data-slmc-preset="default"><?php esc_html_e('WooCommerce default', 'storelinkformc'); ?></button>
                    </div>
                </div>
                <div class="storelinkformc-info-banner">
                    <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                    <p><strong><?php esc_html_e('Only affects synchronized carts.', 'storelinkformc'); ?></strong> <?php esc_html_e('Normal WooCommerce orders keep their standard checkout fields. An empty selection also leaves WooCommerce unchanged.', 'storelinkformc'); ?></p>
                </div>
                <div class="storelinkformc-panel-body storelinkformc-fields-builder">
                    <?php foreach ($field_groups as $group_label => $group_fields) : ?>
                        <section class="storelinkformc-field-group">
                            <div class="storelinkformc-field-group-heading">
                                <div>
                                    <span class="dashicons <?php echo 'Minecraft' === $group_label ? 'dashicons-admin-users' : ('Billing' === $group_label ? 'dashicons-email-alt' : 'dashicons-location'); ?>" aria-hidden="true"></span>
                                    <h3><?php echo esc_html($group_label); ?></h3>
                                </div>
                                <button type="button" class="button-link" data-slmc-group-toggle><?php esc_html_e('Select group', 'storelinkformc'); ?></button>
                            </div>
                            <div class="storelinkformc-grid">
                            <?php foreach ($group_fields as $key) : ?>
                                <?php if (!isset($all_fields[$key])) continue; ?>
                                <label class="storelinkformc-field-card<?php echo in_array($key, $selected_fields, true) ? ' is-selected' : ''; ?>" data-field="<?php echo esc_attr($key); ?>">
                                    <input
                                        type="checkbox"
                                        name="checkout_fields[]"
                                        value="<?php echo esc_attr($key); ?>"
                                        <?php checked(in_array($key, $selected_fields, true)); ?>
                                    >
                                    <span>
                                        <strong><?php echo esc_html($all_fields[$key]); ?>
                                            <?php if (in_array($key, ['minecraft_username', 'billing_email'], true)) : ?>
                                                <em><?php esc_html_e('Recommended', 'storelinkformc'); ?></em>
                                            <?php endif; ?>
                                        </strong>
                                        <span><?php echo esc_html($field_notes[$key] ?? ''); ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
                <div class="storelinkformc-actions">
                    <span class="storelinkformc-selection-summary"><strong data-slmc-selected-count><?php echo esc_html((string) $selected_count); ?></strong> <?php esc_html_e('fields selected', 'storelinkformc'); ?></span>
                    <?php submit_button(__('Save Settings', 'storelinkformc'), 'primary', 'submit', false); ?>
                </div>
            </div>
        </form>
    </div>
    <?php
}

// Filter WooCommerce fields dynamically
add_filter('woocommerce_checkout_fields', function ($fields) {
    $allowed = get_option('storelinkformc_checkout_fields', []);

    // Check if we should enable Minecraft-related logic for this cart
    $cart_has_synced = function_exists('storelinkformc_cart_has_synced_products')
        ? storelinkformc_cart_has_synced_products()
        : false;

    /**
     * 1) SOLO recortar campos de billing/shipping cuando el carrito
     *    tiene productos sincronizados.
     *    Si NO hay productos sincronizados → NO tocamos nada,
     *    WooCommerce se queda tal cual (default).
     */
    if (!empty($allowed) && $cart_has_synced) {
        foreach (['billing', 'shipping'] as $section) {
            if (isset($fields[$section])) {
                foreach ($fields[$section] as $key => $value) {
                    if (!in_array($key, $allowed, true)) {
                        unset($fields[$section][$key]);
                    }
                }
            }
        }
    }

    // 2) A partir de aquí, ya usamos $cart_has_synced para los campos de Minecraft

    $user    = wp_get_current_user();
    $mc_name = ($user && $user->ID) ? get_user_meta($user->ID, 'minecraft_player', true) : '';

    // Add custom fields ONLY if enabled in settings AND the cart has synced products
    if ($cart_has_synced && in_array('minecraft_gift', $allowed, true)) {
        $fields['billing']['minecraft_gift'] = [
            'type'     => 'checkbox',
            'label'    => __('🎁 This is a gift', 'storelinkformc'),
            'required' => false,
            'priority' => 9998,
            'class'    => ['form-row-wide'],
        ];
    }

    if ($cart_has_synced && in_array('minecraft_username', $allowed, true)) {
        // Inicial: si está vinculado → readonly; si NO → disabled hasta que marquen gift
        $custom_attributes = [];
        if (!empty($mc_name)) {
            $custom_attributes['readonly'] = 'readonly';
        } else {
            $custom_attributes['disabled'] = 'disabled';
            $custom_attributes['readonly'] = 'readonly';
        }

        $fields['billing']['minecraft_username'] = [
            'label'             => __('Minecraft Username', 'storelinkformc'),
            'type'              => 'text',
            'required'          => false,
            'default'           => $mc_name ?: '',
            'description'       => !empty($mc_name)
                ? __('Your linked Minecraft username will be used unless you mark this order as a gift.', 'storelinkformc')
                : __('Enter the recipient’s Minecraft username when gifting.', 'storelinkformc'),
            'placeholder'       => __('Recipient username (required for gifts)', 'storelinkformc'),
            'priority'          => 9999,
            'class'             => ['form-row-wide'],
            'custom_attributes' => $custom_attributes,
        ];
    }

    return $fields;
}, 10);


// Save the Minecraft username to the order meta
add_action('woocommerce_checkout_create_order', function ($order) {

    // Opcional: no guardar meta si el carrito no tenía productos sincronizados
    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return;
    }

    // WooCommerce checkout submission; WooCommerce owns nonce validation here.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (isset($_POST['minecraft_username'])) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $username = sanitize_text_field(wp_unslash($_POST['minecraft_username']));
        $order->update_meta_data('_minecraft_username', $username);
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $gift_raw = isset($_POST['minecraft_gift']) ? sanitize_text_field(wp_unslash($_POST['minecraft_gift'])) : '';
    if (!empty($gift_raw)) {
        $order->update_meta_data('_minecraft_gift', 'yes');
    } else {
        $order->update_meta_data('_minecraft_gift', 'no');
    }
});


// Show in admin panel order view
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    $player = $order->get_meta('_minecraft_username', true);
    if ($player) {
        echo '<p><strong>' . esc_html__('Minecraft Username:', 'storelinkformc') . '</strong> ' . esc_html($player) . '</p>';
    }
});

// Enforce linking (self-purchase) vs gift + policy
add_action('woocommerce_checkout_process', function () {

    // Do not enforce Minecraft rules when there are no synced products
    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return;
    }

    // If you disabled the custom fields in settings, skip
    $allowed            = get_option('storelinkformc_checkout_fields', []);
    $has_username_field = in_array('minecraft_username', $allowed, true);
    $has_gift_field     = in_array('minecraft_gift', $allowed, true);

    // Read form
    // WooCommerce checkout submission; WooCommerce owns nonce validation here.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $gift_raw = isset($_POST['minecraft_gift']) ? sanitize_text_field(wp_unslash($_POST['minecraft_gift'])) : '';
    $gift     = !empty($gift_raw); // checkbox present only when checked

    $nick = '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (isset($_POST['minecraft_username'])) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $nick = sanitize_text_field(wp_unslash($_POST['minecraft_username']));
    }

    // CASE A) Not a gift => must be linked
    if (!$gift) {
        // Require login to self-purchase
        if (!is_user_logged_in()) {
            wc_add_notice(
                __(
                    'Please log in and link your Minecraft account to purchase for yourself. You can also tick "This is a gift" to buy for another player.',
                    'storelinkformc'
                ),
                'error'
            );
            return;
        }

        $user_id = get_current_user_id();
        $linked  = get_user_meta($user_id, 'minecraft_player', true);

        if (!$linked) {
            wc_add_notice(
                __(
                    'This store requires you to link your Minecraft account before purchasing for yourself. Either link your account first or tick "This is a gift".',
                    'storelinkformc'
                ),
                'error'
            );
            return;
        }

        // If you prefer, you can ignore any typed username when not gifting.
        // Do NOT Mojang-check here: self-purchase uses the linked account.
        return;
    }

    // CASE B) Gift => validate the provided recipient username
    // Make sure the username field is present when gifting
    if ($has_username_field && empty($nick)) {
        wc_add_notice(__('Please enter the recipient\'s Minecraft username.', 'storelinkformc'), 'error');
        return;
    }

    // Policy check (only when premium mode)
    $policy = get_option('storelinkformc_username_policy', 'premium');
    if ($policy === 'premium') {
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $nick)) {
            wc_add_notice(__('Invalid Minecraft username format.', 'storelinkformc'), 'error');
            return;
        }

        // Uses the helper from linking-api.php
        if (!function_exists('storelinkformc_mojang_check_username')) {
            wc_add_notice(__('Internal error: Mojang validator not found.', 'storelinkformc'), 'error');
            return;
        }

        $check = storelinkformc_mojang_check_username($nick);
        if (!$check['ok']) {
            if ($check['reason'] === 'ERR') {
                wc_add_notice(__('Mojang verification is temporarily unavailable. Please try again.', 'storelinkformc'), 'error');
            } else {
                wc_add_notice(__('❌ That Minecraft username does not exist on Mojang.', 'storelinkformc'), 'error');
            }
            return;
        }

        // Make the resolved UUID available to the save hook
        $_POST['minecraft_uuid_resolved'] = sanitize_text_field($check['uuid']);
    }
});

add_action('woocommerce_checkout_create_order', function ($order) {
    // WooCommerce checkout submission; WooCommerce owns nonce validation here.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (!empty($_POST['minecraft_uuid_resolved'])) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw = sanitize_text_field(wp_unslash($_POST['minecraft_uuid_resolved']));
        $raw = preg_replace('/[^a-f0-9]/i', '', $raw);
        $uuid = substr($raw, 0, 8) . '-' .
            substr($raw, 8, 4) . '-' .
            substr($raw, 12, 4) . '-' .
            substr($raw, 16, 4) . '-' .
            substr($raw, 20);

        $order->update_meta_data('_minecraft_uuid', $uuid);
    }
}, 20);

// Show an info notice on checkout: link account if NOT gifting
add_action('woocommerce_before_checkout_form', function () {
    // ⛔ No mostrar avisos si NO se exige vinculación
    if (function_exists('storelinkformc_force_link_enabled') && !storelinkformc_force_link_enabled()) {
        return;
    }

    // No show notice when the cart has no synced products
    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return;
    }

    if (!function_exists('wc_print_notice') || !function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // Only show if your Minecraft fields are in use
    $allowed = get_option('storelinkformc_checkout_fields', []);
    if (empty($allowed) || !in_array('minecraft_username', $allowed, true)) {
        return;
    }

    // If gifting is disabled in settings, always require linking (so always show)
    $has_gift_field = in_array('minecraft_gift', $allowed, true);

    // Build the message depending on login/link status
    if (!is_user_logged_in()) {
        $msg = '<div class="storelinkformc-linking-notice">'
             . __('To purchase for yourself, please <strong>log in and link your Minecraft account</strong>. ', 'storelinkformc')
             . ($has_gift_field ? __('Or tick <em>"This is a gift"</em> to buy for another player.', 'storelinkformc') : '')
             . '</div>';
        wc_print_notice($msg, 'notice');
        return;
    }

    $linked = get_user_meta(get_current_user_id(), 'minecraft_player', true);
    if (!$linked) {
        $msg = '<div class="storelinkformc-linking-notice">'
             . __('You are not linked. To purchase for yourself, please <strong>link your Minecraft account</strong>. ', 'storelinkformc')
             . ($has_gift_field ? __('Alternatively, tick <em>"This is a gift"</em> to buy for another player.', 'storelinkformc') : '')
             . '</div>';
        wc_print_notice($msg, 'notice');
    }
});

// Hide the notice automatically when "gift" is checked (and show it back if unchecked)
add_action('wp_enqueue_scripts', function () {
    // ⛔ No cargar el JS si NO se exige vinculación
    if (function_exists('storelinkformc_force_link_enabled') && !storelinkformc_force_link_enabled()) {
        return;
    }

    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // Make sure jQuery is available
    wp_enqueue_script('jquery');

    $js  = "jQuery(function($) {\n";
    $js .= "  function toggleLinkingNotice(){\n";
    $js .= "    var \$gift = $('#minecraft_gift, #billing_minecraft_gift');\n";
    $js .= "    var giftChecked = \$gift.length && \$gift.is(':checked');\n";
    $js .= "    var \$notice = $('.storelinkformc-linking-notice').closest('.woocommerce-info, .woocommerce-message, .woocommerce-error');\n";
    $js .= "    if (!\$notice.length) return;\n\n";
    $js .= "    if (giftChecked) { \$notice.hide(); }\n";
    $js .= "    else { \$notice.show(); }\n";
    $js .= "  }\n";
    $js .= "  toggleLinkingNotice();\n";
    $js .= "  $(document).on('change', '#minecraft_gift, #billing_minecraft_gift', toggleLinkingNotice);\n";
    $js .= "});\n";

    wp_add_inline_script('jquery', $js);
});
