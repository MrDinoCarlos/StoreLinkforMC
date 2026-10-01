<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'storelinkformc',
        __('Linked profiles', 'storelinkformc'),
        __('Linked profiles', 'storelinkformc'),
        'manage_woocommerce',
        'storelinkformc_linked_profiles',
        'storelinkformc_render_linked_profiles_page'
    );
});

function storelinkformc_render_linked_profiles_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to manage linked profiles.', 'storelinkformc'));
    }

    if (
        isset($_POST['storelinkformc_profile_action'], $_POST['user_id'], $_POST['_wpnonce']) &&
        'unlink' === sanitize_key(wp_unslash($_POST['storelinkformc_profile_action'])) &&
        wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['_wpnonce'])),
            'storelinkformc_unlink_profile_' . absint($_POST['user_id'])
        )
    ) {
        $user_id = absint($_POST['user_id']);
        if ($user_id && get_user_by('id', $user_id)) {
            storelinkformc_unlink_account($user_id);
            $redirect = add_query_arg(
                ['page' => 'storelinkformc_linked_profiles', 'profile_unlinked' => 1],
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $per_page = 20;

    $query_args = [
        'number'      => $per_page,
        'offset'      => ($paged - 1) * $per_page,
        'orderby'     => 'registered',
        'order'       => 'DESC',
        'count_total' => true,
        'meta_query'  => [
            [
                'key'     => 'minecraft_player',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => 'minecraft_player',
                'value'   => '',
                'compare' => '!=',
            ],
        ],
    ];
    if ($search) {
        $query_args['search'] = '*' . $search . '*';
        $query_args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }

    $query = new WP_User_Query($query_args);
    $users = $query->get_results();

    // Include Minecraft-name matches without losing the normal user search.
    if ($search && empty($users)) {
        $query_args['search'] = '';
        unset($query_args['search_columns']);
        $query_args['meta_query'] = [[
            'key'     => 'minecraft_player',
            'value'   => $search,
            'compare' => 'LIKE',
        ]];
        $query = new WP_User_Query($query_args);
        $users = $query->get_results();
    }

    $total = (int) $query->get_total();
    $pages = max(1, (int) ceil($total / $per_page));
    ?>
    <div class="wrap storelinkformc-admin">
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
        <?php if (isset($_GET['profile_unlinked'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('The Minecraft profile was unlinked successfully.', 'storelinkformc'); ?></p></div>
        <?php endif; ?>

        <div class="storelinkformc-admin-header storelinkformc-admin-header-profiles">
            <div>
                <span class="storelinkformc-eyebrow"><?php esc_html_e('Account management', 'storelinkformc'); ?></span>
                <h1><?php esc_html_e('Linked profiles', 'storelinkformc'); ?></h1>
                <p class="storelinkformc-admin-subtitle"><?php esc_html_e('Find the WordPress account behind each Minecraft player and safely repair incorrect links.', 'storelinkformc'); ?></p>
            </div>
            <div class="storelinkformc-admin-stats">
                <div class="storelinkformc-stat"><strong><?php echo esc_html((string) $total); ?></strong><span><?php esc_html_e('linked accounts', 'storelinkformc'); ?></span></div>
            </div>
        </div>

        <div class="storelinkformc-panel">
            <div class="storelinkformc-profile-toolbar">
                <form method="get" class="storelinkformc-search-form">
                    <input type="hidden" name="page" value="storelinkformc_linked_profiles">
                    <label for="storelinkformc-profile-search"><?php esc_html_e('Search profiles', 'storelinkformc'); ?></label>
                    <div class="storelinkformc-search-control">
                        <input id="storelinkformc-profile-search" type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Email, web name or Minecraft name', 'storelinkformc'); ?>">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Search', 'storelinkformc'); ?></button>
                        <?php if ($search) : ?>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=storelinkformc_linked_profiles')); ?>"><?php esc_html_e('Clear', 'storelinkformc'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if (!$users) : ?>
                <div class="storelinkformc-empty storelinkformc-empty-large">
                    <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                    <h2><?php esc_html_e('No linked profiles found', 'storelinkformc'); ?></h2>
                    <p><?php esc_html_e('Linked customers will appear here after they verify their account from Minecraft.', 'storelinkformc'); ?></p>
                </div>
            <?php else : ?>
                <div class="storelinkformc-table-scroll">
                    <table class="widefat storelinkformc-table storelinkformc-profiles-table">
                        <thead><tr>
                            <th><?php esc_html_e('Minecraft profile', 'storelinkformc'); ?></th>
                            <th><?php esc_html_e('Website account', 'storelinkformc'); ?></th>
                            <th><?php esc_html_e('Email', 'storelinkformc'); ?></th>
                            <th><?php esc_html_e('Status', 'storelinkformc'); ?></th>
                            <th><?php esc_html_e('Actions', 'storelinkformc'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($users as $user) :
                            $minecraft_name = sanitize_text_field(get_user_meta($user->ID, 'minecraft_player', true));
                            if (!$minecraft_name) continue;
                            $head_url = 'https://mc-heads.net/avatar/' . rawurlencode($minecraft_name) . '/64';
                            ?>
                            <tr>
                                <td>
                                    <div class="storelinkformc-player">
                                        <img src="<?php echo esc_url($head_url); ?>" width="46" height="46" loading="lazy" alt="">
                                        <div><strong><?php echo esc_html($minecraft_name); ?></strong><span><?php esc_html_e('Minecraft username', 'storelinkformc'); ?></span></div>
                                    </div>
                                </td>
                                <td><strong><?php echo esc_html($user->display_name ?: $user->user_login); ?></strong><span class="storelinkformc-user-login">@<?php echo esc_html($user->user_login); ?></span></td>
                                <td><a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a></td>
                                <td><span class="storelinkformc-status storelinkformc-status-delivered"><span class="storelinkformc-status-dot"></span><?php esc_html_e('Linked', 'storelinkformc'); ?></span></td>
                                <td>
                                    <div class="storelinkformc-row-actions">
                                        <a class="button" href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php esc_html_e('View user', 'storelinkformc'); ?></a>
                                        <form method="post" class="storelinkformc-unlink-form" data-player="<?php echo esc_attr($minecraft_name); ?>">
                                            <?php wp_nonce_field('storelinkformc_unlink_profile_' . $user->ID); ?>
                                            <input type="hidden" name="storelinkformc_profile_action" value="unlink">
                                            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>">
                                            <button type="submit" class="button storelinkformc-button-danger"><?php esc_html_e('Unlink', 'storelinkformc'); ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($pages > 1) : ?>
                <div class="storelinkformc-pagination">
                    <?php echo wp_kses_post(paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $pages,
                        'prev_text' => __('Previous', 'storelinkformc'),
                        'next_text' => __('Next', 'storelinkformc'),
                    ])); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
