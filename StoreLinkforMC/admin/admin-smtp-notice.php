<?php
// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * SMTP notice for StoreLink for MC
 * - Shows a dismissible warning on the plugin settings page if no SMTP is configured
 * - Per-user dismissal stored in user_meta
 * - Change SLMC_SETTINGS_PAGE_SLUG if your settings page uses a different slug (?page=XXXX)
 */

// Define the settings page slug once
if (!defined('SLMC_SETTINGS_PAGE_SLUG')) {
    define('SLMC_SETTINGS_PAGE_SLUG', 'storelinkformc');
}

// Heuristic: is SMTP configured?
if (!function_exists('slmc_is_smtp_configured')) {
    function slmc_is_smtp_configured() {
        // 1) WP Mail SMTP plugin (most common)
        if (class_exists('\\WPMailSMTP\\Options')) {
            // Avoid try/catch with Throwable for max compatibility
            $opts = \WPMailSMTP\Options::init();
            if (is_object($opts)) {
                $mailer = $opts->get('mail', 'mailer'); // 'smtp','sendgrid','mailgun','gmail','ses','postmark','smtpcom', etc. | 'mail' = PHP mail()
                if (!empty($mailer) && $mailer !== 'mail') {
                    return true;
                }
            }
        }

        // 2) Stored WP Mail SMTP options
        $wpms = get_option('wp_mail_smtp');
        if (is_array($wpms) && !empty($wpms['mail']['mailer']) && $wpms['mail']['mailer'] !== 'mail') {
            return true;
        }

        // 3) Legacy SMTP constants
        if (defined('SMTP_HOST') && defined('SMTP_PORT')) {
            return true;
        }

        // 4) Any plugin hooking phpmailer_init usually forces SMTP
        if (has_action('phpmailer_init')) {
            return true;
        }

        return false;
    }
}

// Handle notice dismissal (per user)
add_action('admin_init', function () {
    if (!is_user_logged_in()) {
        return;
    }
    if (isset($_GET['slmc_dismiss_smtp'], $_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'slmc_dismiss_smtp')) {
        update_user_meta(get_current_user_id(), 'slmc_dismiss_smtp_notice', 1);
        wp_safe_redirect(remove_query_arg(array('slmc_dismiss_smtp','_wpnonce')));
        exit;
    }
});

// Show the notice only on the plugin settings page in admin
add_action('admin_notices', function () {
    if (!is_admin()) {
        return;
    }
    if (!function_exists('get_current_screen')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    // Only on our settings page
    $on_plugin_page = (isset($_GET['page']) && $_GET['page'] === SLMC_SETTINGS_PAGE_SLUG);
    if (!$on_plugin_page) {
        return;
    }

    // If user dismissed it, don't show again
    if (get_user_meta(get_current_user_id(), 'slmc_dismiss_smtp_notice', true)) {
        return;
    }

    // Optional: only warn when "force linking" is enabled — change the option key if yours is different
    $force_linking = (get_option('storelinkformc_force_link', 'no') === 'yes');


    if ($force_linking && !slmc_is_smtp_configured()) {
        $dismiss_url = wp_nonce_url(add_query_arg('slmc_dismiss_smtp', '1'), 'slmc_dismiss_smtp');

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>StoreLink for MC:</strong> It looks like your site does <u>not have an authenticated SMTP</u> configured. ';
        echo 'With <em>Force email linking</em> enabled, some users might <strong>not receive the verification code</strong> due to WordPress/hosting limits or spam filters.</p>';
        echo '<p>Recommended: install and configure an SMTP plugin (e.g. <em>WP Mail SMTP</em>) and add SPF/DKIM to your domain.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('plugins.php')) . '">Set up SMTP</a> ';
        echo '<a class="button" href="' . esc_url($dismiss_url) . '">Got it, don’t show again</a></p>';
        echo '</div>';
    }
});
