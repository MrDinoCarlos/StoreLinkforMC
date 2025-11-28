<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin page: Email Templates (visual editor + defaults)
 * Options:
 *  - slmc_tpl_link_subject
 *  - slmc_tpl_link_html
 */

add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        __('Email Templates', 'StoreLinkforMC'),
        __('Email Templates', 'StoreLinkforMC'),
        'manage_options',
        'storelinkformc_email_templates',
        'storelinkformc_email_templates_page'
    );
});

/** Default templates */
function slmc_default_email_subject(): string {
    return 'Link your Minecraft account on {site_name}';
}

function slmc_default_email_body(): string {
    // Email-friendly HTML (tablas e inline styles son los más compatibles)
    return '
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;">
  <tr><td style="padding:16px 0;text-align:center;">
    <h2 style="margin:0;font-family:Arial,Helvetica,sans-serif;">{site_name}</h2>
  </td></tr>
  <tr><td style="background:#ffffff;border:1px solid #e5e5e5;border-radius:6px;padding:20px;font-family:Arial,Helvetica,sans-serif;">
    <p>Hello {user_email},</p>
    <p>Use this code to link your account:</p>
    <p style="font-size:22px;font-weight:bold;letter-spacing:2px;">{verify_code}</p>
    <p>Minecraft username: <strong>{player}</strong></p>
    <!-- Example banner inserted from Media Library (replace src if you want a default banner) -->
    <p style="text-align:center;margin-top:24px;">
      <img src="" alt="" style="max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">
    </p>
    <p style="color:#666;">If you didn\'t request this, you can ignore this email.</p>
    <p>— {site_name}</p>
  </td></tr>
</table>';
}

function storelinkformc_email_templates_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Defaults
    $default_subject = slmc_default_email_subject();
    $default_body    = slmc_default_email_body();

    // Save
    if (isset($_POST['slmc_email_tpl_save']) && check_admin_referer('slmc_email_tpl_nonce')) {
        $subject_raw = isset($_POST['slmc_tpl_link_subject']) ? wp_unslash($_POST['slmc_tpl_link_subject']) : '';
        $subject     = sanitize_text_field($subject_raw);

        // Allow safe email HTML incl. <img>, tables, inline styles
        $allowed = wp_kses_allowed_html('post');
        // broaden for emails: allow style on more tags + table attributes
        foreach (['table', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot', 'span', 'div', 'p', 'img', 'a'] as $t) {
            $allowed[$t]['style'] = true;
        }
        $allowed['table']['cellpadding'] = true;
        $allowed['table']['cellspacing'] = true;
        $allowed['table']['role']        = true;
        $allowed['img']['width']         = true;
        $allowed['img']['height']        = true;

        $html_raw = isset($_POST['slmc_tpl_link_html']) ? wp_unslash($_POST['slmc_tpl_link_html']) : '';
        $html     = wp_kses($html_raw, $allowed);

        update_option('slmc_tpl_link_subject', $subject ?: $default_subject);
        update_option('slmc_tpl_link_html', $html ?: $default_body);

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Templates saved.', 'StoreLinkforMC') . '</p></div>';
    }

    // Reset
    if (isset($_POST['slmc_email_tpl_reset']) && check_admin_referer('slmc_email_tpl_nonce')) {
        update_option('slmc_tpl_link_subject', $default_subject);
        update_option('slmc_tpl_link_html', $default_body);
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Templates reset to defaults.', 'StoreLinkforMC') . '</p></div>';
    }

    $subject = get_option('slmc_tpl_link_subject', $default_subject);
    $body    = get_option('slmc_tpl_link_html', $default_body);

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Email Templates', 'StoreLinkforMC'); ?></h1>
        <p><?php esc_html_e('Customize the email sent when a player requests a verification code.', 'StoreLinkforMC'); ?></p>

        <p><strong><?php esc_html_e('Available placeholders:', 'StoreLinkforMC'); ?></strong></p>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><code>{site_name}</code> – <?php esc_html_e('Your site name', 'StoreLinkforMC'); ?></li>
            <li><code>{user_email}</code> – <?php esc_html_e('Recipient email', 'StoreLinkforMC'); ?></li>
            <li><code>{verify_code}</code> – <?php esc_html_e('6-digit code', 'StoreLinkforMC'); ?></li>
            <li><code>{link_url}</code> – <?php esc_html_e('One-click verification URL', 'StoreLinkforMC'); ?></li>
            <li><code>{player}</code> – <?php esc_html_e('Minecraft username', 'StoreLinkforMC'); ?></li>
        </ul>

        <form method="post">
            <?php wp_nonce_field('slmc_email_tpl_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="slmc_tpl_link_subject">
                            <?php esc_html_e('Subject', 'StoreLinkforMC'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               class="regular-text"
                               id="slmc_tpl_link_subject"
                               name="slmc_tpl_link_subject"
                               value="<?php echo esc_attr($subject); ?>"
                               required>
                        <button type="button" class="button" id="slmc_insert_default_subject" style="margin-left:8px;">
                            <?php esc_html_e('Insert default', 'StoreLinkforMC'); ?>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="slmc_tpl_link_html">
                            <?php esc_html_e('HTML Body', 'StoreLinkforMC'); ?>
                        </label>
                    </th>
                    <td>
                        <?php
                        // Visual editor with Media button enabled (images from Media Library)
                        $editor_id = 'slmc_tpl_link_html';
                        $settings  = [
                            'textarea_name' => 'slmc_tpl_link_html',
                            'media_buttons' => true,
                            'teeny'         => false,
                            'tinymce'       => true, // sin especificar plugins/toolbar
                            'quicktags'     => true,
                        ];
                        wp_editor($body, $editor_id, $settings);
                        ?>
                        <p class="description">
                            <?php esc_html_e('Visual editor with media support. Most email clients prefer simple HTML with inline styles and images by URL.', 'StoreLinkforMC'); ?>
                        </p>
                        <p>
                            <button type="button" class="button" id="slmc_insert_default_body">
                                <?php esc_html_e('Insert default', 'StoreLinkforMC'); ?>
                            </button>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="slmc_email_tpl_save" class="button button-primary">
                    <?php esc_html_e('Save templates', 'StoreLinkforMC'); ?>
                </button>
                <button type="submit" name="slmc_email_tpl_reset" class="button"
                        onclick="return confirm('<?php echo esc_js(__('Reset to default templates?', 'StoreLinkforMC')); ?>');">
                    <?php esc_html_e('Reset to defaults', 'StoreLinkforMC'); ?>
                </button>
            </p>
        </form>
    </div>

    <script>
    (function(){
        const defaultSubject = <?php echo wp_json_encode($default_subject); ?>;
        const defaultBody    = <?php echo wp_json_encode($default_body); ?>;

        const subjectBtn = document.getElementById('slmc_insert_default_subject');
        const bodyBtn    = document.getElementById('slmc_insert_default_body');

        if (subjectBtn) {
            subjectBtn.addEventListener('click', function(){
                const subjectField = document.getElementById('slmc_tpl_link_subject');
                if (subjectField) {
                    subjectField.value = defaultSubject;
                }
            });
        }

        if (bodyBtn) {
            bodyBtn.addEventListener('click', function(){
                if (window.tinymce && tinymce.get('slmc_tpl_link_html')) {
                    tinymce.get('slmc_tpl_link_html').setContent(defaultBody);
                } else {
                    const ta = document.getElementById('slmc_tpl_link_html');
                    if (ta) {
                        ta.value = defaultBody;
                    }
                }
            });
        }
    })();
    </script>
    <?php
}
