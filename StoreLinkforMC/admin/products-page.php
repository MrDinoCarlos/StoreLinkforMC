<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', 'storelinkformc_add_products_submenu');

function storelinkformc_add_products_submenu() {
    add_submenu_page(
        'storelinkformc',
        'Synced Products',
        'Products',
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

    if (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['storelinkformc_selected_products']) &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'storelinkformc_products_save')
    ) {
        $product_ids = array_map('intval', (array) $_POST['storelinkformc_selected_products']);
        update_option('storelinkformc_sync_products', $product_ids);

        echo '<div class="updated"><p>' . esc_html__('Products saved successfully.', 'storelinkformc') . '</p></div>';
    }


    $selected = get_option('storelinkformc_sync_products', []);
    $products = wc_get_products(['limit' => -1]);

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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <input type="checkbox"
                                       name="storelinkformc_selected_products[]"
                                       value="<?php echo esc_attr($product->get_id()); ?>"
                                       <?php checked(in_array($product->get_id(), $selected, true)); ?>>
                            </td>
                            <td><?php echo esc_html($product->get_name()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button(__('Save Selection', 'storelinkformc')); ?>
        </form>
    </div>
    <?php
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'storelinkformc_page_storelinkformc_products') return;

    wp_register_script(
        'storelinkformc-products',
        plugins_url('../assets/js/products.js', __FILE__),
        [],
        '1.0.0',
        true
    );
    wp_enqueue_script('storelinkformc-products');
});
