<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        __('Checkout Fields', 'StoreLinkforMC'),
        __('Checkout Fields', 'StoreLinkforMC'),
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
        if (isset($_POST['checkout_fields'])) {
            $raw_fields = (array) wp_unslash($_POST['checkout_fields']);
            $fields     = array_map('sanitize_text_field', $raw_fields);
        }

        update_option('storelinkformc_checkout_fields', $fields);
        echo '<div class="updated"><p>' . esc_html__('Settings saved successfully.', 'StoreLinkforMC') . '</p></div>';
    }

    $selected_fields = get_option('storelinkformc_checkout_fields', []);
    $all_fields      = [
        'minecraft_username'   => __('Minecraft Username', 'StoreLinkforMC'),
        'minecraft_gift'       => __('Gift this to another player', 'StoreLinkforMC'),
        'billing_first_name'   => __('Billing First Name', 'StoreLinkforMC'),
        'billing_last_name'    => __('Billing Last Name', 'StoreLinkforMC'),
        'billing_email'        => __('Billing Email', 'StoreLinkforMC'),
        'billing_address_1'    => __('Billing Address Line 1', 'StoreLinkforMC'),
        'billing_city'         => __('Billing City', 'StoreLinkforMC'),
        'billing_postcode'     => __('Billing Postal Code', 'StoreLinkforMC'),
        'billing_country'      => __('Billing Country', 'StoreLinkforMC'),
        'billing_state'        => __('Billing State/Province', 'StoreLinkforMC'),
        'shipping_first_name'  => __('Shipping First Name', 'StoreLinkforMC'),
        'shipping_last_name'   => __('Shipping Last Name', 'StoreLinkforMC'),
        'shipping_address_1'   => __('Shipping Address Line 1', 'StoreLinkforMC'),
        'shipping_city'        => __('Shipping City', 'StoreLinkforMC'),
        'shipping_postcode'    => __('Shipping Postal Code', 'StoreLinkforMC'),
        'shipping_country'     => __('Shipping Country', 'StoreLinkforMC'),
        'shipping_state'       => __('Shipping State/Province', 'StoreLinkforMC'),
    ];

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Checkout Field Settings', 'StoreLinkforMC'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('storelinkformc_save_checkout_fields', 'storelinkformc_checkout_fields_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Fields to ask during checkout:', 'StoreLinkforMC'); ?></th>
                </tr>
                <?php foreach ($all_fields as $key => $label) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($label); ?></th>
                        <td>
                            <input
                                type="checkbox"
                                name="checkout_fields[]"
                                value="<?php echo esc_attr($key); ?>"
                                <?php checked(in_array($key, $selected_fields, true)); ?>
                            >
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <p class="description">
                <?php esc_html_e('Only the selected fields will be shown during WooCommerce checkout.', 'StoreLinkforMC'); ?>
            </p>
            <?php submit_button(__('Save Settings', 'StoreLinkforMC')); ?>
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
            'label'    => __('🎁 This is a gift', 'StoreLinkforMC'),
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
            'label'             => __('Minecraft Username', 'StoreLinkforMC'),
            'type'              => 'text',
            'required'          => false,
            'default'           => $mc_name ?: '',
            'description'       => !empty($mc_name)
                ? __('Your linked Minecraft username will be used unless you mark this order as a gift.', 'StoreLinkforMC')
                : __('Enter the recipient’s Minecraft username when gifting.', 'StoreLinkforMC'),
            'placeholder'       => __('Recipient username (required for gifts)', 'StoreLinkforMC'),
            'priority'          => 9999,
            'class'             => ['form-row-wide'],
            'custom_attributes' => $custom_attributes,
        ];
    }

    return $fields;
}, 10);


// Save the Minecraft username to the order meta
add_action('woocommerce_checkout_update_order_meta', function ($order_id) {

    // Opcional: no guardar meta si el carrito no tenía productos sincronizados
    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return;
    }

    if (isset($_POST['minecraft_username'])) {
        $username = sanitize_text_field(wp_unslash($_POST['minecraft_username']));
        update_post_meta($order_id, '_minecraft_username', $username);
    }

    $gift_raw = $_POST['minecraft_gift'] ?? '';
    if (!empty($gift_raw)) {
        update_post_meta($order_id, '_minecraft_gift', 'yes');
    } else {
        update_post_meta($order_id, '_minecraft_gift', 'no');
    }
});


// Show in admin panel order view
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    $player = get_post_meta($order->get_id(), '_minecraft_username', true);
    if ($player) {
        echo '<p><strong>' . esc_html__('Minecraft Username:', 'StoreLinkforMC') . '</strong> ' . esc_html($player) . '</p>';
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'storelinkformc_page_storelinkformc_checkout_fields') {
        return;
    }

    wp_register_script(
        'storelinkformc-checkout',
        plugins_url('../assets/js/checkout-fields.js', __FILE__),
        [],
        filemtime(plugin_dir_path(__FILE__) . '../assets/js/checkout-fields.js'),
        true
    );
    wp_enqueue_script('storelinkformc-checkout');
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
    $gift_raw = $_POST['minecraft_gift'] ?? '';
    $gift     = !empty($gift_raw); // checkbox present only when checked

    $nick = '';
    if (isset($_POST['minecraft_username'])) {
        $nick = sanitize_text_field(wp_unslash($_POST['minecraft_username']));
    }

    // CASE A) Not a gift => must be linked
    if (!$gift) {
        // Require login to self-purchase
        if (!is_user_logged_in()) {
            wc_add_notice(
                __(
                    'Please log in and link your Minecraft account to purchase for yourself. You can also tick "This is a gift" to buy for another player.',
                    'StoreLinkforMC'
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
                    'StoreLinkforMC'
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
        wc_add_notice(__('Please enter the recipient\'s Minecraft username.', 'StoreLinkforMC'), 'error');
        return;
    }

    // Policy check (only when premium mode)
    $policy = get_option('storelinkformc_username_policy', 'premium');
    if ($policy === 'premium') {
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $nick)) {
            wc_add_notice(__('Invalid Minecraft username format.', 'StoreLinkforMC'), 'error');
            return;
        }

        // Uses the helper from linking-api.php
        if (!function_exists('storelinkformc_mojang_check_username')) {
            wc_add_notice(__('Internal error: Mojang validator not found.', 'StoreLinkforMC'), 'error');
            return;
        }

        $check = storelinkformc_mojang_check_username($nick);
        if (!$check['ok']) {
            if ($check['reason'] === 'ERR') {
                wc_add_notice(__('Mojang verification is temporarily unavailable. Please try again.', 'StoreLinkforMC'), 'error');
            } else {
                wc_add_notice(__('❌ That Minecraft username does not exist on Mojang.', 'StoreLinkforMC'), 'error');
            }
            return;
        }

        // Make the resolved UUID available to the save hook
        $_POST['minecraft_uuid_resolved'] = $check['uuid'];
    }
});

add_action('woocommerce_checkout_update_order_meta', function ($order_id) {
    if (!empty($_POST['minecraft_uuid_resolved'])) {
        $raw = wp_unslash($_POST['minecraft_uuid_resolved']);
        $raw = preg_replace('/[^a-f0-9]/i', '', $raw);
        $uuid = substr($raw, 0, 8) . '-' .
            substr($raw, 8, 4) . '-' .
            substr($raw, 12, 4) . '-' .
            substr($raw, 16, 4) . '-' .
            substr($raw, 20);

        update_post_meta($order_id, '_minecraft_uuid', $uuid);
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

    if (!function_exists('wc_print_notice') || !is_checkout()) {
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
             . __('To purchase for yourself, please <strong>log in and link your Minecraft account</strong>. ', 'StoreLinkforMC')
             . ($has_gift_field ? __('Or tick <em>"This is a gift"</em> to buy for another player.', 'StoreLinkforMC') : '')
             . '</div>';
        wc_print_notice($msg, 'notice');
        return;
    }

    $linked = get_user_meta(get_current_user_id(), 'minecraft_player', true);
    if (!$linked) {
        $msg = '<div class="storelinkformc-linking-notice">'
             . __('You are not linked. To purchase for yourself, please <strong>link your Minecraft account</strong>. ', 'StoreLinkforMC')
             . ($has_gift_field ? __('Alternatively, tick <em>"This is a gift"</em> to buy for another player.', 'StoreLinkforMC') : '')
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

    if (!is_checkout()) {
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
