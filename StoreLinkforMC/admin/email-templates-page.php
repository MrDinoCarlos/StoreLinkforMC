<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin page: Email Templates (visual editor + defaults)
 * Options:
 *  - slmc_tpl_link_subject
 *  - slmc_tpl_link_html
 */

add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        'Email Templates',
        'Email Templates',
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
    if (!current_user_can('manage_options')) return;

    // Defaults
    $default_subject = slmc_default_email_subject();
    $default_body    = slmc_default_email_body();

    // Save
    if (isset($_POST['slmc_email_tpl_save']) && check_admin_referer('slmc_email_tpl_nonce')) {
        $subject = sanitize_text_field($_POST['slmc_tpl_link_subject'] ?? '');

        // Allow safe email HTML incl. <img>, tables, inline styles
        $allowed = wp_kses_allowed_html('post');
        // broaden for emails: allow style on more tags + table attributes
        foreach (['table','tr','td','th','thead','tbody','tfoot','span','div','p','img','a'] as $t) {
            $allowed[$t]['style'] = true;
        }
        $allowed['table']['cellpadding'] = true;
        $allowed['table']['cellspacing'] = true;
        $allowed['table']['role'] = true;
        $allowed['img']['width']  = true;
        $allowed['img']['height'] = true;

        $html_raw = wp_unslash($_POST['slmc_tpl_link_html'] ?? '');
        $html     = wp_kses($html_raw, $allowed);

        update_option('slmc_tpl_link_subject', $subject ?: $default_subject);
        update_option('slmc_tpl_link_html',    $html ?: $default_body);

        echo '<div class="notice notice-success is-dismissible"><p>Templates saved.</p></div>';
    }

    // Reset
    if (isset($_POST['slmc_email_tpl_reset']) && check_admin_referer('slmc_email_tpl_nonce')) {
        update_option('slmc_tpl_link_subject', $default_subject);
        update_option('slmc_tpl_link_html',    $default_body);
        echo '<div class="notice notice-warning is-dismissible"><p>Templates reset to defaults.</p></div>';
    }

    $subject = esc_attr(get_option('slmc_tpl_link_subject', $default_subject));
    $body    = get_option('slmc_tpl_link_html', $default_body);

    ?>
    <div class="wrap">
        <h1>Email Templates</h1>
        <p>Customize the email sent when a player requests a verification code.</p>

        <p><strong>Available placeholders:</strong></p>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><code>{site_name}</code> – Your site name</li>
            <li><code>{user_email}</code> – Recipient email</li>
            <li><code>{verify_code}</code> – 6-digit code</li>
            <li><code>{link_url}</code> – One-click verification URL</li>
            <li><code>{player}</code> – Minecraft username</li>
        </ul>

        <form method="post">
            <?php wp_nonce_field('slmc_email_tpl_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="slmc_tpl_link_subject">Subject</label></th>
                    <td>
                        <input type="text" class="regular-text" id="slmc_tpl_link_subject" name="slmc_tpl_link_subject" value="<?php echo $subject; ?>" required>
                        <button type="button" class="button" id="slmc_insert_default_subject" style="margin-left:8px;">Insert default</button>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="slmc_tpl_link_html">HTML Body</label></th>
                    <td>
                        <?php
                        // Visual editor with Media button enabled (images from Media Library)
                        $editor_id = 'slmc_tpl_link_html';
                        $settings = [
                          'textarea_name' => 'slmc_tpl_link_html',
                          'media_buttons' => true,
                          'teeny'         => false,
                          'tinymce'       => true,    // <– sin especificar plugins/toolbar
                          'quicktags'     => true,
                        ];
                        wp_editor($body, 'slmc_tpl_link_html', $settings);

                        ?>
                        <p class="description">Visual editor with media support. Most email clients prefer simple HTML with inline styles and images by URL.</p>
                        <p>
                            <button type="button" class="button" id="slmc_insert_default_body">Insert default</button>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="slmc_email_tpl_save" class="button button-primary">Save templates</button>
                <button type="submit" name="slmc_email_tpl_reset" class="button" onclick="return confirm('Reset to default templates?');">Reset to defaults</button>
            </p>
        </form>
    </div>

    <script>
    (function(){
        const defaultSubject = <?php echo wp_json_encode($default_subject); ?>;
        const defaultBody    = <?php echo wp_json_encode($default_body); ?>;

        document.getElementById('slmc_insert_default_subject')?.addEventListener('click', function(){
            document.getElementById('slmc_tpl_link_subject').value = defaultSubject;
        });

        document.getElementById('slmc_insert_default_body')?.addEventListener('click', function(){
            if (window.tinymce && tinymce.get('slmc_tpl_link_html')) {
                tinymce.get('slmc_tpl_link_html').setContent(defaultBody);
            } else {
                const ta = document.getElementById('slmc_tpl_link_html');
                if (ta) ta.value = defaultBody;
            }
        });
    })();
    </script>
    <?php
}
