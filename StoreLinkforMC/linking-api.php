<?php
if (!defined('ABSPATH')) {
    exit;
}

// 📡 Endpoints REST API
add_action('rest_api_init', function () {
    register_rest_route('storelinkformc/v1', '/request-link', [
        'methods'  => 'POST',
        'callback' => 'storelinkformc_request_link',
        'permission_callback' => 'storelinkformc_rest_authorize',
    ]);

    register_rest_route('storelinkformc/v1', '/verify-link', [
        'methods'  => 'POST',
        'callback' => 'storelinkformc_verify_link',
        'permission_callback' => 'storelinkformc_rest_authorize',
    ]);

    register_rest_route('storelinkformc/v1', '/unlink', [
        'methods'  => 'POST',
        'callback' => 'storelinkformc_api_unlink_player',
        'permission_callback' => 'storelinkformc_rest_authorize',
    ]);
});


// 🔐 Enviar código de verificación
function storelinkformc_request_link($request) {
    $email  = sanitize_email($request->get_param('email'));
    $player = sanitize_user($request->get_param('player'));

    if (!is_email($email) || !preg_match('/^[A-Za-z0-9_]{3,16}$/', $player)) {
        return new WP_REST_Response(['error' => 'Invalid email or player'], 400);
    }

    $rate_key = 'storelinkformc_link_rate_' . hash('sha256', storelinkformc_request_ip() . '|' . strtolower($email));
    if (get_transient($rate_key)) {
        return new WP_REST_Response(['error' => 'Please wait before requesting another code.'], 429);
    }
    set_transient($rate_key, 1, MINUTE_IN_SECONDS);

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

    // === Require that the email belongs to an existing WP user ===
    $user = get_user_by('email', $email);
    if (!$user) {
        // No logging here to satisfy WP.org sniff; Minecraft will show the error message.
        return new WP_REST_Response(['error' => 'User not found. Please register on the site before linking.'], 404);
    }

    $existing = get_user_meta($user->ID, 'minecraft_player', true);
    if (!empty($existing) && $existing !== $player) {
        return new WP_REST_Response(['error' => 'This email is already linked to another player.'], 409);
    }

    // Prevenir que se use un nombre ya vinculado
    $users = get_users([
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_key'   => 'minecraft_player',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        'meta_value' => $player,
    ]);
    if (!empty($users)) {
        return new WP_REST_Response(['error' => 'This player name is already linked.'], 409);
    }

    $code = wp_rand(100000, 999999);
    $key  = 'storelinkformc_verify_code_' . md5($email);

    set_transient(
        $key,
        [
            'code_hash' => wp_hash_password((string) $code),
            'player'  => $player,
            'user_id' => $user->ID,
        ],
        HOUR_IN_SECONDS
    );

    $link_url = slmc_build_link_url($email, (string) $code);
    $ok       = slmc_send_linking_email($email, (string) $code, $link_url, $player);

    if (!$ok) {
        // Devuelve error real al cliente (Minecraft lo mostrará en el chat)
        return new WP_REST_Response(['error' => 'Email could not be sent. Check mail/SMTP configuration.'], 500);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Verification code sent.'], 200);
}

// ✅ Verificar código y vincular cuenta
function storelinkformc_verify_link($request) {
    $email = sanitize_email($request->get_param('email'));
    $code  = sanitize_text_field($request->get_param('code'));
    $key   = 'storelinkformc_verify_code_' . md5($email);
    $attempt_key = 'storelinkformc_verify_attempt_' . hash('sha256', strtolower($email) . '|' . storelinkformc_request_ip());
    $attempts = (int) get_transient($attempt_key);
    if ($attempts >= 5) {
        return new WP_REST_Response(['error' => 'Too many attempts. Please request a new code later.'], 429);
    }

    $data = get_transient($key);
    if (!$data || empty($data['code_hash']) || !wp_check_password($code, $data['code_hash'])) {
        set_transient($attempt_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
        return new WP_REST_Response(['error' => 'Invalid or expired code.'], 400);
    }

    $already_linked = get_users([
        'meta_key'   => 'minecraft_player', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_value' => $data['player'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        'exclude'    => [(int) $data['user_id']],
        'number'     => 1,
        'fields'     => 'ids',
    ]);
    if ($already_linked) {
        delete_transient($key);
        return new WP_REST_Response(['error' => 'This player name is already linked.'], 409);
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
    delete_transient($attempt_key);

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

/**
 * Unlink the authenticated Minecraft player from its WordPress account.
 */
function storelinkformc_api_unlink_player($request) {
    $player = sanitize_user((string) $request->get_param('player'));
    if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $player)) {
        return new WP_REST_Response(['error' => 'Invalid player parameter.'], 400);
    }

    $users = get_users([
        'meta_key'   => 'minecraft_player', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_value' => $player, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        'number'     => 1,
        'fields'     => 'ids',
    ]);
    if (!$users) {
        return new WP_REST_Response(['error' => 'This Minecraft account is not linked.'], 404);
    }

    storelinkformc_unlink_account((int) $users[0]);

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Minecraft account unlinked successfully.',
        'player'  => $player,
    ], 200);
}

add_action('rest_api_init', function () {
    register_rest_route('storelinkformc/v1', '/pending', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'storelinkformc_api_get_pending',
        'permission_callback' => 'storelinkformc_rest_authorize',
    ]);
    register_rest_route('storelinkformc/v1', '/pending-batch', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'storelinkformc_api_get_pending_batch',
        'permission_callback' => 'storelinkformc_rest_authorize',
    ]);
    register_rest_route('storelinkformc/v1', '/mark-delivered', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'storelinkformc_api_mark_delivered',
        'permission_callback' => 'storelinkformc_rest_authorize',
    ]);
});

function storelinkformc_rest_authorize($request) {
    $stored   = (string) get_option('storelinkformc_api_token', '');
    $provided = (string) $request->get_header('x-storelink-token');
    $authorization = (string) $request->get_header('authorization');

    if (!$provided && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        $provided = trim($matches[1]);
    }
    if (!$provided) {
        $provided = (string) $request->get_param('token'); // v1 client compatibility.
    }

    if (!$stored || !$provided || !hash_equals($stored, $provided)) {
        return new WP_Error(
            'storelinkformc_invalid_token',
            __('Invalid API token.', 'storelinkformc'),
            ['status' => 401]
        );
    }
    return true;
}

function storelinkformc_api_get_pending($request) {
    $player = sanitize_text_field((string) $request->get_param('player'));
    if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $player)) {
        return new WP_REST_Response(['error' => 'Invalid player parameter'], 400);
    }
    $grouped = storelinkformc_get_pending_for_players([$player], 50);
    return new WP_REST_Response([
        'success'    => true,
        'deliveries' => $grouped[$player] ?? [],
    ], 200);
}

function storelinkformc_api_get_pending_batch($request) {
    $players = $request->get_param('players');
    if (!is_array($players)) {
        return new WP_REST_Response(['error' => 'Players must be an array'], 400);
    }

    $clean = [];
    foreach (array_slice($players, 0, 100) as $player) {
        $player = sanitize_text_field((string) $player);
        if (preg_match('/^[A-Za-z0-9_]{3,16}$/', $player)) {
            $clean[$player] = $player;
        }
    }
    if (!$clean) {
        return new WP_REST_Response(['success' => true, 'deliveries' => []], 200);
    }

    $limit = max(1, min(200, absint($request->get_param('limit') ?: 50)));
    return new WP_REST_Response([
        'success'    => true,
        'deliveries' => storelinkformc_get_pending_for_players(array_values($clean), $limit),
    ], 200);
}

function storelinkformc_get_pending_for_players(array $players, int $per_player_limit): array {
    global $wpdb;
    $table = esc_sql($wpdb->prefix . 'pending_deliveries');
    $result = array_fill_keys($players, []);
    $player_keys = [];
    foreach ($players as $player) {
        $player_keys[strtolower($player)] = $player;
    }

    $placeholders = implode(', ', array_fill(0, count($players), '%s'));
    $maximum_rows = min(2000, count($players) * $per_player_limit);
    $arguments = array_merge($players, [current_time('mysql'), $maximum_rows]);
    $sql = "SELECT id, order_id, player, item, product_id, variation_id, amount
            FROM {$table}
            WHERE player IN ({$placeholders})
              AND delivered = 0
              AND (expired IS NULL OR expired = 0)
              AND (expires_at IS NULL OR expires_at >= %s)
            ORDER BY id ASC
            LIMIT %d";

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$arguments));
    foreach ((array) $rows as $row) {
        $requested = $player_keys[strtolower((string) $row->player)] ?? null;
        if (null === $requested || count($result[$requested]) >= $per_player_limit) {
            continue;
        }
        unset($row->player);
        $result[$requested][] = $row;
    }
    return $result;
}

function storelinkformc_api_mark_delivered($request) {
    $ids = $request->get_param('ids');
    if (!is_array($ids)) {
        $ids = [$request->get_param('id')]; // v1 client compatibility.
    }
    $ids = array_values(array_unique(array_filter(array_map('absint', array_slice($ids, 0, 200)))));
    if (!$ids) {
        return new WP_REST_Response(['error' => 'Missing delivery IDs'], 400);
    }

    global $wpdb;
    $table = esc_sql($wpdb->prefix . 'pending_deliveries');
    $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $order_ids = array_map('absint', $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT order_id FROM {$table} WHERE id IN ({$placeholders})",
        ...$ids
    )));
    $sql = "UPDATE {$table}
            SET delivered = 1, expired = 0
            WHERE delivered = 0 AND id IN ({$placeholders})";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $updated = $wpdb->query($wpdb->prepare($sql, ...$ids));
    if (false === $updated) {
        return new WP_REST_Response(['error' => 'Database update failed'], 500);
    }
    if ($updated > 0 && $order_ids) {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('storelinkformc_complete_delivered_orders', [$order_ids], 'storelinkformc');
        } elseif (!wp_next_scheduled('storelinkformc_complete_delivered_orders', [$order_ids])) {
            wp_schedule_single_event(time() + 10, 'storelinkformc_complete_delivered_orders', [$order_ids]);
        }
    }
    return new WP_REST_Response([
        'success' => true,
        'updated' => (int) $updated,
    ], 200);
}

add_action('storelinkformc_complete_delivered_orders', 'storelinkformc_complete_delivered_orders');
function storelinkformc_complete_delivered_orders($order_ids): void {
    if (!function_exists('wc_get_order') || !is_array($order_ids)) {
        return;
    }
    global $wpdb;
    $table = esc_sql($wpdb->prefix . 'pending_deliveries');
    foreach (array_slice(array_unique(array_map('absint', $order_ids)), 0, 200) as $order_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND delivered = 0 AND (expired IS NULL OR expired = 0)",
            $order_id
        ));
        if ($remaining > 0) {
            continue;
        }
        $order = wc_get_order($order_id);
        if ($order && in_array($order->get_status(), ['processing', 'on-hold', 'pending'], true)) {
            $order->update_status('completed', __('All Minecraft deliveries were confirmed.', 'storelinkformc'));
        }
    }
}

function storelinkformc_request_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $source) {
        if (!empty($_SERVER[$source])) {
            $candidate = trim((string) wp_unslash($_SERVER[$source]));
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }
    return '0.0.0.0';
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
    $cached    = get_transient($cache_key);
    if ($cached !== false) {
        if ($cached === 'NF') {
            return ['ok' => false, 'reason' => 'NF'];
        }
        if (is_array($cached) && !empty($cached['uuid'])) {
            return ['ok' => true, 'uuid' => $cached['uuid']];
        }
    }

    $resp = wp_remote_get(
        'https://api.mojang.com/users/profiles/minecraft/' . rawurlencode($nick),
        [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/json'],
        ]
    );
    if (is_wp_error($resp)) {
        return ['ok' => false, 'reason' => 'ERR'];
    }

    $code = wp_remote_retrieve_response_code($resp);

    if ($code === 200) {
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $uuid = isset($body['id']) ? preg_replace('/[^a-f0-9]/i', '', $body['id']) : '';
        if ($uuid) {
            set_transient($cache_key, ['uuid' => $uuid], DAY_IN_SECONDS);
            return ['ok' => true, 'uuid' => $uuid];
        }
        return ['ok' => false, 'reason' => 'NF'];
    } elseif ($code === 204 || $code === 404) {
        set_transient($cache_key, 'NF', HOUR_IN_SECONDS);
        return ['ok' => false, 'reason' => 'NF'];
    }

    return ['ok' => false, 'reason' => 'ERR']; // network / rate-limit / service down
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
            [
                'slmc-verify' => rawurlencode($code),
                'slmc-email'  => rawurlencode($email),
            ],
            home_url('/')
        );
    }
}

if (!function_exists('slmc_mail_content_type_html')) {
    function slmc_mail_content_type_html() {
        return 'text/html';
    }
}

if (!function_exists('slmc_send_linking_email')) {
    /**
     * Envía el email usando wp_mail() (lo que tenga tu WP/hosting o plugin SMTP).
     * Usa las plantillas guardadas en opciones.
     * No fuerces el "From:" aquí: deja que el plugin SMTP lo gestione.
     */
    function slmc_send_linking_email(string $user_email, string $verify_code, string $link_url, string $player = ''): bool {
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $subject_tpl = get_option('slmc_tpl_link_subject', 'Link your Minecraft account on {site_name}');
        $body_tpl    = get_option(
            'slmc_tpl_link_html',
            '<p>Hello {player},</p>' .
            '<p>Use this code: <strong>{verify_code}</strong> to link your account.</p>' .
            '<p><a href="{link_url}">{link_url}</a></p>' .
            '<p>— {site_name}</p>'
        );

        $repl = [
            '{site_name}'   => $site_name,
            '{user_email}'  => $user_email,
            '{verify_code}' => $verify_code,
            '{link_url}'    => $link_url,
            '{player}'      => $player,
        ];

        $subject = strtr($subject_tpl, $repl);
        $body    = strtr($body_tpl, $repl);

        // HTML via filtro oficial (no headers manuales)
        add_filter('wp_mail_content_type', 'slmc_mail_content_type_html');
        $ok = wp_mail($user_email, $subject, $body);
        remove_filter('wp_mail_content_type', 'slmc_mail_content_type_html');

        return (bool) $ok;
    }
}

/**
 * ✅ Show Minecraft head inside checkout field
 * This uses the same user meta we already use: "minecraft_player"
 */
add_action('wp_head', function () {
    // Solo en frontend, checkout y usuario logueado
    if (!function_exists('is_checkout') || !is_checkout() || !is_user_logged_in()) {
        return;
    }

    // Sacamos el nick vinculado
    $user_id = get_current_user_id();
    $player  = sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true));

    // Avatar según si tiene o no
    $avatar_url = $player
        ? 'https://mc-heads.net/avatar/' . rawurlencode($player) . '/40'
        : 'https://mc-heads.net/avatar/MHF_Question/40';

    ?>
    <style>
    /* IMPORTANTE: este id debe ser el del campo de Minecraft en tu checkout */
    #storelinkformc_minecraft_username {
        background-image: url('<?php echo esc_url($avatar_url); ?>');
        background-repeat: no-repeat;
        background-position: 10px center;
        background-size: 34px 34px;
        padding-left: 54px !important; /* sitio para la cabeza */
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // mismo id que en el CSS de arriba
        var input = document.getElementById('storelinkformc_minecraft_username');
        if (input) {
            input.title = <?php echo $player ? json_encode($player) : json_encode('No linked player'); ?>;
        }
    });
    </script>
    <?php
});
