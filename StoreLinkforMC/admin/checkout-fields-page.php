<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        'Checkout Fields',
        'Checkout Fields',
        'manage_options',
        'storelinkformc_checkout_fields',
        'storelinkformc_checkout_fields_page'
    );
});

function storelinkformc_checkout_fields_page() {
    if (
        isset($_POST['storelinkformc_checkout_fields_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['storelinkformc_checkout_fields_nonce'])), 'storelinkformc_save_checkout_fields') &&
        current_user_can('manage_options')
    )

    {
        $fields = isset($_POST['checkout_fields']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['checkout_fields'])) : [];
        update_option('storelinkformc_checkout_fields', $fields);
        echo '<div class="updated"><p>Settings saved successfully.</p></div>';
    }

    $selected_fields = get_option('storelinkformc_checkout_fields', []);
    $all_fields = [
        'minecraft_username'   => 'Minecraft Username',
        'minecraft_gift'       => 'Gift this to another player',
        'billing_first_name'   => 'Billing First Name',
        'billing_last_name'    => 'Billing Last Name',
        'billing_email'        => 'Billing Email',
        'billing_address_1'    => 'Billing Address Line 1',
        'billing_city'         => 'Billing City',
        'billing_postcode'     => 'Billing Postal Code',
        'billing_country'      => 'Billing Country',
        'billing_state'        => 'Billing State/Province',
        'shipping_first_name'  => 'Shipping First Name',
        'shipping_last_name'   => 'Shipping Last Name',
        'shipping_address_1'   => 'Shipping Address Line 1',
        'shipping_city'        => 'Shipping City',
        'shipping_postcode'    => 'Shipping Postal Code',
        'shipping_country'     => 'Shipping Country',
        'shipping_state'       => 'Shipping State/Province',
    ];

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Checkout Field Settings', 'storelinkformc'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('storelinkformc_save_checkout_fields', 'storelinkformc_checkout_fields_nonce'); ?>

            <table class="form-table">
                <tr><th><?php esc_html_e('Fields to ask during checkout:', 'storelinkformc'); ?></th></tr>
                <?php foreach ($all_fields as $key => $label): ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($label); ?></th>
                        <td>
                            <input type="checkbox" name="checkout_fields[]" value="<?php echo esc_attr($key); ?>"
                                <?php checked(in_array($key, $selected_fields, true)); ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <p class="description"><?php esc_html_e('Only the selected fields will be shown during WooCommerce checkout.', 'storelinkformc'); ?></p>
            <?php submit_button(__('Save Settings', 'storelinkformc')); ?>
        </form>
    </div>
    <?php
}

// Filter WooCommerce fields dynamically
// Filter WooCommerce fields dynamically
add_filter('woocommerce_checkout_fields', function ($fields) {
    $allowed = get_option('storelinkformc_checkout_fields', []);

    if (!empty($allowed)) {
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

    $user    = wp_get_current_user();
    $mc_name = ($user && $user->ID) ? get_user_meta($user->ID, 'minecraft_player', true) : '';

    // Add custom fields ONLY if enabled in settings
    if (in_array('minecraft_gift', $allowed, true)) {
        $fields['billing']['minecraft_gift'] = [
            'type'     => 'checkbox',
            'label'    => __('🎁 This is a gift', 'storelinkformc'),
            'required' => false,
            'priority' => 9998,
            'class'    => ['form-row-wide'],
        ];
    }

    if (in_array('minecraft_username', $allowed, true)) {

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
            'required'          => false, // JS + validación servidor lo hacen obligatorio solo si es gift
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
add_action('woocommerce_checkout_update_order_meta', function ($order_id) {
    if (isset($_POST['minecraft_username'])) {
        update_post_meta($order_id, '_minecraft_username', sanitize_text_field(wp_unslash($_POST['minecraft_username'])));
    }
    if (!empty($_POST['minecraft_gift'])) {
        update_post_meta($order_id, '_minecraft_gift', 'yes');
    } else {
        update_post_meta($order_id, '_minecraft_gift', 'no');
    }
});

// Show in admin panel order view
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    $player = get_post_meta($order->get_id(), '_minecraft_username', true);
    if ($player) {
        echo '<p><strong>' . esc_html__('Minecraft Username:', 'storelinkformc') . '</strong> ' . esc_html($player) . '</p>';
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'storelinkformc_page_storelinkformc_checkout_fields') return;

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
    // If you disabled the custom fields in settings, skip
    $allowed = get_option('storelinkformc_checkout_fields', []);
    $has_username_field = in_array('minecraft_username', $allowed, true);
    $has_gift_field     = in_array('minecraft_gift', $allowed, true);

    // Read form
    $gift = !empty($_POST['minecraft_gift']); // checkbox present only when checked
    $nick = isset($_POST['minecraft_username']) ? sanitize_text_field($_POST['minecraft_username']) : '';

    // CASE A) Not a gift => must be linked
    if (!$gift) {
        // Require login to self-purchase
        if (!is_user_logged_in()) {
            wc_add_notice(__('Please log in and link your Minecraft account to purchase for yourself. You can also tick "This is a gift" to buy for another player.', 'storelinkformc'), 'error');
            return;
        }

        $user_id = get_current_user_id();
        $linked  = get_user_meta($user_id, 'minecraft_player', true);

        if (!$linked) {
            wc_add_notice(__('This store requires you to link your Minecraft account before purchasing for yourself. Either link your account first or tick "This is a gift".', 'storelinkformc'), 'error');
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
        } else {
            // Make the resolved UUID available to the save hook
            $_POST['minecraft_uuid_resolved'] = $check['uuid'];
        }
    }
});

add_action('woocommerce_checkout_update_order_meta', function ($order_id) {
    if (!empty($_POST['minecraft_uuid_resolved'])) {
        $raw = preg_replace('/[^a-f0-9]/i', '', $_POST['minecraft_uuid_resolved']);
        $uuid = substr($raw,0,8).'-'.substr($raw,8,4).'-'.substr($raw,12,4).'-'.substr($raw,16,4).'-'.substr($raw,20);
        update_post_meta($order_id, '_minecraft_uuid', $uuid);
    }
}, 20);

// Show an info notice on checkout: link account if NOT gifting
add_action('woocommerce_before_checkout_form', function () {
    if ( ! function_exists('wc_print_notice') || ! is_checkout() ) return;

    // Only show if your Minecraft fields are in use
    $allowed = get_option('storelinkformc_checkout_fields', []);
    if ( empty($allowed) || ! in_array('minecraft_username', $allowed, true) ) return;

    // If gifting is disabled in settings, always require linking (so always show)
    $has_gift_field = in_array('minecraft_gift', $allowed, true);

    // Build the message depending on login/link status
    if ( ! is_user_logged_in() ) {
        $msg = '<div class="storelinkformc-linking-notice">To purchase for yourself, please <strong>log in and link your Minecraft account</strong>. '
             . ( $has_gift_field ? 'Or tick <em>"This is a gift"</em> to buy for another player.' : '' )
             . '</div>';
        wc_print_notice($msg, 'notice');
        return;
    }

    $linked = get_user_meta(get_current_user_id(), 'minecraft_player', true);
    if ( ! $linked ) {
        $msg = '<div class="storelinkformc-linking-notice">You are not linked. To purchase for yourself, please <strong>link your Minecraft account</strong>. '
             . ( $has_gift_field ? 'Alternatively, tick <em>"This is a gift"</em> to buy for another player.' : '' )
             . '</div>';
        wc_print_notice($msg, 'notice');
    }
});

// Hide the notice automatically when "gift" is checked (and show it back if unchecked)
add_action('wp_enqueue_scripts', function () {
    if ( ! is_checkout() ) return;

    // Make sure jQuery is available
    wp_enqueue_script('jquery');

    $js = <<<JS
jQuery(function($){
  function toggleLinkingNotice(){
    var \$gift = $('#minecraft_gift, #billing_minecraft_gift');
    var giftChecked = \$gift.length && \$gift.is(':checked');
    var \$notice = $('.storelinkformc-linking-notice').closest('.woocommerce-info, .woocommerce-message, .woocommerce-error');
    if (!\$notice.length) return;

    if (giftChecked) { \$notice.hide(); }
    else { \$notice.show(); }
  }
  toggleLinkingNotice();
  $(document).on('change', '#minecraft_gift, #billing_minecraft_gift', toggleLinkingNotice);
});
JS;
    wp_add_inline_script('jquery', $js);
});

