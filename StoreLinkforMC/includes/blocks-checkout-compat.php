<?php
if (!defined('ABSPATH')) {
    exit;
}

add_filter('the_content', 'storelinkformc_use_classic_checkout_for_synced_block_carts', 20);
function storelinkformc_use_classic_checkout_for_synced_block_carts($content) {
    if (is_admin() || !function_exists('is_checkout') || !is_checkout()) {
        return $content;
    }

    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
        return $content;
    }

    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return $content;
    }

    $has_checkout_block = false !== strpos($content, 'wp:woocommerce/checkout')
        || (function_exists('has_block') && has_block('woocommerce/checkout'));

    if (!$has_checkout_block) {
        return $content;
    }

    return do_shortcode('[woocommerce_checkout]');
}

function storelinkformc_checkout_page_has_checkout_block(): bool {
    if (!function_exists('wc_get_page_id')) {
        return false;
    }

    $checkout_id = wc_get_page_id('checkout');
    if (!$checkout_id || $checkout_id <= 0) {
        return false;
    }

    $post = get_post($checkout_id);
    if (!$post || empty($post->post_content)) {
        return false;
    }

    return false !== strpos($post->post_content, 'wp:woocommerce/checkout')
        || (function_exists('has_block') && has_block('woocommerce/checkout', $post));
}

/**
 * WooCommerce Checkout Blocks compatibility.
 *
 * Classic checkout fields are handled in frontend-mc-order-fields.php. Blocks do
 * not run those classic field hooks, so we register equivalent additional fields
 * and sync them to the same order meta used by deliveries.
 */
add_action('woocommerce_init', 'storelinkformc_register_blocks_checkout_fields');
function storelinkformc_register_blocks_checkout_fields() {
    if (!function_exists('woocommerce_register_additional_checkout_field')) {
        return;
    }

    if (storelinkformc_blocks_checkout_field_enabled('minecraft_username')) {
        woocommerce_register_additional_checkout_field([
            'id'            => 'storelinkformc/minecraft_username',
            'label'         => __('Minecraft Username', 'storelinkformc'),
            'optionalLabel' => __('Minecraft Username', 'storelinkformc'),
            'location'      => 'contact',
            'type'          => 'text',
            'required'      => false,
            'placeholder'   => '',
            'attributes'    => [
                'autocomplete' => 'off',
                'maxLength'    => '16',
            ],
        ]);
    }

    if (
        storelinkformc_blocks_checkout_field_enabled('minecraft_gift')
        && function_exists('storelinkformc_force_link_enabled')
        && storelinkformc_force_link_enabled()
    ) {
        woocommerce_register_additional_checkout_field([
            'id'            => 'storelinkformc/minecraft_gift',
            'label'         => __('This is a gift', 'storelinkformc'),
            'optionalLabel' => __('This is a gift', 'storelinkformc'),
            'location'      => 'contact',
            'type'          => 'checkbox',
            'required'      => false,
        ]);
    }
}

add_action('woocommerce_store_api_checkout_update_order_from_request', 'storelinkformc_blocks_checkout_update_order_from_request', 10, 2);
function storelinkformc_blocks_checkout_update_order_from_request($order, $request) {
    if (!is_a($order, 'WC_Order')) {
        return;
    }

    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return;
    }

    $values = storelinkformc_blocks_get_checkout_values($order, $request);
    storelinkformc_blocks_validate_checkout_values($values);
    storelinkformc_blocks_sync_order_meta($order, $values);
}

add_action('woocommerce_checkout_order_created', 'storelinkformc_blocks_copy_registered_fields_to_order_meta');
function storelinkformc_blocks_copy_registered_fields_to_order_meta($order) {
    if (!is_a($order, 'WC_Order')) {
        return;
    }

    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return;
    }

    $values  = storelinkformc_blocks_get_checkout_values($order, null);
    $force   = function_exists('storelinkformc_force_link_enabled') ? storelinkformc_force_link_enabled() : true;
    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';

    if ('' === $values['minecraft_username'] && !$values['minecraft_gift'] && !($force && $linked)) {
        return;
    }

    storelinkformc_blocks_sync_order_meta($order, $values);
    $order->save();
}

add_action('wp_head', 'storelinkformc_blocks_checkout_css');
function storelinkformc_blocks_checkout_css() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    if (!storelinkformc_checkout_page_has_checkout_block()) {
        return;
    }

    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
        return;
    }

    $has_synced_products = function_exists('storelinkformc_cart_has_synced_products') && storelinkformc_cart_has_synced_products();
    $force_link          = function_exists('storelinkformc_force_link_enabled') ? storelinkformc_force_link_enabled() : true;
    $hidden_fields       = storelinkformc_blocks_get_hidden_checkout_fields();
    ?>
    <style>
    <?php if (!$has_synced_products) : ?>
    .wc-block-components-text-input:has(#storelinkformc\/minecraft_username),
    .wc-block-components-text-input:has(#storelinkformc-minecraft_username),
    .wc-block-components-checkbox:has(#storelinkformc\/minecraft_gift),
    .wc-block-components-checkbox:has(#storelinkformc-minecraft_gift),
    #storelinkformc\/minecraft_username-wrapper,
    #storelinkformc-minecraft_username-wrapper,
    #storelinkformc\/minecraft_gift-wrapper,
    #storelinkformc-minecraft_gift-wrapper {
        display: none !important;
    }
    <?php elseif (!$force_link) : ?>
    .wc-block-components-checkbox:has(#storelinkformc\/minecraft_gift),
    .wc-block-components-checkbox:has(#storelinkformc-minecraft_gift),
    #storelinkformc\/minecraft_gift-wrapper,
    #storelinkformc-minecraft_gift-wrapper {
        display: none !important;
    }
    <?php endif; ?>
    .wc-block-components-text-input:has(#storelinkformc\/minecraft_username) .wc-block-components-text-input__optional,
    .wc-block-components-text-input:has(#storelinkformc-minecraft_username) .wc-block-components-text-input__optional {
        display: none !important;
    }
    .wc-block-components-text-input:has(#storelinkformc\/minecraft_username),
    .wc-block-components-text-input:has(#storelinkformc-minecraft_username) {
        position: relative;
    }
    #storelinkformc\/minecraft_username,
    #storelinkformc-minecraft_username,
    input[name="storelinkformc/minecraft_username"] {
        min-height: 50px !important;
        padding-left: 54px !important;
        background-repeat: no-repeat !important;
        background-position: 12px center !important;
        background-size: 34px 34px !important;
    }
    .wc-block-components-text-input:has(#storelinkformc\/minecraft_username) label,
    .wc-block-components-text-input:has(#storelinkformc-minecraft_username) label {
        left: 54px !important;
        max-width: calc(100% - 68px) !important;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .wc-block-components-text-input:has(#storelinkformc\/minecraft_username.is-active) label,
    .wc-block-components-text-input:has(#storelinkformc-minecraft_username.is-active) label,
    .wc-block-components-text-input:has(#storelinkformc\/minecraft_username:focus) label,
    .wc-block-components-text-input:has(#storelinkformc-minecraft_username:focus) label {
        left: 54px !important;
        max-width: calc(100% - 68px) !important;
    }
    .wc-block-components-text-input:has(#storelinkformc\/minecraft_username.storelinkformc-has-value) label,
    .wc-block-components-text-input:has(#storelinkformc-minecraft_username.storelinkformc-has-value) label {
        left: 54px !important;
        max-width: calc(100% - 68px) !important;
    }
    .storelinkformc-mc-block-field label {
        top: 6px !important;
        left: 72px !important;
        transform: none !important;
        max-width: calc(100% - 86px) !important;
        font-size: 12px !important;
        line-height: 14px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        pointer-events: none !important;
    }
    .storelinkformc-mc-block-field input {
        min-height: 50px !important;
        padding: 20px 12px 6px 72px !important;
        line-height: 20px !important;
    }
    </style>
    <?php
}

add_action('wp_footer', 'storelinkformc_blocks_checkout_script', 20);
function storelinkformc_blocks_checkout_script() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    if (!storelinkformc_checkout_page_has_checkout_block()) {
        return;
    }

    if (function_exists('storelinkformc_cart_has_synced_products') && storelinkformc_cart_has_synced_products()) {
        return;
    }

    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
        return;
    }

    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return;
    }

    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    $avatar  = $linked
        ? 'https://mc-heads.net/avatar/' . rawurlencode($linked) . '/40'
        : 'https://mc-heads.net/avatar/MHF_Question/40';
    ?>
    <script>
    (function () {
        var linkedPlayer = <?php echo wp_json_encode($linked); ?>;
        var linkedAvatar = <?php echo wp_json_encode($avatar); ?>;
        var questionAvatar = 'https://mc-heads.net/avatar/MHF_Question/40';
        var syncingUsername = false;

        function findUsernameInput() {
            return document.getElementById('storelinkformc/minecraft_username') ||
                document.getElementById('storelinkformc-minecraft_username') ||
                document.querySelector('input[name="storelinkformc/minecraft_username"]') ||
                document.querySelector('input[id$="minecraft_username"]');
        }

        function findGiftCheckbox() {
            var direct = document.getElementById('storelinkformc/minecraft_gift') ||
                document.getElementById('storelinkformc-minecraft_gift') ||
                document.querySelector('input[name="storelinkformc/minecraft_gift"]') ||
                document.querySelector('input[id$="minecraft_gift"]') ||
                document.querySelector('input[name$="minecraft_gift"]');

            if (direct) return direct;

            var labels = document.querySelectorAll('label, .wc-block-components-checkbox__label');
            for (var i = 0; i < labels.length; i++) {
                if (!/gift/i.test(labels[i].textContent || '')) continue;
                var field = labels[i].closest('.wc-block-components-checkbox') || labels[i].parentElement;
                var input = field ? field.querySelector('input[type="checkbox"]') : null;
                if (input) return input;
            }

            return null;
        }

        function setNativeValue(input, value) {
            var descriptor = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(input), 'value');
            var setter = descriptor && descriptor.set;
            if (setter) {
                setter.call(input, value);
            } else {
                input.value = value;
            }
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function setAvatar(input, url) {
            input.style.setProperty('background-image', "url('" + url + "')", 'important');
            input.style.setProperty('background-repeat', 'no-repeat', 'important');
            input.style.setProperty('background-position', '12px center', 'important');
            input.style.setProperty('background-size', '34px 34px', 'important');
            input.style.setProperty('padding', '20px 12px 6px 72px', 'important');
            input.style.setProperty('min-height', '50px', 'important');
            input.style.setProperty('line-height', '20px', 'important');
            styleMinecraftField(input);
        }

        function styleMinecraftField(input) {
            var field = input.closest('.wc-block-components-text-input') || input.parentElement;
            if (!field) return;

            field.classList.add('storelinkformc-mc-block-field');
            var label = field.querySelector('label');
            if (!label) return;

            label.style.setProperty('top', '6px', 'important');
            label.style.setProperty('left', '72px', 'important');
            label.style.setProperty('transform', 'none', 'important');
            label.style.setProperty('max-width', 'calc(100% - 86px)', 'important');
            label.style.setProperty('font-size', '12px', 'important');
            label.style.setProperty('line-height', '14px', 'important');
            label.style.setProperty('white-space', 'nowrap', 'important');
            label.style.setProperty('overflow', 'hidden', 'important');
            label.style.setProperty('text-overflow', 'ellipsis', 'important');
            label.style.setProperty('pointer-events', 'none', 'important');
        }

        function setValueState(input) {
            var hasValue = !!input.value;
            input.classList.toggle('is-active', hasValue);
            input.classList.toggle('storelinkformc-has-value', hasValue);
        }

        function syncUsernameValue(input) {
            if (!input || syncingUsername) return;

            var value = cleanMinecraftName(input.value);
            syncingUsername = true;
            if (value !== input.value) {
                setNativeValue(input, value);
            } else {
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncingUsername = false;
        }

        function cleanMinecraftName(value) {
            return (value || '').trim().replace(/[^A-Za-z0-9_]/g, '');
        }

        function clearGiftUsernameNotice(input) {
            var gift = findGiftCheckbox();
            var player = input ? cleanMinecraftName(input.value) : '';
            if (!gift || !gift.checked || !/^[A-Za-z0-9_]{3,16}$/.test(player)) return;

            document.querySelectorAll('.wc-block-components-notice-banner, .woocommerce-error, .woocommerce-message, .woocommerce-info').forEach(function (notice) {
                if (/recipient's Minecraft username/i.test(notice.textContent || '')) {
                    notice.remove();
                }
            });
        }

        function updateGiftAvatar(input) {
            var gift = findGiftCheckbox();
            if (!gift || !gift.checked) return;

            var player = cleanMinecraftName(input.value);
            setAvatar(input, player ? 'https://mc-heads.net/avatar/' + encodeURIComponent(player) + '/40' : questionAvatar);
            input.title = player || '';
            setValueState(input);
            clearGiftUsernameNotice(input);
        }

        function applyState() {
            var input = findUsernameInput();
            if (!input) return;

            var gift = findGiftCheckbox();
            var giftMode = !!(gift && gift.checked);
            var player = linkedPlayer || '';

            setAvatar(input, giftMode ? questionAvatar : linkedAvatar);

            if (giftMode) {
                input.disabled = false;
                input.readOnly = false;
                input.removeAttribute('disabled');
                input.removeAttribute('readonly');
                input.required = true;
                input.setAttribute('aria-required', 'true');
                syncUsernameValue(input);
                setValueState(input);
                input.placeholder = '';
                input.title = '';
                updateGiftAvatar(input);
                clearGiftUsernameNotice(input);
                return;
            }

            input.required = false;
            input.removeAttribute('aria-required');
            input.disabled = false;
            input.readOnly = true;
            input.removeAttribute('disabled');
            input.setAttribute('readonly', 'readonly');
            input.placeholder = '';
            input.title = player || 'No linked player';
            if (player && input.value !== player) {
                setNativeValue(input, player);
            }
            setValueState(input);
        }

        function bind() {
            applyState();
            var input = findUsernameInput();
            if (input && !input.dataset.storelinkformcInputBound) {
                input.dataset.storelinkformcInputBound = '1';
                input.addEventListener('input', function () {
                    syncUsernameValue(input);
                    updateGiftAvatar(input);
                });
                input.addEventListener('change', function () {
                    syncUsernameValue(input);
                    updateGiftAvatar(input);
                });
            }
            var gift = findGiftCheckbox();
            if (gift && !gift.dataset.storelinkformcBound) {
                gift.dataset.storelinkformcBound = '1';
                gift.addEventListener('change', applyState);
            }

        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bind);
        } else {
            bind();
        }

        new MutationObserver(bind).observe(document.body, { childList: true, subtree: true });
    })();
    </script>
    <?php
}

function storelinkformc_blocks_checkout_field_enabled(string $field): bool {
    $allowed = get_option('storelinkformc_checkout_fields', []);
    if (!is_array($allowed) || empty($allowed)) {
        return true;
    }

    return in_array($field, $allowed, true);
}

function storelinkformc_blocks_get_hidden_checkout_fields(): array {
    $allowed = get_option('storelinkformc_checkout_fields', []);
    if (!is_array($allowed) || empty($allowed)) {
        return [];
    }

    $map = [
        'billing_first_name' => [
            '.wc-block-components-address-form__first_name',
            '#billing-first_name_field',
        ],
        'billing_last_name' => [
            '.wc-block-components-address-form__last_name',
            '#billing-last_name_field',
        ],
        'billing_email' => [
            '.wc-block-components-text-input:has(#email)',
        ],
        'billing_address_1' => [
            '.wc-block-components-address-form__address_1',
            '.wc-block-components-address-form__address_2',
            '#billing-address_1_field',
            '#billing-address_2_field',
        ],
        'billing_city' => [
            '.wc-block-components-address-form__city',
            '#billing-city_field',
        ],
        'billing_postcode' => [
            '.wc-block-components-address-form__postcode',
            '#billing-postcode_field',
        ],
        'billing_country' => [
            '.wc-block-components-address-form__country',
            '#billing-country_field',
        ],
        'billing_state' => [
            '.wc-block-components-address-form__state',
            '#billing-state_field',
        ],
        'billing_phone' => [
            '.wc-block-components-address-form__phone',
            '#billing-phone_field',
        ],
        'shipping_first_name' => [
            '.wc-block-components-shipping-address .wc-block-components-address-form__first_name',
        ],
        'shipping_last_name' => [
            '.wc-block-components-shipping-address .wc-block-components-address-form__last_name',
        ],
        'shipping_address_1' => [
            '.wc-block-components-shipping-address .wc-block-components-address-form__address_1',
            '.wc-block-components-shipping-address .wc-block-components-address-form__address_2',
        ],
        'shipping_city' => [
            '.wc-block-components-shipping-address .wc-block-components-address-form__city',
        ],
        'shipping_postcode' => [
            '.wc-block-components-shipping-address .wc-block-components-address-form__postcode',
        ],
        'shipping_country' => [
            '.wc-block-components-shipping-address .wc-block-components-address-form__country',
        ],
        'shipping_state' => [
            '.wc-block-components-shipping-address .wc-block-components-address-form__state',
        ],
        'shipping_phone' => [
            '.wc-block-components-shipping-address .wc-block-components-address-form__phone',
        ],
    ];

    $selectors = [];
    foreach ($map as $field => $field_selectors) {
        if (!in_array($field, $allowed, true)) {
            $selectors = array_merge($selectors, $field_selectors);
        }
    }

    return array_values(array_unique($selectors));
}

function storelinkformc_blocks_get_checkout_values($order, $request): array {
    $values = [
        'minecraft_username' => '',
        'minecraft_gift'     => false,
    ];
    $username_enabled = storelinkformc_blocks_checkout_field_enabled('minecraft_username');
    $gift_enabled     = storelinkformc_blocks_checkout_field_enabled('minecraft_gift');

    if ($request && is_callable([$request, 'get_param'])) {
        $additional = $request->get_param('additional_fields');
        if (is_array($additional)) {
            $values['minecraft_username'] = storelinkformc_blocks_clean_username_value($additional['storelinkformc/minecraft_username'] ?? '');
            $values['minecraft_gift']     = storelinkformc_blocks_bool_value($additional['storelinkformc/minecraft_gift'] ?? false);
        }

        $extensions = $request->get_param('extensions');
        if (is_array($extensions) && isset($extensions['storelinkformc']) && is_array($extensions['storelinkformc'])) {
            $values['minecraft_username'] = storelinkformc_blocks_clean_username_value($extensions['storelinkformc']['minecraft_username'] ?? $values['minecraft_username']);
            $values['minecraft_gift']     = storelinkformc_blocks_bool_value($extensions['storelinkformc']['minecraft_gift'] ?? $values['minecraft_gift']);
        }

        if ('' === $values['minecraft_username']) {
            $values['minecraft_username'] = storelinkformc_blocks_clean_username_value(
                storelinkformc_blocks_find_request_value($request, ['minecraft_username', 'minecraft-username'])
            );
        }

        if (!$values['minecraft_gift']) {
            $gift_value = storelinkformc_blocks_find_request_value($request, ['minecraft_gift', 'minecraft-gift']);
            if (null !== $gift_value) {
                $values['minecraft_gift'] = storelinkformc_blocks_bool_value($gift_value);
            }
        }
    }

    if ('' === $values['minecraft_username']) {
        $values['minecraft_username'] = storelinkformc_blocks_clean_username_value(storelinkformc_blocks_get_order_additional_field($order, 'minecraft_username'));
    }

    if (!$values['minecraft_gift']) {
        $values['minecraft_gift'] = storelinkformc_blocks_bool_value(storelinkformc_blocks_get_order_additional_field($order, 'minecraft_gift'));
    }

    if (!$username_enabled) {
        $values['minecraft_username'] = '';
    }

    if (!$gift_enabled) {
        $values['minecraft_gift'] = false;
    }

    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    if ($values['minecraft_gift'] && '' === $values['minecraft_username'] && $linked) {
        $values['minecraft_username'] = $linked;
    }

    return $values;
}

function storelinkformc_blocks_get_order_additional_field($order, string $field) {
    $keys = [
        '_wc_other/storelinkformc/' . $field,
        'storelinkformc/' . $field,
        '_storelinkformc_' . $field,
    ];

    foreach ($keys as $key) {
        $value = $order->get_meta($key, true);
        if ('' !== $value && null !== $value) {
            return $value;
        }
    }

    return '';
}

function storelinkformc_blocks_find_request_value($request, array $needles) {
    if (!$request || !is_callable([$request, 'get_params'])) {
        return null;
    }

    $params = $request->get_params();
    return storelinkformc_blocks_find_nested_value($params, $needles);
}

function storelinkformc_blocks_find_nested_value($value, array $needles) {
    if (!is_array($value)) {
        return null;
    }

    foreach ($value as $key => $child) {
        $normalized_key = strtolower(str_replace(['/', '_'], '-', (string) $key));
        foreach ($needles as $needle) {
            $normalized_needle = strtolower(str_replace(['/', '_'], '-', $needle));
            if ($normalized_key === $normalized_needle || str_ends_with($normalized_key, '-' . $normalized_needle)) {
                return $child;
            }
        }

        if (is_array($child)) {
            $found = storelinkformc_blocks_find_nested_value($child, $needles);
            if (null !== $found) {
                return $found;
            }
        }
    }

    return null;
}

function storelinkformc_blocks_validate_checkout_values(array $values): void {
    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    $force   = function_exists('storelinkformc_force_link_enabled') ? storelinkformc_force_link_enabled() : true;
    $policy  = get_option('storelinkformc_username_policy', 'premium');
    $username_enabled = storelinkformc_blocks_checkout_field_enabled('minecraft_username');
    $gift_enabled     = storelinkformc_blocks_checkout_field_enabled('minecraft_gift');

    if (!$username_enabled && !$gift_enabled) {
        return;
    }

    if ($force) {
        if ($values['minecraft_gift'] && $gift_enabled) {
            if ('' === $values['minecraft_username'] && $linked) {
                $values['minecraft_username'] = $linked;
            }

            if ('' === $values['minecraft_username']) {
                storelinkformc_blocks_throw_checkout_error(__('Please enter the recipient\'s Minecraft username (gift).', 'storelinkformc'));
            }

            if (!storelinkformc_checkout_verify_username($values['minecraft_username'], $policy)) {
                storelinkformc_blocks_throw_checkout_error(__('Invalid or not allowed Minecraft username for this server policy.', 'storelinkformc'));
            }
            return;
        }

        if ($linked && '' !== $values['minecraft_username'] && $values['minecraft_username'] !== $linked) {
            storelinkformc_blocks_throw_checkout_error(__('You cannot change your linked Minecraft username unless you mark this order as a gift.', 'storelinkformc'));
        }

        if (!$linked && '' !== $values['minecraft_username']) {
            storelinkformc_blocks_throw_checkout_error(__('You must link your Minecraft account or mark this order as a gift to enter a username.', 'storelinkformc'));
        }
        return;
    }

    if (!$username_enabled) {
        return;
    }

    if ('' === $values['minecraft_username']) {
        storelinkformc_blocks_throw_checkout_error(__('Please enter a Minecraft username.', 'storelinkformc'));
    }

    if (!storelinkformc_checkout_verify_username($values['minecraft_username'], $policy)) {
        storelinkformc_blocks_throw_checkout_error(__('Invalid or not allowed Minecraft username for this server policy.', 'storelinkformc'));
    }
}

function storelinkformc_blocks_sync_order_meta($order, array $values): void {
    $user_id = get_current_user_id();
    $linked  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';
    $force   = function_exists('storelinkformc_force_link_enabled') ? storelinkformc_force_link_enabled() : true;
    $username_enabled = storelinkformc_blocks_checkout_field_enabled('minecraft_username');
    $gift_enabled     = storelinkformc_blocks_checkout_field_enabled('minecraft_gift');

    if ($force) {
        if ($values['minecraft_gift'] && $gift_enabled) {
            $order->update_meta_data('_minecraft_username', $values['minecraft_username']);
            $order->update_meta_data('_minecraft_gift', 'yes');
            $order->update_meta_data('_slmc_target_type', 'gift');
        } elseif ($linked) {
            $order->update_meta_data('_minecraft_username', $linked);
            $order->update_meta_data('_minecraft_gift', 'no');
            $order->update_meta_data('_slmc_target_type', 'linked');
        } else {
            $order->delete_meta_data('_minecraft_username');
            $order->update_meta_data('_minecraft_gift', 'no');
            $order->delete_meta_data('_slmc_target_type');
        }
        return;
    }

    if (!$username_enabled) {
        return;
    }

    $order->update_meta_data('_minecraft_username', $values['minecraft_username']);
    $order->update_meta_data('_minecraft_gift', 'no');
    $order->update_meta_data('_slmc_target_type', 'manual_username');
}

function storelinkformc_blocks_throw_checkout_error(string $message): void {
    if (class_exists('Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'storelinkformc_checkout_minecraft_username_error',
            $message,
            400
        );
    }

    if (function_exists('wc_add_notice')) {
        wc_add_notice($message, 'error');
    }
}

function storelinkformc_blocks_clean_username_value($value): string {
    if (is_array($value) || is_object($value)) {
        return '';
    }

    return sanitize_text_field(wp_unslash((string) $value));
}

function storelinkformc_blocks_bool_value($value): bool {
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (bool) (int) $value;
    }

    return in_array(strtolower((string) $value), ['1', 'yes', 'true', 'on'], true);
}
