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
    $variation_count = 0;
    foreach ($products as $product) {
        if ($product->is_type('variable')) {
            $variation_count += count($product->get_children());
        }
    }
    ?>

    <div class="wrap storelinkformc-admin">
        <div class="storelinkformc-admin-header">
            <div>
                <h1><?php esc_html_e('Products', 'storelinkformc'); ?></h1>
                <p class="storelinkformc-admin-subtitle">
                    <?php esc_html_e('Select the WooCommerce products and variations that should trigger Minecraft delivery and role sync logic.', 'storelinkformc'); ?>
                </p>
            </div>
            <div class="storelinkformc-admin-stats" aria-hidden="true">
                <div class="storelinkformc-stat">
                    <strong><?php echo esc_html((string) count($selected)); ?></strong>
                    <span><?php esc_html_e('selected', 'storelinkformc'); ?></span>
                </div>
                <div class="storelinkformc-stat">
                    <strong><?php echo esc_html((string) count($products)); ?></strong>
                    <span><?php esc_html_e('products', 'storelinkformc'); ?></span>
                </div>
                <div class="storelinkformc-stat">
                    <strong><?php echo esc_html((string) $variation_count); ?></strong>
                    <span><?php esc_html_e('variations', 'storelinkformc'); ?></span>
                </div>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field('storelinkformc_products_save'); ?>

            <div class="storelinkformc-panel">
                <div class="storelinkformc-panel-header">
                    <div>
                        <h2><?php esc_html_e('Synced catalog items', 'storelinkformc'); ?></h2>
                        <p><?php esc_html_e('Select parent products, specific variations, or both depending on how your store sells Minecraft rewards.', 'storelinkformc'); ?></p>
                    </div>
                </div>

                <?php if (empty($products)) : ?>
                    <div class="storelinkformc-panel-body">
                        <div class="storelinkformc-empty">
                            <?php esc_html_e('No WooCommerce products were found.', 'storelinkformc'); ?>
                        </div>
                    </div>
                <?php else : ?>
                    <table class="widefat fixed striped storelinkformc-table">
                        <thead>
                            <tr>
                                <th style="width: 74px;"><?php esc_html_e('Sync', 'storelinkformc'); ?></th>
                                <th><?php esc_html_e('Product', 'storelinkformc'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Woo ID', 'storelinkformc'); ?></th>
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
                                    <td>
                                        <span class="storelinkformc-product-name">
                                            <strong><?php echo esc_html($product->get_name()); ?></strong>
                                            <span class="storelinkformc-muted"><?php echo esc_html(wc_get_product_types()[$product->get_type()] ?? $product->get_type()); ?></span>
                                        </span>
                                    </td>
                                    <td><span class="storelinkformc-id-pill"><?php echo esc_html((string) $product->get_id()); ?></span></td>
                                </tr>
                                <?php if ($product->is_type('variable')) : ?>
                                    <?php foreach ($product->get_children() as $variation_id) : ?>
                                        <?php
                                        $variation = wc_get_product($variation_id);
                                        if (!$variation || !$variation->exists()) {
                                            continue;
                                        }
                                        $variation_label = wc_get_formatted_variation($variation, true, false, true);
                                        if (!$variation_label) {
                                            $variation_label = $variation->get_name();
                                        }
                                        ?>
                                        <tr class="storelinkformc-variation">
                                            <td>
                                                <input type="checkbox"
                                                    name="storelinkformc_selected_products[]"
                                                    value="<?php echo esc_attr($variation->get_id()); ?>"
                                                    <?php checked(in_array($variation->get_id(), $selected, true)); ?>>
                                            </td>
                                            <td>
                                                <span class="storelinkformc-product-name">
                                                    <strong><?php echo esc_html($variation_label); ?></strong>
                                                    <span class="storelinkformc-muted"><?php echo esc_html($product->get_name()); ?></span>
                                                </span>
                                            </td>
                                            <td><span class="storelinkformc-id-pill"><?php echo esc_html((string) $variation->get_id()); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <div class="storelinkformc-actions">
                    <?php submit_button(__('Save Selection', 'storelinkformc'), 'primary', 'submit', false); ?>
                </div>
            </div>
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

    wp_enqueue_style(
        'storelinkformc-admin-pages',
        plugins_url('../assets/css/admin-pages.css', __FILE__),
        [],
        filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin-pages.css')
    );

    wp_register_script(
        'storelinkformc-products',
        plugins_url('../assets/js/products.js', __FILE__),
        [],
        '1.0.0',
        true
    );

    wp_enqueue_script('storelinkformc-products');
});
