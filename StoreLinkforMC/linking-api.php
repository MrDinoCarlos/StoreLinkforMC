<?php
if (!defined('ABSPATH')) exit;

// 📡 Endpoints REST API
add_action('rest_api_init', function () {
    register_rest_route('storelinkformc/v1', '/request-link', [
        'methods' => 'POST',
        'callback' => 'storelinkformc_request_link',
        'permission_callback' => '__return_true'


    ]);

    register_rest_route('storelinkformc/v1', '/verify-link', [
        'methods' => 'POST',
        'callback' => 'storelinkformc_verify_link',
        'permission_callback' => '__return_true'


    ]);
});

// 🔐 Enviar código de verificación
function storelinkformc_request_link($request) {
    $email  = sanitize_email($request->get_param('email'));
    $player = sanitize_user($request->get_param('player'));

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

    wp_mail($email, "Your Minecraft Link Code", "Use this code to link your Minecraft account: $code");

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

    update_user_meta($data['user_id'], 'minecraft_player', $data['player']);
    delete_transient($key);

    // Asignar rol automáticamente
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
        'permission_callback' => function () {
            return isset($_GET['token']) && sanitize_text_field($_GET['token']) === get_option('storelinkformc_api_token');
        },
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
        'permission_callback' => function () {
            return isset($_POST['token']) && sanitize_text_field($_POST['token']) === get_option('storelinkformc_api_token');
        },
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
