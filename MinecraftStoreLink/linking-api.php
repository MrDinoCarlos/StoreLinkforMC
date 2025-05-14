<?php
add_action('rest_api_init', function () {
    register_rest_route('minecraftstorelink/v1', '/request-link', [
        'methods' => 'POST',
        'callback' => 'msl_request_link',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('minecraftstorelink/v1', '/verify-link', [
        'methods' => 'POST',
        'callback' => 'msl_verify_link',
        'permission_callback' => '__return_true',
    ]);
});

function msl_request_link($request) {
    global $wpdb;

    $email = sanitize_email($request['email']);
    $player = sanitize_user($request['player']);

    if (!is_email($email) || empty($player)) {
        return new WP_REST_Response(['error' => 'Invalid email or player'], 400);
    }

    $user = get_user_by('email', $email);
    if (!$user) {
        return new WP_REST_Response(['error' => 'User not found'], 404);
    }

    $existing = get_user_meta($user->ID, 'minecraft_player', true);
    if (!empty($existing) && $existing !== $player) {
        return new WP_REST_Response(['error' => 'This email is already linked to another player.'], 409);
    }

    // Prevent player reuse
    $users = get_users(['meta_key' => 'minecraft_player', 'meta_value' => $player]);
    if (!empty($users)) {
        return new WP_REST_Response(['error' => 'This player name is already linked.'], 409);
    }

    // Generate code and store
    $code = wp_rand(100000, 999999);
    $key = 'msl_verify_code_' . md5($email);

    set_transient($key, [
        'code' => (string)$code,
        'player' => $player,
        'user_id' => $user->ID,
    ], 60 * MINUTE_IN_SECONDS); // 🔁 60 mins for testing

    // Optional logging
    error_log("[WooStoreLink] Code for $email is $code"); // For debug

    wp_mail($email, "Your Minecraft Link Code", "Use this code to link your Minecraft account: $code");

    return ['success' => true, 'message' => 'Verification code sent.'];
}

function msl_verify_link($request) {
    $email = sanitize_email($request['email']);
    $code = sanitize_text_field($request['code']);
    $key = 'msl_verify_code_' . md5($email);

    $data = get_transient($key);

    if (!$data || !isset($data['code']) || (string)$data['code'] !== (string)$code) {
        return new WP_REST_Response(['error' => 'Invalid or expired code.'], 400);
    }

    update_user_meta($data['user_id'], 'minecraft_player', $data['player']);
    delete_transient($key);

    return ['success' => true, 'message' => 'Your account has been linked!'];
}
