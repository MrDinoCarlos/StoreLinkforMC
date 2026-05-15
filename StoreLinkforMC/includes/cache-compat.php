<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache compatibility layer for StoreLinkforMC
 * - Disables cache/optimization on StoreLinkforMC REST endpoints
 * - Adds headers respected by cache/CDN systems (Cloudflare, LSCache, etc.)
 * - Displays admin notice with URL patterns to exclude (LSCache / WP Rocket)
 * - Optional soft purge helpers
 */

// 1) Runtime signals/headers for our endpoints
add_action('init', function () {
    $uri = '';
    if (isset($_SERVER['REQUEST_URI'])) {
        $uri = sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI']));
    }

    $is_storelink_endpoint = false;

    if ($uri !== '') {
        // Pretty permalinks: /wp-json/storelinkformc/v1/...
        if (strpos($uri, '/wp-json/storelinkformc/v1/') === 0) {
            $is_storelink_endpoint = true;
        }

        // Fallback REST (?rest_route=) or manual trigger with ?storelinkformc=1
        if (!$is_storelink_endpoint) {
            $q = [];
            $query = wp_parse_url($uri, PHP_URL_QUERY);
            if (is_string($query) && '' !== $query) {
                wp_parse_str($query, $q);
                $q = array_map('sanitize_text_field', $q);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!empty($q['storelinkformc'])) {
                $is_storelink_endpoint = true;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            } elseif (!empty($q['rest_route']) && strpos((string) $q['rest_route'], '/storelinkformc/') === 0) {
                $is_storelink_endpoint = true;
            }
        }
    }

    if (!$is_storelink_endpoint) {
        return;
    }

    // Signals respected by most cache/CDN plugins
    if (!defined('DONOTCACHE_PAGE')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        define('DONOTCACHE_PAGE', true);
    }
    if (!defined('DONOTCACHE_OBJECT')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        define('DONOTCACHE_OBJECT', true);
    }
    if (!defined('DONOTMINIFY')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        define('DONOTMINIFY', true);
    }
    if (!defined('DONOTCDN')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        define('DONOTCDN', true);
    }
    if (!defined('DONOTROCKETCACHE')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        define('DONOTROCKETCACHE', true);
    }
    if (!defined('DONOTROCKETOPTIMIZE')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        define('DONOTROCKETOPTIMIZE', true);
    }

    // Conservative headers for dynamic content
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
    header('Pragma: no-cache', true);
    header('Expires: 0', true);

    // Specific signals for LSCache / Nginx
    header('X-LiteSpeed-Cache-Control: no-cache', true);
    header('X-Accel-Expires: 0', true);

    // Standard WP nocache headers
    if (function_exists('nocache_headers')) {
        nocache_headers();
    }
});

// 1b) Reinforce headers when serving REST responses
add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
    $route = method_exists($request, 'get_route') ? $request->get_route() : '';

    if (is_string($route) && strpos($route, '/storelinkformc/v1/') === 0) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
        header('X-LiteSpeed-Cache-Control: no-cache', true);
        header('X-Accel-Expires: 0', true);
    }

    return $served;
}, 10, 4);

// 2) On activation, if LSCache or WP Rocket is active, show admin notice with exclusion patterns
register_activation_hook(dirname(__DIR__) . '/storelinkformc.php', function () {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (function_exists('is_plugin_active') && is_plugin_active('litespeed-cache/litespeed-cache.php')) {
        update_option('storelinkformc_needs_lscache_exclusion', 1);
    }
    if (function_exists('is_plugin_active') && is_plugin_active('wp-rocket/wp-rocket.php')) {
        update_option('storelinkformc_needs_rocket_exclusion', 1);
    }
});

// 3) Admin notice showing recommended cache exclusions
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $needs_ls = (bool) get_option('storelinkformc_needs_lscache_exclusion');
    $needs_wr = (bool) get_option('storelinkformc_needs_rocket_exclusion');

    if (!$needs_ls && !$needs_wr) {
        return;
    }

    $pattern_pretty = home_url('/wp-json/storelinkformc/*');
    $pattern_rest   = home_url('/?rest_route=/storelinkformc/*');

    echo '<div class="notice notice-warning is-dismissible"><p>';
    echo '<strong>StoreLinkforMC:</strong> Exclude these URLs in your cache plugin to prevent issues with account linking and deliveries:<br>';
    echo '<code>' . esc_html($pattern_pretty) . '</code><br>';
    echo '<code>' . esc_html($pattern_rest) . '</code>';
    echo '</p></div>';

    delete_option('storelinkformc_needs_lscache_exclusion');
    delete_option('storelinkformc_needs_rocket_exclusion');
});

// 4) Optional soft purge helpers (can be called from events if needed)
if (!function_exists('storelinkformc_purge_all_caches_soft')) {
    function storelinkformc_purge_all_caches_soft(): void {
        // LiteSpeed
        if (function_exists('do_action')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            do_action('litespeed_purge_all');
        }
        // SG Optimizer
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }
        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
    }
}

if (!function_exists('storelinkformc_purge_url_soft')) {
    function storelinkformc_purge_url_soft(string $url): void {
        if (function_exists('do_action')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            do_action('litespeed_purge_url', $url);
        }
        if (function_exists('rocket_clean_files')) {
            rocket_clean_files($url);
        }
    }
}
