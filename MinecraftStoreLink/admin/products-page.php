<?php

add_action('admin_menu', 'minecraftstorelink_add_products_submenu');

function minecraftstorelink_add_products_submenu() {
    add_submenu_page(
        'minecraftstorelink',
        'Synced Products',
        'Products',
        'manage_woocommerce',
        'minecraftstorelink_products',
        'minecraftstorelink_products_page'
    );
}

function minecraftstorelink_products_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'minecraftstorelink'));
    }

    if (!function_exists('wc_get_products')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is not active.', 'minecraftstorelink') . '</p></div>';
        return;
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['minecraftstorelink_selected_products'])) {
        check_admin_referer('minecraftstorelink_products_save');

        $product_ids = array_map('intval', (array) $_POST['minecraftstorelink_selected_products']);
        update_option('minecraftstorelink_sync_products', $product_ids);

        echo '<div class="updated"><p>' . esc_html__('Products saved successfully.', 'minecraftstorelink') . '</p></div>';
    }

    $selected = get_option('minecraftstorelink_sync_products', []);
    $products = wc_get_products(['limit' => -1]);

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Synced Products', 'minecraftstorelink'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('minecraftstorelink_products_save'); ?>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?php esc_html_e('Select', 'minecraftstorelink'); ?></th>
                        <th><?php esc_html_e('Product Name', 'minecraftstorelink'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <input type="checkbox"
                                       name="minecraftstorelink_selected_products[]"
                                       value="<?php echo esc_attr($product->get_id()); ?>"
                                       <?php checked(in_array($product->get_id(), $selected, true)); ?>>
                            </td>
                            <td><?php echo esc_html($product->get_name()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button(__('Save Selection', 'minecraftstorelink')); ?>
        </form>
    </div>
    <?php
}
