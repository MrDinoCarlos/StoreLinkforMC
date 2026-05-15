<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add submenu for Synced Products
 */
add_action('admin_menu', 'storelinkformc_add_products_submenu');

function storelinkformc_add_products_submenu() {
    add_submenu_page(
        'storelinkformc',
        __('Synced Products', 'storelinkformc'),
        __('Products', 'storelinkformc'),
        'manage_woocommerce',
        'storelinkformc_products',
        'storelinkformc_products_page'
    );
}

function storelinkformc_products_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'storelinkformc'));
    }

    if (!function_exists('wc_get_products')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is not active.', 'storelinkformc') . '</p></div>';
        return;
    }

    // Save selected products and variations.
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    if (
        'POST' === $request_method &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_products_save')
    ) {
        $product_ids = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (isset($_POST['storelinkformc_selected_products']) && is_array($_POST['storelinkformc_selected_products'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            foreach (wp_unslash($_POST['storelinkformc_selected_products']) as $raw_id) {
                $product_id = absint($raw_id);
                if ($product_id) {
                    $product_ids[] = $product_id;
                }
            }
        }
        $product_ids = array_values(array_unique($product_ids));

        update_option('storelinkformc_sync_products', $product_ids);

        echo '<div class="updated notice"><p>' . esc_html__('Products saved successfully.', 'storelinkformc') . '</p></div>';
    }

    $selected = array_map('absint', (array) get_option('storelinkformc_sync_products', []));
    $products = wc_get_products([
        'limit'  => -1,
        'status' => ['publish', 'private'],
        'type'   => ['simple', 'variable'],
        'orderby' => 'name',
        'order'  => 'ASC',
    ]);
    ?>

    <div class="wrap">
        <h1><?php esc_html_e('Synced Products', 'storelinkformc'); ?></h1>

        <form method="post">
            <?php wp_nonce_field('storelinkformc_products_save'); ?>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?php esc_html_e('Select', 'storelinkformc'); ?></th>
                        <th><?php esc_html_e('Product Name', 'storelinkformc'); ?></th>
                        <th style="width: 110px;"><?php esc_html_e('Woo ID', 'storelinkformc'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product) : ?>
                        <tr>
                            <td>
                                <input type="checkbox"
                                    name="storelinkformc_selected_products[]"
                                    value="<?php echo esc_attr($product->get_id()); ?>"
                                    <?php checked(in_array($product->get_id(), $selected, true)); ?>>
                            </td>
                            <td><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                            <td><?php echo esc_html((string) $product->get_id()); ?></td>
                        </tr>
                        <?php if ($product->is_type('variable')) : ?>
                            <?php foreach ($product->get_children() as $variation_id) : ?>
                                <?php
                                $variation = wc_get_product($variation_id);
                                if (!$variation || !$variation->exists()) {
                                    continue;
                                }
                                $variation_label = wc_get_formatted_variation($variation, true, false, true);
                                if ($variation_label) {
                                    $variation_label = sprintf('%s - %s', $product->get_name(), $variation_label);
                                } else {
                                    $variation_label = $variation->get_name();
                                }
                                ?>
                                <tr>
                                    <td style="padding-left: 30px;">
                                        <input type="checkbox"
                                            name="storelinkformc_selected_products[]"
                                            value="<?php echo esc_attr($variation->get_id()); ?>"
                                            <?php checked(in_array($variation->get_id(), $selected, true)); ?>>
                                    </td>
                                    <td style="padding-left: 30px; color: #646970;">
                                        <?php echo esc_html($variation_label); ?>
                                    </td>
                                    <td><?php echo esc_html((string) $variation->get_id()); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button(__('Save Selection', 'storelinkformc')); ?>
        </form>
    </div>
    <?php
}

/**
 * Load JS only in this admin page
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'storelinkformc_page_storelinkformc_products') {
        return;
    }

    wp_register_script(
        'storelinkformc-products',
        plugins_url('../assets/js/products.js', __FILE__),
        [],
        '1.0.0',
        true
    );

    wp_enqueue_script('storelinkformc-products');
});
