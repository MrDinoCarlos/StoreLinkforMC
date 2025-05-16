<?php

// 📡 Endpoints REST API
add_action('rest_api_init', function () {
    register_rest_route('minecraftstorelink/v1', '/request-link', [
        'methods' => 'POST',
        'callback' => 'minecraftstorelink_request_link',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('minecraftstorelink/v1', '/verify-link', [
        'methods' => 'POST',
        'callback' => 'minecraftstorelink_verify_link',
        'permission_callback' => '__return_true',
    ]);
});

// 🔐 Enviar código de verificación
function minecraftstorelink_request_link($request) {
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

    $code = wp_rand(100000, 999999);
    $key = 'minecraftstorelink_verify_code_' . md5($email);

    set_transient($key, [
        'code' => (string)$code,
        'player' => $player,
        'user_id' => $user->ID,
    ], 60 * 60); // 60 minutos

    wp_mail($email, "Your Minecraft Link Code", "Use this code to link your Minecraft account: $code");

    return ['success' => true, 'message' => 'Verification code sent.'];
}

// ✅ Verificar código y vincular cuenta
function minecraftstorelink_verify_link($request) {
    $email = sanitize_email($request['email']);
    $code = sanitize_text_field($request['code']);
    $key = 'minecraftstorelink_verify_code_' . md5($email);

    $data = get_transient($key);

    if (!$data || !isset($data['code']) || (string)$data['code'] !== (string)$code) {
        return new WP_REST_Response(['error' => 'Invalid or expired code.'], 400);
    }

    update_user_meta($data['user_id'], 'minecraft_player', $data['player']);
    delete_transient($key);

    // Asignar rol si está configurado
    $role_to_assign = get_option('minecraftstorelink_default_linked_role');
    if ($role_to_assign && !user_can($data['user_id'], $role_to_assign)) {
        $user = new WP_User($data['user_id']);
        $user->add_role($role_to_assign);
    }

    return ['success' => true, 'message' => 'Your account has been linked and role assigned!'];
}

// 🔓 Desvincular cuenta manualmente (AJAX desde frontend)
add_action('wp_ajax_minecraftstorelink_unlink_account', 'minecraftstorelink_handle_unlink_ajax');
function minecraftstorelink_handle_unlink_ajax() {
    if (!is_user_logged_in()) {
        wp_send_json_error('Unauthorized');
    }

    $user_id = get_current_user_id();
    minecraftstorelink_unlink_account($user_id);

    wp_send_json_success('Unlinked');
}

// 🧹 Función para eliminar vinculación y rol
function minecraftstorelink_unlink_account($user_id) {
    delete_user_meta($user_id, 'minecraft_player');

    $linked_role = get_option('minecraftstorelink_default_linked_role');
    if ($linked_role && user_can($user_id, $linked_role)) {
        $user = new WP_User($user_id);
        $user->remove_role($linked_role);
    }
}
