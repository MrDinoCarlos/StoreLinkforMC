<?php

add_action('admin_menu', function () {
    add_submenu_page(
        'minecraftstorelink',
        'Checkout Fields',
        'Checkout Fields',
        'manage_options',
        'minecraftstorelink_checkout_fields',
        'minecraftstorelink_checkout_fields_page'
    );
});

function minecraftstorelink_checkout_fields_page() {
    if (
        isset($_POST['minecraftstorelink_checkout_fields_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['minecraftstorelink_checkout_fields_nonce'])), 'minecraftstorelink_save_checkout_fields')
    ) {
        $fields = isset($_POST['checkout_fields']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['checkout_fields'])) : [];
        update_option('minecraftstorelink_checkout_fields', $fields);
        echo '<div class="updated"><p>Settings saved successfully.</p></div>';
    }

    $selected_fields = get_option('minecraftstorelink_checkout_fields', []);
    $all_fields = [
        'billing_first_name' => 'First Name',
        'billing_last_name'  => 'Last Name',
        'billing_email'      => 'Email',
        'billing_address_1'  => 'Address Line 1',
        'billing_city'       => 'City',
        'billing_postcode'   => 'Postal Code',
        'billing_country'    => 'Country',
        'billing_state'      => 'State/Province',
    ];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Checkout Field Settings', 'minecraftstorelink'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('minecraftstorelink_save_checkout_fields', 'minecraftstorelink_checkout_fields_nonce'); ?>

            <table class="form-table">
                <tr><th><?php esc_html_e('Fields to ask during checkout:', 'minecraftstorelink'); ?></th></tr>
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

            <p class="description"><?php esc_html_e('Only the selected fields will be shown during WooCommerce checkout.', 'minecraftstorelink'); ?></p>
            <?php submit_button(__('Save Settings', 'minecraftstorelink')); ?>
        </form>
    </div>
    <?php
}

// Filter WooCommerce fields dynamically
add_filter('woocommerce_checkout_fields', function ($fields) {
    $allowed = get_option('minecraftstorelink_checkout_fields', []);
    if (!empty($allowed) && isset($fields['billing'])) {
        foreach ($fields['billing'] as $key => $value) {
            if (strpos($key, 'billing_') === 0 && !in_array($key, $allowed, true)) {
                unset($fields['billing'][$key]);
            }
        }
    }
    return $fields;
}, 10);

// Auto-fill Minecraft username field in checkout (read-only custom field)
add_filter('woocommerce_checkout_fields', function ($fields) {
    $user = wp_get_current_user();
    $mc_name = ($user && $user->ID) ? get_user_meta($user->ID, 'minecraft_player', true) : '';

    $fields['billing']['minecraft_username'] = [
        'label' => __('Minecraft Username', 'minecraftstorelink'),
        'type' => 'text',
        'required' => true,
        'default' => $mc_name,
        'custom_attributes' => $mc_name ? ['readonly' => 'readonly'] : [],
        'description' => $mc_name
            ? __('This field is auto-filled from your linked Minecraft account.', 'minecraftstorelink')
            : __('Link your Minecraft account to auto-fill this.', 'minecraftstorelink'),
        'priority' => 5,
    ];

    return $fields;
}, 15);

// Save the Minecraft username to the order meta
add_action('woocommerce_checkout_update_order_meta', function ($order_id) {
    if (isset($_POST['minecraft_username'])) {
        update_post_meta($order_id, '_minecraft_username', sanitize_text_field(wp_unslash($_POST['minecraft_username'])));
    }
});

// Show in admin panel order view
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {
    $player = get_post_meta($order->get_id(), '_minecraft_username', true);
    if ($player) {
        echo '<p><strong>' . esc_html__('Minecraft Username:', 'minecraftstorelink') . '</strong> ' . esc_html($player) . '</p>';
    }
});
