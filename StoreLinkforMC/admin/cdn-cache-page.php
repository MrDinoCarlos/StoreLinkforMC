<?php
if (!defined('ABSPATH')) {
    exit;
}

// Register submenu in the plugin admin panel
add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        __('CDN & Cache', 'storelinkformc'),
        __('CDN & Cache', 'storelinkformc'),
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
        $zone = isset($_POST['storelinkformc_cf_zone_id']) ? sanitize_text_field(wp_unslash($_POST['storelinkformc_cf_zone_id'])) : '';
        $tok  = isset($_POST['storelinkformc_cf_api_token']) ? sanitize_text_field(wp_unslash($_POST['storelinkformc_cf_api_token'])) : '';

        update_option('storelinkformc_cf_zone_id', $zone);
        update_option('storelinkformc_cf_api_token', $tok);

        echo '<div class="notice notice-success is-dismissible"><p>' .
             esc_html__('Cloudflare settings saved.', 'storelinkformc') .
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
                     esc_html__('Cache rule created/updated successfully on Cloudflare.', 'storelinkformc') .
                     '</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>' .
                 esc_html__('Missing Zone ID or API Token.', 'storelinkformc') .
                 '</p></div>';
        }
    }

    $zone = get_option('storelinkformc_cf_zone_id', '');
    $tok  = get_option('storelinkformc_cf_api_token', '');

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('CDN & Cache', 'storelinkformc'); ?></h1>
        <p>
            <?php esc_html_e('Configure Cloudflare to bypass cache on StoreLinkforMC REST endpoints.', 'storelinkformc'); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field('storelinkformc_cf_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="storelinkformc_cf_zone_id">
                            <?php esc_html_e('Cloudflare Zone ID', 'storelinkformc'); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            name="storelinkformc_cf_zone_id"
                            id="storelinkformc_cf_zone_id"
                            type="text"
                            class="regular-text"
                            value="<?php echo esc_attr($zone); ?>"
                            required
                        >
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="storelinkformc_cf_api_token">
                            <?php esc_html_e('Cloudflare API Token', 'storelinkformc'); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            name="storelinkformc_cf_api_token"
                            id="storelinkformc_cf_api_token"
                            type="password"
                            class="regular-text"
                            value="<?php echo esc_attr($tok); ?>"
                            required
                        >
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="storelinkformc_cf_save" class="button button-primary">
                    <?php esc_html_e('Save', 'storelinkformc'); ?>
                </button>
                <button type="submit" name="storelinkformc_cf_apply" class="button">
                    <?php esc_html_e('Create/Update Cache Rule on Cloudflare', 'storelinkformc'); ?>
                </button>
            </p>

            <h2><?php esc_html_e('What does this rule do?', 'storelinkformc'); ?></h2>
            <p><?php esc_html_e('It bypasses cache for:', 'storelinkformc'); ?></p>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><code>/wp-json/storelinkformc/...</code></li>
                <li><code>?rest_route=/storelinkformc/...</code></li>
            </ul>
            <p>
                <?php esc_html_e('If you use “Cache Everything”, this rule is essential.', 'storelinkformc'); ?>
            </p>
        </form>
    </div>
    <?php
}
