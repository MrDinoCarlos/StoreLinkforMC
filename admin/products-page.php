<?php

add_action('admin_menu', 'woostorelink_add_products_submenu');

function woostorelink_add_products_submenu() {
    add_submenu_page(
        'woostorelink',                  // Parent menu slug
        'Synced Products',              // Page title
        'Products',                     // Menu title
        'manage_woocommerce',
        'woostorelink_products',
        'woostorelink_products_page'
    );
}

function woostorelink_products_page() {
    if (!function_exists('wc_get_products')) {
        echo '<div class="notice notice-error"><p>WooCommerce is not active.</p></div>';
        return;
    }

    if (isset($_POST['woostorelink_selected_products'])) {
        check_admin_referer('woostorelink_products_save');
        update_option('woostorelink_sync_products', array_map('intval', $_POST['woostorelink_selected_products']));
        echo '<div class="updated"><p>Products saved successfully.</p></div>';
    }

    $selected = get_option('woostorelink_sync_products', []);
    $products = wc_get_products(['limit' => -1]);

    ?>
    <div class="wrap">
        <h1>Select products to sync with Minecraft</h1>
        <form method="post">
            <?php wp_nonce_field('woostorelink_products_save'); ?>
            <table class="widefat fixed">
                <thead><tr><th>Select</th><th>Product</th></tr></thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   name="woostorelink_selected_products[]"
                                   value="<?= esc_attr($product->get_id()); ?>"
                                   <?= in_array($product->get_id(), $selected) ? 'checked' : ''; ?>>
                        </td>
                        <td><?= esc_html($product->get_name()); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button('Save selection'); ?>
        </form>
    </div>
    <?php
}
