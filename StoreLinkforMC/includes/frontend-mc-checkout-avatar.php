<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Show Minecraft head inside checkout field
 * - respeta storelinkformc_username_policy
 * - si es regalo, muestra la cabeza del destinatario en tiempo real
 */
add_action('wp_footer', function () {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // If the cart does not contain synced products, do not load the Minecraft avatar widget
    if (!function_exists('storelinkformc_cart_has_synced_products') || !storelinkformc_cart_has_synced_products()) {
        return;
    }

    $user_id = get_current_user_id();
    $player  = $user_id ? sanitize_text_field(get_user_meta($user_id, 'minecraft_player', true)) : '';

    $username_policy = get_option('storelinkformc_username_policy', 'any'); // 'premium' o 'any'

    $default_avatar = $player
        ? 'https://mc-heads.net/avatar/' . rawurlencode($player) . '/40'
        : 'https://mc-heads.net/avatar/MHF_Question/40';
    ?>
    <style>
    #billing_minecraft_username,
    #minecraft_username,
    input[name="billing[minecraft_username]"] {
        background-image: url('<?php echo esc_url($default_avatar); ?>') !important;
        background-repeat: no-repeat !important;
        background-position: 10px center !important;
        background-size: 34px 34px !important;
        padding-left: 54px !important;
        transition: background-image 0.15s ease-in-out;
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const usernameInput =
            document.getElementById('billing_minecraft_username') ||
            document.getElementById('minecraft_username') ||
            document.querySelector('input[name="billing[minecraft_username]"]');

        if (!usernameInput) {
            return;
        }

        // localizar checkbox de regalo
        let giftCheckbox =
            document.querySelector('#storelinkformc_is_gift') ||
            document.querySelector('input[name="storelinkformc_is_gift"]') ||
            document.querySelector('input[name="this_is_a_gift"]') ||
            document.querySelector('input[id*="gift"]') ||
            document.querySelector('input[name*="gift"]');

        if (!giftCheckbox) {
            document.querySelectorAll('label').forEach(function (label) {
                if (!giftCheckbox && /gift/i.test(label.textContent)) {
                    const inside = label.querySelector('input[type="checkbox"]');
                    if (inside) {
                        giftCheckbox = inside;
                    }
                    const forAttr = label.getAttribute('for');
                    if (!giftCheckbox && forAttr) {
                        const byFor = document.getElementById(forAttr);
                        if (byFor && byFor.type === 'checkbox') {
                            giftCheckbox = byFor;
                        }
                    }
                }
            });
        }

        const linkedAvatar   = '<?php echo esc_js($default_avatar); ?>';
        const questionAvatar = 'https://mc-heads.net/avatar/MHF_Question/40';
        const usernamePolicy = '<?php echo esc_js($username_policy); ?>';

        // poner fondo con !important
        function setAvatar(url) {
            usernameInput.style.setProperty('background-image', "url('" + url + "')", 'important');
        }

        // quita espacios, caracteres raros, guiones… solo deja nicks válidos
        function sanitizeName(raw) {
            // primero trim
            let clean = raw.trim();
            // luego quitar todo lo que no sea MC normal
            clean = clean.replace(/[^A-Za-z0-9_]/g, '');
            return clean;
        }

        function isPremiumLike(name) {
            return /^[A-Za-z0-9_]{3,16}$/.test(name);
        }

        function updateAvatarFromInput() {
            const giftMode = giftCheckbox && giftCheckbox.checked;

            // si no es regalo → siempre el vinculado
            if (!giftMode) {
                setAvatar(linkedAvatar);
                usernameInput.title = <?php echo $player ? json_encode($player) : json_encode('No linked player'); ?>;
                return;
            }

            // regalo → usar lo que escriben
            let val = usernameInput.value || '';
            val = sanitizeName(val);

            // política premium: si no parece premium, mostramos ?
            if (usernamePolicy === 'premium') {
                if (!isPremiumLike(val)) {
                    setAvatar(questionAvatar);
                    usernameInput.title = 'Invalid premium username';
                    return;
                }
            }

            if (!val) {
                setAvatar(questionAvatar);
                usernameInput.title = 'No linked player';
                return;
            }

            // añadimos un query ?t= para saltarnos caché
            const avatarUrl = 'https://mc-heads.net/avatar/' + encodeURIComponent(val) + '/40?t=' + Date.now();
            setAvatar(avatarUrl);
            usernameInput.title = val;
        }

        // eventos
        ['input', 'keyup', 'change', 'blur'].forEach(function (evt) {
            usernameInput.addEventListener(evt, updateAvatarFromInput);
        });
        if (giftCheckbox) {
            giftCheckbox.addEventListener('change', updateAvatarFromInput);
        }

        // estado inicial
        updateAvatarFromInput();
    });
    </script>
    <?php
});
