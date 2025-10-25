<?php
if (!defined('ABSPATH')) exit;

// Register submenu in the plugin admin panel
add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        'CDN & Cache',
        'CDN & Cache',
        'manage_options',
        'storelinkformc_cdn_cache',
        'storelinkformc_cdn_cache_page'
    );
});

function storelinkformc_cdn_cache_page() {
    if (!current_user_can('manage_options')) return;

    // Save Cloudflare credentials
    if (isset($_POST['storelinkformc_cf_save']) && check_admin_referer('storelinkformc_cf_settings')) {
        update_option('storelinkformc_cf_zone_id', sanitize_text_field($_POST['storelinkformc_cf_zone_id'] ?? ''));
        update_option('storelinkformc_cf_api_token', sanitize_text_field($_POST['storelinkformc_cf_api_token'] ?? ''));
        echo '<div class="notice notice-success is-dismissible"><p>Cloudflare settings saved.</p></div>';
    }

    // Execute creation/update of the cache rule
    if (isset($_POST['storelinkformc_cf_apply']) && check_admin_referer('storelinkformc_cf_settings')) {
        $zone = get_option('storelinkformc_cf_zone_id', '');
        $tok  = get_option('storelinkformc_cf_api_token', '');
        if ($zone && $tok) {
            if (!function_exists('storelinkformc_cf_upsert_cache_rule')) {
                require_once plugin_dir_path(__FILE__) . '../includes/cloudflare-api.php';
            }
            $res = storelinkformc_cf_upsert_cache_rule($zone, $tok);
            if (is_wp_error($res)) {
                $msg = esc_html($res->get_error_message());
                $data = $res->get_error_data();
                $code = is_array($data) && isset($data['code']) ? intval($data['code']) : 0;
                echo '<div class="notice notice-error"><p><strong>Cloudflare Error:</strong> ' . $msg . ' (HTTP ' . $code . ')</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>Cache rule created/updated successfully on Cloudflare.</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>Missing Zone ID or API Token.</p></div>';
        }
    }

    $zone = esc_attr(get_option('storelinkformc_cf_zone_id', ''));
    $tok  = esc_attr(get_option('storelinkformc_cf_api_token', ''));

    ?>
    <div class="wrap">
        <h1>CDN & Cache</h1>
        <p>Configure Cloudflare to <strong>bypass cache</strong> on StoreLinkforMC REST endpoints.</p>

        <form method="post">
            <?php wp_nonce_field('storelinkformc_cf_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="storelinkformc_cf_zone_id">Cloudflare Zone ID</label></th>
                    <td><input name="storelinkformc_cf_zone_id" id="storelinkformc_cf_zone_id" type="text" class="regular-text" value="<?php echo $zone; ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="storelinkformc_cf_api_token">Cloudflare API Token</label></th>
                    <td><input name="storelinkformc_cf_api_token" id="storelinkformc_cf_api_token" type="password" class="regular-text" value="<?php echo $tok; ?>" required></td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="storelinkformc_cf_save" class="button button-primary">Save</button>
                <button type="submit" name="storelinkformc_cf_apply" class="button">Create/Update Cache Rule on Cloudflare</button>
            </p>

            <h2>What does this rule do?</h2>
            <p>It bypasses cache for:</p>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><code>/wp-json/storelinkformc/...</code></li>
                <li><code>?rest_route=/storelinkformc/...</code></li>
            </ul>
            <p>If you use “Cache Everything”, this rule is essential.</p>
        </form>
    </div>
    <?php
}
