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

    // Añadir campos personalizados para StoreLink
    $user = wp_get_current_user();
    $mc_name = ($user && $user->ID) ? get_user_meta($user->ID, 'minecraft_player', true) : '';

    // Campo: "¿Es un regalo?"
    $fields['billing']['minecraft_gift'] = [
        'type'     => 'checkbox',
        'label'    => __('🎁 This is a gift for another player', 'storelinkformc'),
        'required' => false,
        'priority' => 9998,
    ];

    // Campo: Minecraft Username (editable siempre)
    $fields['billing']['minecraft_username'] = [
        'label'    => __('Minecraft Username', 'storelinkformc'),
        'type'     => 'text',
        'required' => true,
        'default'  => $mc_name,
        'description' => $mc_name
            ? __('Your linked Minecraft name (or enter another for gifting)', 'storelinkformc')
            : __('Enter the Minecraft username to receive the item', 'storelinkformc'),
        'priority' => 9999,
    ];

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
