<?php
if (!defined('ABSPATH')) {
    exit;
}

function storelinkformc_sync_roles_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $all_roles      = get_editable_roles();
    $editable_slugs = array_keys($all_roles);

    $selected_role = get_option('storelinkformc_default_linked_role', '');
    $role_map      = get_option('storelinkformc_product_roles_map', []);
    $sync_products = get_option('storelinkformc_sync_products', []);
    $products      = [];

    if (function_exists('wc_get_product') && !empty($sync_products)) {
        foreach ($sync_products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $products[] = $product;
            }
        }
    }

    $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    if ('POST' === $request_method) {
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
        $slug = isset($_POST['storelinkformc_new_role_slug']) ? sanitize_key(wp_unslash($_POST['storelinkformc_new_role_slug'])) : '';
        $name = isset($_POST['storelinkformc_new_role_name']) ? sanitize_text_field(wp_unslash($_POST['storelinkformc_new_role_name'])) : '';
        if ('' !== $slug && '' !== $name) {

            if (!preg_match('/^[a-z0-9_\-]{3,30}$/', $slug)) {
                echo '<div class="notice notice-error"><p>' . esc_html__('⚠️ Invalid slug. Use lowercase letters, numbers, hyphens.', 'storelinkformc') . '</p></div>';
            } elseif (!get_role($slug)) {
                add_role($slug, $name, ['read' => true]);
                echo '<div class="updated notice"><p>' . esc_html__('✅ New role created successfully.', 'storelinkformc') . '</p></div>';
                $all_roles      = get_editable_roles();
                $editable_slugs = array_keys($all_roles);
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('⚠️ Role already exists.', 'storelinkformc') . '</p></div>';
            }
        }

        // Save product-role mapping
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (isset($_POST['storelinkformc_product_roles']) && is_array($_POST['storelinkformc_product_roles'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $product_roles_raw = wp_unslash($_POST['storelinkformc_product_roles']);
            $map = [];
            foreach ($product_roles_raw as $product_id => $role) {
                $role = sanitize_text_field($role);
                if (!empty($role) && in_array($role, $editable_slugs, true)) {
                    $map[(int) $product_id] = $role;
                }
            }
            update_option('storelinkformc_product_roles_map', $map);
            $role_map = $map;
            echo '<div class="updated notice"><p>' . esc_html__('✅ Product role mappings saved.', 'storelinkformc') . '</p></div>';
        }
    }
    $mapped_count = is_array($role_map) ? count(array_filter($role_map)) : 0;
    ?>
    <div class="wrap storelinkformc-admin">
        <div class="storelinkformc-admin-header">
            <div>
                <h1><?php esc_html_e('Sync Roles', 'storelinkformc'); ?></h1>
                <p class="storelinkformc-admin-subtitle">
                    <?php esc_html_e('Assign WordPress roles when players link their Minecraft account or purchase synced products.', 'storelinkformc'); ?>
                </p>
            </div>
            <div class="storelinkformc-admin-stats" aria-hidden="true">
                <div class="storelinkformc-stat">
                    <strong><?php echo esc_html((string) count($all_roles)); ?></strong>
                    <span><?php esc_html_e('roles', 'storelinkformc'); ?></span>
                </div>
                <div class="storelinkformc-stat">
                    <strong><?php echo esc_html((string) count($products)); ?></strong>
                    <span><?php esc_html_e('products', 'storelinkformc'); ?></span>
                </div>
                <div class="storelinkformc-stat">
                    <strong><?php echo esc_html((string) $mapped_count); ?></strong>
                    <span><?php esc_html_e('mapped', 'storelinkformc'); ?></span>
                </div>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field('storelinkformc_sync_roles'); ?>

            <div class="storelinkformc-form-grid">
                <div class="storelinkformc-control-card">
                    <h2><?php esc_html_e('Minecraft link role', 'storelinkformc'); ?></h2>
                    <label for="storelinkformc_selected_role">
                        <?php esc_html_e('Role assigned after linking', 'storelinkformc'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('This role will be assigned when a Minecraft account is linked.', 'storelinkformc'); ?>
                    </p>
                    <p>
                        <select name="storelinkformc_selected_role" id="storelinkformc_selected_role">
                            <option value=""><?php esc_html_e('— NONE —', 'storelinkformc'); ?></option>
                            <?php foreach ($all_roles as $slug => $details) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_role, $slug); ?>>
                                    <?php echo esc_html($details['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>

                <div class="storelinkformc-control-card">
                    <h2><?php esc_html_e('Create role', 'storelinkformc'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Create a lightweight WordPress role with read access, then use it in mappings below.', 'storelinkformc'); ?>
                    </p>
                    <p>
                        <label for="storelinkformc_new_role_name"><?php esc_html_e('Role name', 'storelinkformc'); ?></label>
                        <input id="storelinkformc_new_role_name" type="text" name="storelinkformc_new_role_name" class="regular-text" placeholder="<?php esc_attr_e('VIP Player', 'storelinkformc'); ?>" />
                    </p>
                    <p>
                        <label for="storelinkformc_new_role_slug"><?php esc_html_e('Role slug', 'storelinkformc'); ?></label>
                        <input id="storelinkformc_new_role_slug" type="text" name="storelinkformc_new_role_slug" class="regular-text" placeholder="<?php esc_attr_e('vip_player', 'storelinkformc'); ?>" />
                    </p>
                </div>
            </div>

            <div class="storelinkformc-panel" style="margin-top:18px;">
                <div class="storelinkformc-panel-header">
                    <div>
                        <h2><?php esc_html_e('Roles by product', 'storelinkformc'); ?></h2>
                        <p><?php esc_html_e('These are the products currently set to sync with Minecraft. Choose which role to assign when each is purchased.', 'storelinkformc'); ?></p>
                    </div>
                </div>

                <?php if (empty($products)) : ?>
                    <div class="storelinkformc-panel-body">
                        <div class="storelinkformc-empty">
                            <?php esc_html_e('No synced products yet. Select products on the Products page before mapping roles.', 'storelinkformc'); ?>
                        </div>
                    </div>
                <?php else : ?>
                    <table class="widefat fixed striped storelinkformc-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Product', 'storelinkformc'); ?></th>
                                <th style="width: 320px;"><?php esc_html_e('Role', 'storelinkformc'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product) : ?>
                                <tr>
                                    <td>
                                        <span class="storelinkformc-product-name">
                                            <strong><?php echo esc_html($product->get_name()); ?></strong>
                                            <span class="storelinkformc-muted"><?php esc_html_e('Woo ID:', 'storelinkformc'); ?> <?php echo esc_html((string) $product->get_id()); ?></span>
                                        </span>
                                    </td>
                                    <td>
                                        <select class="storelinkformc-role-select" name="storelinkformc_product_roles[<?php echo esc_attr($product->get_id()); ?>]">
                                            <option value=""><?php esc_html_e('— NONE —', 'storelinkformc'); ?></option>
                                            <?php foreach ($all_roles as $slug => $details) : ?>
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
                <?php endif; ?>

                <div class="storelinkformc-actions">
                    <?php submit_button(__('Save Role Settings', 'storelinkformc'), 'primary', 'submit', false); ?>
                </div>
            </div>
        </form>
    </div>
    <?php
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'storelinkformc_page_storelinkformc_sync_roles') {
        return;
    }

    wp_enqueue_style(
        'storelinkformc-admin-pages',
        plugins_url('../assets/css/admin-pages.css', __FILE__),
        [],
        filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin-pages.css')
    );

    wp_register_script(
        'storelinkformc-sync-roles',
        plugins_url('../assets/js/sync-roles.js', __FILE__),
        [],
        '1.0.0',
        true
    );
    wp_enqueue_script('storelinkformc-sync-roles');
});
