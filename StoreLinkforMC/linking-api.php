<?php
if (!defined('ABSPATH')) exit;

// 📡 Endpoints REST API
add_action('rest_api_init', function () {
    register_rest_route('storelinkformc/v1', '/request-link', [
        'methods' => 'POST',
        'callback' => function ($request) {
            $email  = sanitize_email($request->get_param('email'));
            $player = sanitize_user($request->get_param('player'));

            // Rate limiting per IP
            $real_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ip_key  = 'storelinkformc_rate_' . md5($real_ip);
            if (get_transient($ip_key)) {
                return new WP_REST_Response(['error' => 'Please wait before requesting another code.'], 429);
            }
            set_transient($ip_key, true, 60); // 1 request/minute

            // Token verification
            $token = sanitize_text_field($request->get_param('token'));
            $stored_token = get_option('storelinkformc_api_token');
            if ($token !== $stored_token) {
                return new WP_REST_Response(['error' => 'Invalid token'], 403);
            }

            return storelinkformc_request_link($request);
        },
        'permission_callback' => '__return_true' // Handled inside callback
    ]);

    register_rest_route('storelinkformc/v1', '/verify-link', [
        'methods' => 'POST',
        'callback' => function ($request) {
            $token = sanitize_text_field($request->get_param('token'));
            $stored_token = get_option('storelinkformc_api_token');
            if ($token !== $stored_token) {
                return new WP_REST_Response(['error' => 'Invalid token'], 403);
            }
            return storelinkformc_verify_link($request);
        },
        'permission_callback' => '__return_true'
    ]);
});


// 🔐 Enviar código de verificación
function storelinkformc_request_link($request) {
    $email  = sanitize_email($request->get_param('email'));
    $player = sanitize_user($request->get_param('player'));

    // Enforce policy (optional here – pre-check before emailing)
    $policy = get_option('storelinkformc_username_policy', 'premium');
    if ($policy === 'premium') {
        $check = storelinkformc_mojang_check_username($player);
        if (!$check['ok']) {
            $msg = ($check['reason'] === 'ERR')
                ? 'Mojang verification is temporarily unavailable. Please try again.'
                : 'This site only accepts Mojang (premium) usernames.';
            return new WP_REST_Response(['error' => $msg], 400);
        }
    }

    if (!is_email($email) || empty($player)) {
        return new WP_REST_Response(['error' => 'Invalid email or player'], 400);
    }

    // === Require that the email belongs to an existing WP user ===
    $user = get_user_by('email', $email);
    if (!$user) {
        error_log('[SLMC] request-link: user not found for email='.$email.' player='.$player.' ip='.$_SERVER['REMOTE_ADDR']);
        return new WP_REST_Response(['error' => 'User not found. Please register on the site before linking.'], 404);
    }

    $existing = get_user_meta($user->ID, 'minecraft_player', true);
    if (!empty($existing) && $existing !== $player) {
        return new WP_REST_Response(['error' => 'This email is already linked to another player.'], 409);
    }

    // Prevenir que se use un nombre ya vinculado
    $users = get_users(['meta_key' => 'minecraft_player', 'meta_value' => $player]);
    if (!empty($users)) {
        return new WP_REST_Response(['error' => 'This player name is already linked.'], 409);
    }

    $code = wp_rand(100000, 999999);
    $key  = 'storelinkformc_verify_code_' . md5($email);

    set_transient($key, [
        'code'    => (string)$code,
        'player'  => $player,
        'user_id' => $user->ID,
    ], HOUR_IN_SECONDS);

    $link_url = slmc_build_link_url($email, (string)$code);
    slmc_send_linking_email($email, (string)$code, $link_url, $player);

    return new WP_REST_Response(['success' => true, 'message' => 'Verification code sent.'], 200);
}

// ✅ Verificar código y vincular cuenta
function storelinkformc_verify_link($request) {
    $email = sanitize_email($request->get_param('email'));
    $code  = sanitize_text_field($request->get_param('code'));
    $key   = 'storelinkformc_verify_code_' . md5($email);

    $data = get_transient($key);
    if (!$data || empty($data['code']) || (string)$data['code'] !== $code) {
        return new WP_REST_Response(['error' => 'Invalid or expired code.'], 400);
    }

    // Enforce policy (authoritative)
    $policy = get_option('storelinkformc_username_policy', 'premium');
    if ($policy === 'premium') {
        $check = storelinkformc_mojang_check_username($data['player']);
        if (!$check['ok']) {
            $msg = ($check['reason'] === 'ERR')
                ? 'Mojang verification is temporarily unavailable. Please try again.'
                : 'This site only accepts Mojang (premium) usernames.';
            return new WP_REST_Response(['error' => $msg], 400);
        }
    }

    // Now it’s safe to write
    update_user_meta($data['user_id'], 'minecraft_player', $data['player']);
    delete_transient($key);

    $role = get_option('storelinkformc_default_linked_role');
    if ($role && !user_can($data['user_id'], $role)) {
        $user = new WP_User($data['user_id']);
        $user->add_role($role);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Your account has been linked and role assigned!'], 200);
}


// 🔓 AJAX: Desvincular cuenta desde frontend
add_action('wp_ajax_storelinkformc_unlink_account', 'storelinkformc_handle_unlink_ajax');
function storelinkformc_handle_unlink_ajax() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['error' => 'Unauthorized'], 403);
    }

    check_ajax_referer('storelinkformc_unlink_action', 'security');

    $user_id = get_current_user_id();
    storelinkformc_unlink_account($user_id);

    wp_send_json_success(['message' => 'Unlinked']);
}


// 🧹 Función para eliminar vínculo y rol
function storelinkformc_unlink_account($user_id) {
    delete_user_meta($user_id, 'minecraft_player');

    $linked_role = get_option('storelinkformc_default_linked_role');
    if ($linked_role && user_can($user_id, $linked_role)) {
        $user = new WP_User($user_id);
        $user->remove_role($linked_role);
    }
}

add_action('rest_api_init', function () {
    register_rest_route('storelinkformc/v1', '/pending', [
        'methods' => 'GET',
        'callback' => 'storelinkformc_api_get_pending',
        'permission_callback' => function ($request) {
            $token = sanitize_text_field($request->get_param('token'));
            $stored_token = get_option('storelinkformc_api_token');
            return $token === $stored_token;
        }

    ]);
});

function storelinkformc_api_get_pending($request) {
    $token = sanitize_text_field($request->get_param('token'));
    $player = sanitize_text_field($request->get_param('player'));

    $stored_token = get_option('storelinkformc_api_token');
    if ($token !== $stored_token) {
        return new WP_REST_Response(['error' => 'Invalid token'], 403);
    }

    if (empty($player)) {
        return new WP_REST_Response(['error' => 'Missing player parameter'], 400);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'pending_deliveries';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, item, amount FROM $table WHERE player = %s AND delivered = 0",
        $player
    ));

    return ['success' => true, 'deliveries' => $rows];
}
add_action('rest_api_init', function () {
    register_rest_route('storelinkformc/v1', '/mark-delivered', [
        'methods' => 'POST',
        'callback' => 'storelinkformc_api_mark_delivered',
        'permission_callback' => function ($request) {
            $token = sanitize_text_field($request->get_param('token'));
            $stored_token = get_option('storelinkformc_api_token');
            return $token === $stored_token;
        }

    ]);
});

function storelinkformc_api_mark_delivered($request) {
    $token = $request->get_param('token');
    $id = $request->get_param('id');

    if (empty($token) || empty($id)) {
        return new WP_REST_Response(['error' => 'Missing delivery ID or token'], 400);
    }

    $token = sanitize_text_field($token);
    $id = intval($id);
    $stored_token = get_option('storelinkformc_api_token');

    if (!$stored_token || $token !== $stored_token) {
        return new WP_REST_Response(['error' => 'Invalid token'], 403);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'pending_deliveries';

    $updated = $wpdb->update(
        $table,
        ['delivered' => 1],
        ['id' => $id],
        ['%d'],
        ['%d']
    );

    if ($updated === false) {
        return new WP_REST_Response(['error' => 'Database update failed'], 500);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Marked as delivered'], 200);
}

/**
 * Check if a Minecraft username exists on Mojang (premium).
 * Returns ['ok'=>true,'uuid'=>string] or ['ok'=>false,'reason'=>'NF|ERR'].
 */
function storelinkformc_mojang_check_username($nick) {
    $nick = trim($nick);
    if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $nick)) {
        return ['ok' => false, 'reason' => 'NF']; // invalid format → treat as not found
    }

    $cache_key = 'mojang_profile_' . strtolower($nick);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        if ($cached === 'NF') return ['ok'=>false,'reason'=>'NF'];
        if (is_array($cached) && !empty($cached['uuid'])) return ['ok'=>true,'uuid'=>$cached['uuid']];
    }

    $resp = wp_remote_get('https://api.mojang.com/users/profiles/minecraft/' . rawurlencode($nick), [
        'timeout' => 8,
        'headers' => ['Accept' => 'application/json'],
    ]);
    $code = wp_remote_retrieve_response_code($resp);

    if ($code === 200) {
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $uuid = isset($body['id']) ? preg_replace('/[^a-f0-9]/i', '', $body['id']) : '';
        if ($uuid) {
            set_transient($cache_key, ['uuid'=>$uuid], DAY_IN_SECONDS);
            return ['ok'=>true,'uuid'=>$uuid];
        }
        return ['ok'=>false,'reason'=>'NF'];
    } elseif ($code === 204 || $code === 404) {
        set_transient($cache_key, 'NF', HOUR_IN_SECONDS);
        return ['ok'=>false,'reason'=>'NF'];
    }

    return ['ok'=>false,'reason'=>'ERR']; // network / rate-limit / service down
}

// ========================
// Utilidades de email/URL
// ========================
if (!function_exists('slmc_build_link_url')) {
    /**
     * Construye URL opcional de verificación por clic:
     * https://tusitio.com/?slmc-verify=CODE&slmc-email=EMAIL
     */
    function slmc_build_link_url(string $email, string $code): string {
        return add_query_arg(
            ['slmc-verify' => rawurlencode($code), 'slmc-email' => rawurlencode($email)],
            home_url('/')
        );
    }
}

if (!function_exists('slmc_mail_content_type_html')) {
    function slmc_mail_content_type_html(){ return 'text/html'; }
}

if (!function_exists('slmc_send_linking_email')) {
    /**
     * Envía el email usando wp_mail() (lo que tenga tu WP/hosting o plugin SMTP).
     * Usa las plantillas guardadas en opciones.
     */
    function slmc_send_linking_email(string $user_email, string $verify_code, string $link_url, string $player = ''): bool {
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $domain    = parse_url(home_url(), PHP_URL_HOST);
        if (!$domain) { $domain = $_SERVER['HTTP_HOST'] ?? 'localhost'; }

        $subject_tpl = get_option('slmc_tpl_link_subject', 'Link your Minecraft account on {site_name}');
        $body_tpl    = get_option('slmc_tpl_link_html',
            '<p>Hello,</p>'.
            '<p>Use this code: <strong>{verify_code}</strong> to link your account.</p>'.
            '<p><a href="{link_url}">{link_url}</a></p>'.
            '<p>— {site_name}</p>'
        );

        $repl = [
            '{site_name}'   => $site_name,
            '{user_email}'  => $user_email,
            '{verify_code}' => $verify_code,
            '{link_url}'    => $link_url,
            '{player}'      => $player,           // <-- nuevo placeholder
        ];

        $subject = strtr($subject_tpl, $repl);
        $body    = strtr($body_tpl, $repl);

        $from_email = 'no-reply@' . $domain;
        $headers   = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $from_email . '>',
        ];

        $ok = wp_mail($user_email, $subject, $body, $headers);
        error_log('[SLMC] wp_mail to '.$user_email.' subject="'. $subject .'" result='.var_export($ok,true));
        return (bool) $ok;
    }

}
