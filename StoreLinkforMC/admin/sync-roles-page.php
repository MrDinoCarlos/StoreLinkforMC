<?php
if (!defined('ABSPATH')) exit;

function storelinkformc_sync_roles_page() {
    if (!current_user_can('manage_options')) return;

    $all_roles = get_editable_roles();
    $editable_slugs = array_keys($all_roles);

    $selected_role = get_option('storelinkformc_default_linked_role', '');
    $role_map = get_option('storelinkformc_product_roles_map', []);
    $sync_products = get_option('storelinkformc_sync_products', []);
    $products = [];

    if (function_exists('wc_get_product') && !empty($sync_products)) {
        foreach ($sync_products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) $products[] = $product;
        }
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('storelinkformc_sync_roles');

        // Save default linked role
        if (isset($_POST['storelinkformc_selected_role'])) {
            $selected = sanitize_text_field(wp_unslash($_POST['storelinkformc_selected_role']));
            if (in_array($selected, $editable_slugs, true)) {
                update_option('storelinkformc_default_linked_role', $selected);
                echo '<div class="updated notice"><p>' . esc_html__('✅ Role selection saved.', 'storelinkformc') . '</p></div>';
            }
        }

        // Create new role
        if (!empty($_POST['storelinkformc_new_role_slug']) && !empty($_POST['storelinkformc_new_role_name'])) {
            $slug = sanitize_key(wp_unslash($_POST['storelinkformc_new_role_slug']));
            $name = sanitize_text_field(wp_unslash($_POST['storelinkformc_new_role_name']));

            if (!preg_match('/^[a-z0-9_\-]{3,30}$/', $slug)) {
                echo '<div class="notice notice-error"><p>' . esc_html__('⚠️ Invalid slug. Use lowercase letters, numbers, hyphens.', 'storelinkformc') . '</p></div>';
            } elseif (!get_role($slug)) {
                add_role($slug, $name, ['read' => true]);
                echo '<div class="updated notice"><p>' . esc_html__('✅ New role created successfully.', 'storelinkformc') . '</p></div>';
                $all_roles = get_editable_roles();
                $editable_slugs = array_keys($all_roles);
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('⚠️ Role already exists.', 'storelinkformc') . '</p></div>';
            }
        }

        // Save product-role mapping
        if (isset($_POST['storelinkformc_product_roles']) && is_array($_POST['storelinkformc_product_roles'])) {
            $map = [];
            foreach ($_POST['storelinkformc_product_roles'] as $product_id => $role) {
                $role = sanitize_text_field(wp_unslash($role));
                if (!empty($role) && in_array($role, $editable_slugs, true)) {
                    $map[intval($product_id)] = $role;
                }
            }
            update_option('storelinkformc_product_roles_map', $map);
            $role_map = $map;
            echo '<div class="updated notice"><p>' . esc_html__('✅ Product role mappings saved.', 'storelinkformc') . '</p></div>';
        }
    }
?>
    <div class="wrap">
        <h1><?php esc_html_e('Sync WordPress Roles with Minecraft Link', 'storelinkformc'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('storelinkformc_sync_roles'); ?>

            <h2><?php esc_html_e('Assign Role on Minecraft Link', 'storelinkformc'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="storelinkformc_selected_role"><?php esc_html_e('Select Role', 'storelinkformc'); ?></label></th>
                    <td>
                        <select name="storelinkformc_selected_role" id="storelinkformc_selected_role">
                            <option value=""><?php esc_html_e('— NONE —', 'storelinkformc'); ?></option>
                            <?php foreach ($all_roles as $slug => $details): ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_role, $slug); ?>>
                                    <?php echo esc_html($details['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('This role will be assigned when a Minecraft account is linked.', 'storelinkformc'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Create New Role', 'storelinkformc'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="storelinkformc_new_role_name"><?php esc_html_e('Role Name', 'storelinkformc'); ?></label></th>
                    <td><input type="text" name="storelinkformc_new_role_name" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="storelinkformc_new_role_slug"><?php esc_html_e('Role Slug', 'storelinkformc'); ?></label></th>
                    <td><input type="text" name="storelinkformc_new_role_slug" class="regular-text"></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Assign Roles by Product', 'storelinkformc'); ?></h2>
            <p><?php esc_html_e('These are the products currently set to sync with Minecraft. Choose which role to assign when each is purchased.', 'storelinkformc'); ?></p>
            <table class="widefat fixed striped">
                <thead><tr><th><?php esc_html_e('Product', 'storelinkformc'); ?></th><th><?php esc_html_e('Role', 'storelinkformc'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?php echo esc_html($product->get_name()); ?></td>
                            <td>
                                <select name="storelinkformc_product_roles[<?php echo esc_attr($product->get_id()); ?>]">
                                    <option value=""><?php esc_html_e('— NONE —', 'storelinkformc'); ?></option>
                                    <?php foreach ($all_roles as $slug => $details): ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($role_map[$product->get_id()] ?? '', $slug); ?>>
                                            <?php echo esc_html($details['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <br>
            <?php submit_button(__('Save All Role Settings', 'storelinkformc')); ?>
        </form>
    </div>
<?php
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'storelinkformc_page_storelinkformc_sync_roles') return;

    wp_register_script(
        'storelinkformc-sync-roles',
        plugins_url('../assets/js/sync-roles.js', __FILE__),
        [],
        '1.0.0',
        true
    );
    wp_enqueue_script('storelinkformc-sync-roles');
});
