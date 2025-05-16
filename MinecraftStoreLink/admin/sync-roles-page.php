<?php

function minecraftstorelink_sync_roles_page() {
    if (!current_user_can('manage_options')) return;

    $all_roles = get_editable_roles();
    $selected_role = get_option('minecraftstorelink_default_linked_role', '');
    $role_map = get_option('minecraftstorelink_product_roles_map', []);
    $sync_products = get_option('minecraftstorelink_sync_products', []);
    $products = [];

    if (function_exists('wc_get_product') && !empty($sync_products)) {
        foreach ($sync_products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) $products[] = $product;
        }
    }

    // Guardar datos si se envió el formulario
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['minecraftstorelink_selected_role'])) {
            update_option('minecraftstorelink_default_linked_role', sanitize_text_field($_POST['minecraftstorelink_selected_role']));
            echo '<div class="updated notice"><p>✅ Role selection saved.</p></div>';
        }

        if (!empty($_POST['minecraftstorelink_new_role_slug']) && !empty($_POST['minecraftstorelink_new_role_name'])) {
            $slug = sanitize_key($_POST['minecraftstorelink_new_role_slug']);
            $name = sanitize_text_field($_POST['minecraftstorelink_new_role_name']);

            if (!get_role($slug)) {
                add_role($slug, $name);
                echo '<div class="updated notice"><p>✅ New role created successfully.</p></div>';
                $all_roles = get_editable_roles();
            } else {
                echo '<div class="notice notice-error"><p>⚠️ Role already exists.</p></div>';
            }
        }

        if (isset($_POST['minecraftstorelink_product_roles']) && is_array($_POST['minecraftstorelink_product_roles'])) {
            $map = [];
            foreach ($_POST['minecraftstorelink_product_roles'] as $product_id => $role) {
                $role = sanitize_text_field($role);
                if (!empty($role)) {
                    $map[intval($product_id)] = $role;
                }
            }
            update_option('minecraftstorelink_product_roles_map', $map);
            $role_map = $map;
            echo '<div class="updated notice"><p>✅ Product role mappings saved.</p></div>';
        }
    }
    ?>

    <div class="wrap">
        <h1>Sync WordPress Roles with Minecraft Link</h1>
        <form method="post">
            <h2>Assign Role on Minecraft Link</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="minecraftstorelink_selected_role">Select Role</label></th>
                    <td>
                        <select name="minecraftstorelink_selected_role" id="minecraftstorelink_selected_role">
                            <option value="">— NONE —</option>
                            <?php foreach ($all_roles as $slug => $details): ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_role, $slug); ?>>
                                    <?php echo esc_html($details['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">This role will be assigned when a Minecraft account is linked.</p>
                    </td>
                </tr>
            </table>

            <h2>Create New Role</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="minecraftstorelink_new_role_name">Role Name</label></th>
                    <td><input type="text" name="minecraftstorelink_new_role_name" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="minecraftstorelink_new_role_slug">Role Slug</label></th>
                    <td><input type="text" name="minecraftstorelink_new_role_slug" class="regular-text"></td>
                </tr>
            </table>

            <h2>Assign Roles by Product</h2>
            <p>These are the products currently set to sync with Minecraft. Choose which role to assign when each is purchased.</p>
            <table class="widefat fixed striped">
                <thead><tr><th>Product</th><th>Role</th></tr></thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?php echo esc_html($product->get_name()); ?></td>
                            <td>
                                <select name="minecraftstorelink_product_roles[<?php echo $product->get_id(); ?>]">
                                    <option value="">— NONE —</option>
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
            <?php submit_button('Save All Role Settings'); ?>
        </form>
    </div>

    <?php
}
