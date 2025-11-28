<?php
if (!defined('ABSPATH')) {
    exit;
}

// Register submenu in the plugin admin panel
add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        __('CDN & Cache', 'StoreLinkforMC'),
        __('CDN & Cache', 'StoreLinkforMC'),
        'manage_options',
        'storelinkformc_cdn_cache',
        'storelinkformc_cdn_cache_page'
    );
});

function storelinkformc_cdn_cache_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Save Cloudflare credentials
    if (isset($_POST['storelinkformc_cf_save']) && check_admin_referer('storelinkformc_cf_settings')) {
        $zone_raw = isset($_POST['storelinkformc_cf_zone_id']) ? wp_unslash($_POST['storelinkformc_cf_zone_id']) : '';
        $tok_raw  = isset($_POST['storelinkformc_cf_api_token']) ? wp_unslash($_POST['storelinkformc_cf_api_token']) : '';

        update_option('storelinkformc_cf_zone_id', sanitize_text_field($zone_raw));
        update_option('storelinkformc_cf_api_token', sanitize_text_field($tok_raw));


        update_option('storelinkformc_cf_zone_id', $zone);
        update_option('storelinkformc_cf_api_token', $tok);

        echo '<div class="notice notice-success is-dismissible"><p>' .
             esc_html__('Cloudflare settings saved.', 'StoreLinkforMC') .
             '</p></div>';
    }

    // Execute creation/update of the cache rule
    if (
        isset($_POST['storelinkformc_cf_apply']) &&
        check_admin_referer('storelinkformc_cf_settings')
    ) {
        $zone = get_option('storelinkformc_cf_zone_id', '');
        $tok  = get_option('storelinkformc_cf_api_token', '');

        if ($zone && $tok) {
            if (!function_exists('storelinkformc_cf_upsert_cache_rule')) {
                require_once plugin_dir_path(__FILE__) . '../includes/cloudflare-api.php';
            }

            $res = storelinkformc_cf_upsert_cache_rule($zone, $tok);
            if (is_wp_error($res)) {
                $raw_msg = $res->get_error_message();
                $data    = $res->get_error_data();
                $code    = is_array($data) && isset($data['code']) ? (int) $data['code'] : 0;

                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Cloudflare Error:', 'storelinkformc') . '</strong> ' .
                     esc_html($raw_msg) . ' (HTTP ' . esc_html((string) $code) . ')</p></div>';

            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                     esc_html__('Cache rule created/updated successfully on Cloudflare.', 'StoreLinkforMC') .
                     '</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>' .
                 esc_html__('Missing Zone ID or API Token.', 'StoreLinkforMC') .
                 '</p></div>';
        }
    }

    $zone = get_option('storelinkformc_cf_zone_id', '');
    $tok  = get_option('storelinkformc_cf_api_token', '');

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('CDN & Cache', 'StoreLinkforMC'); ?></h1>
        <p>
            <?php esc_html_e('Configure Cloudflare to bypass cache on StoreLinkforMC REST endpoints.', 'StoreLinkforMC'); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field('storelinkformc_cf_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="storelinkformc_cf_zone_id">
                            <?php esc_html_e('Cloudflare Zone ID', 'StoreLinkforMC'); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            name="storelinkformc_cf_zone_id"
                            id="storelinkformc_cf_zone_id"
                            type="text"
                            class="regular-text"
                            value="<?php echo $zone; ?>"
                            required
                        >
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="storelinkformc_cf_api_token">
                            <?php esc_html_e('Cloudflare API Token', 'StoreLinkforMC'); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            name="storelinkformc_cf_api_token"
                            id="storelinkformc_cf_api_token"
                            type="password"
                            class="regular-text"
                            value="<?php echo $tok; ?>"
                            required
                        >
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="storelinkformc_cf_save" class="button button-primary">
                    <?php esc_html_e('Save', 'StoreLinkforMC'); ?>
                </button>
                <button type="submit" name="storelinkformc_cf_apply" class="button">
                    <?php esc_html_e('Create/Update Cache Rule on Cloudflare', 'StoreLinkforMC'); ?>
                </button>
            </p>

            <h2><?php esc_html_e('What does this rule do?', 'StoreLinkforMC'); ?></h2>
            <p><?php esc_html_e('It bypasses cache for:', 'StoreLinkforMC'); ?></p>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><code>/wp-json/storelinkformc/...</code></li>
                <li><code>?rest_route=/storelinkformc/...</code></li>
            </ul>
            <p>
                <?php esc_html_e('If you use “Cache Everything”, this rule is essential.', 'StoreLinkforMC'); ?>
            </p>
        </form>
    </div>
    <?php
}
