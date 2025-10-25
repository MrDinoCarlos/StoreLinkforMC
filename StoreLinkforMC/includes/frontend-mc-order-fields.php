<?php
if (!defined('ABSPATH')) exit;

/**
 * Añadir checkbox "This is a gift" y campo "Minecraft Username" al checkout.
 */
add_filter('woocommerce_checkout_fields', function ($fields) {
    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    $force   = function_exists('storelinkformc_force_link_enabled') ? storelinkformc_force_link_enabled() : true;

    // Campo de username siempre presente
    $fields['billing']['minecraft_username'] = [
        'type'        => 'text',
        'label'       => __('Minecraft Username', 'storelinkformc'),
        'required'    => $force ? false : true, // si NO se fuerza vincular, este campo es obligatorio
        'class'       => ['form-row-wide'],
        'priority'    => 210,
        'placeholder' => __('e.g. Notch', 'storelinkformc'),
        'custom_attributes' => [],
    ];

    if ($force) {
        // MODO CLÁSICO: mostrar checkbox de regalo
        $fields['billing']['minecraft_gift'] = [
            'type'        => 'checkbox',
            'label'       => __('This is a gift', 'storelinkformc'),
            'required'    => false,
            'class'       => ['form-row-wide'],
            'priority'    => 209,
        ];
        // Si el usuario tiene vinculado, el JS lo pondrá readonly cuando no sea regalo
    } else {
        // MODO LIBRE: NO mostrar el checkbox de regalo
        if (isset($fields['billing']['minecraft_gift'])) {
            unset($fields['billing']['minecraft_gift']);
        }
    }

    return $fields;
}, 20);


/**
 * Render inline help bajo el campo (opcional).
 */
add_action('woocommerce_after_checkout_billing_form', function () {
    echo '<p id="storelinkformc-mc-help" style="margin-top:-8px;color:#666;font-size:12px;"></p>';
});

/**
 * Validación del checkout (servidor).
 */
add_action('woocommerce_checkout_process', function () {
    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    $is_gift = !empty($_POST['minecraft_gift']);
    $mc_user = isset($_POST['minecraft_username']) ? sanitize_text_field($_POST['minecraft_username']) : '';
    $force   = function_exists('storelinkformc_force_link_enabled') ? storelinkformc_force_link_enabled() : true;

    // Política (premium / any)
    $policy = get_option('storelinkformc_username_policy', 'premium'); // 'premium' | 'any'

    if ($force) {
        // MODO CLÁSICO (como ahora)
        if ($is_gift) {
            if ($mc_user === '') {
                wc_add_notice(__('Please enter the recipient\'s Minecraft username (gift).', 'storelinkformc'), 'error');
                return;
            }
            // Verificar usuario si procede
            if (!storelinkformc_checkout_verify_username($mc_user, $policy)) {
                wc_add_notice(__('Invalid or not allowed Minecraft username for this server policy.', 'storelinkformc'), 'error');
            }
        } else {
            if ($linked) {
                // Si se intenta cambiar el vinculado, error (lo gestiona también el JS)
                if ($mc_user && $mc_user !== $linked) {
                    wc_add_notice(__('You cannot change your linked Minecraft username unless you mark this order as a gift.', 'storelinkformc'), 'error');
                }
            } else {
                // Sin vinculado y sin gift => no permitido
                if (!empty($mc_user)) {
                    wc_add_notice(__('You must link your Minecraft account or mark this order as a gift to enter a username.', 'storelinkformc'), 'error');
                }
            }
        }
    } else {
        // MODO LIBRE: username obligatorio y verificado
        if ($mc_user === '') {
            wc_add_notice(__('Please enter a Minecraft username.', 'storelinkformc'), 'error');
            return;
        }
        if (!storelinkformc_checkout_verify_username($mc_user, $policy)) {
            wc_add_notice(__('Invalid or not allowed Minecraft username for this server policy.', 'storelinkformc'), 'error');
        }
    }
});


/**
 * Guardar metadatos del pedido.
 */
add_action('woocommerce_checkout_update_order_meta', function ($order_id) {
    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    $is_gift = !empty($_POST['minecraft_gift']);
    $mc_user = isset($_POST['minecraft_username']) ? sanitize_text_field($_POST['minecraft_username']) : '';
    $force   = function_exists('storelinkformc_force_link_enabled') ? storelinkformc_force_link_enabled() : true;

    if ($force) {
        if ($is_gift) {
            update_post_meta($order_id, '_minecraft_username', $mc_user);
            update_post_meta($order_id, '_slmc_target_type', 'gift');
        } elseif ($linked) {
            update_post_meta($order_id, '_minecraft_username', $linked);
            update_post_meta($order_id, '_slmc_target_type', 'linked');
        } else {
            delete_post_meta($order_id, '_minecraft_username');
            delete_post_meta($order_id, '_slmc_target_type');
        }
    } else {
        // MODO LIBRE: siempre manual_username
        update_post_meta($order_id, '_minecraft_username', $mc_user);
        update_post_meta($order_id, '_slmc_target_type', 'manual_username');
    }
}, 10, 1);



if (!function_exists('storelinkformc_checkout_verify_username')) {
    function storelinkformc_checkout_verify_username(string $username, string $policy): bool {
        // Validación básica de formato
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $username)) {
            return false;
        }

        if ($policy === 'any') {
            return true;
        }

        // policy === 'premium' -> verificar que exista en Mojang/PlayerDB
        $url = 'https://playerdb.co/api/player/minecraft/' . rawurlencode($username);
        $resp = wp_remote_get($url, ['timeout' => 6]);
        if (is_wp_error($resp)) return false;

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200 || !is_array($body)) return false;
        // PlayerDB responde success:true cuando existe
        return !empty($body['success']);
    }
}

