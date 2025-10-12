<?php
if (!defined('ABSPATH')) exit;

/**
 * Añadir checkbox "This is a gift" y campo "Minecraft Username" al checkout.
 */
add_filter('woocommerce_checkout_fields', function ($fields) {
    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';

    // Checkbox: regalo
    $fields['billing']['minecraft_gift'] = [
        'type'        => 'checkbox',
        'label'       => __('This is a gift', 'storelinkformc'),
        'required'    => false,
        'class'       => ['form-row-wide'],
        'priority'    => 124,
    ];

    // Campo: username
    // Estado inicial:
    // - Si hay cuenta vinculada -> pre-rellenado y readonly.
    // - Si NO hay cuenta vinculada -> deshabilitado hasta que marquen regalo.
    $custom_attributes = [];
    if ($linked) {
        $custom_attributes['readonly'] = 'readonly';
    } else {
        $custom_attributes['disabled'] = 'disabled';
        $custom_attributes['readonly'] = 'readonly';
    }

    $fields['billing']['minecraft_username'] = [
        'type'              => 'text',
        'label'             => __('Minecraft Username', 'storelinkformc'),
        'required'          => false, // Se valida en servidor si es regalo
        'class'             => ['form-row-wide'],
        'priority'          => 125,
        'default'           => $linked ?: '',
        'custom_attributes' => $custom_attributes,
        'placeholder'       => __('Recipient username (if gift)', 'storelinkformc'),
    ];

    return $fields;
}, 10);

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
    $user_id   = get_current_user_id();
    $linked    = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    $is_gift   = isset($_POST['minecraft_gift']) && $_POST['minecraft_gift'];
    $mc_user   = isset($_POST['minecraft_username']) ? sanitize_text_field($_POST['minecraft_username']) : '';

    // Si es regalo, el username es obligatorio
    if ($is_gift && $mc_user === '') {
        wc_add_notice(__('Please enter the recipient\'s Minecraft username (gift).', 'storelinkformc'), 'error');
    }

    // Si NO es regalo:
    if (!$is_gift) {
        if ($linked) {
            // No se permite cambiarlo si hay cuenta vinculada
            if ($mc_user && $mc_user !== $linked) {
                wc_add_notice(__('You cannot change your linked Minecraft username unless you mark this order as a gift.', 'storelinkformc'), 'error');
            }
        } else {
            // Sin cuenta vinculada y sin regalo: el campo debe permanecer vacío
            if (!empty($mc_user)) {
                wc_add_notice(__('You must link your Minecraft account or mark this order as a gift to enter a username.', 'storelinkformc'), 'error');
            }
        }
    }
});

/**
 * Guardar metadatos del pedido.
 */
add_action('woocommerce_checkout_update_order_meta', function ($order_id) {
    $is_gift = isset($_POST['minecraft_gift']) && $_POST['minecraft_gift'] ? 'yes' : 'no';
    update_post_meta($order_id, '_minecraft_gift', $is_gift);

    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    $mc_user = isset($_POST['minecraft_username']) ? sanitize_text_field($_POST['minecraft_username']) : '';

    // Guardado:
    // - Si es regalo => guardar el username del destinatario.
    // - Si NO es regalo y hay cuenta vinculada => guarda el vinculado (coherente con readonly en UI).
    if ($is_gift === 'yes') {
        update_post_meta($order_id, '_minecraft_username', $mc_user);
    } elseif ($linked) {
        update_post_meta($order_id, '_minecraft_username', $linked);
    } else {
        delete_post_meta($order_id, '_minecraft_username');
    }
});
