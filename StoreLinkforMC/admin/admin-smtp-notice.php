<?php
defined( 'ABSPATH' ) || exit;

/**
 * SMTP notice for StoreLink for MC.
 */

// Define the settings page slug once.
if ( ! defined( 'SLMC_SETTINGS_PAGE_SLUG' ) ) {
	define( 'SLMC_SETTINGS_PAGE_SLUG', 'storelinkformc' );
}

// Heuristic: is SMTP configured?
if ( ! function_exists( 'slmc_is_smtp_configured' ) ) {
	function slmc_is_smtp_configured() {
		// 1) WP Mail SMTP plugin (most common).
		if ( class_exists( '\\WPMailSMTP\\Options' ) ) {
			$opts = \WPMailSMTP\Options::init();
			if ( is_object( $opts ) ) {
				$mailer = $opts->get( 'mail', 'mailer' );
				if ( ! empty( $mailer ) && 'mail' !== $mailer ) {
					return true;
				}
			}
		}

		// 2) Stored WP Mail SMTP options.
		$wpms = get_option( 'wp_mail_smtp' );
		if ( is_array( $wpms ) && ! empty( $wpms['mail']['mailer'] ) && 'mail' !== $wpms['mail']['mailer'] ) {
			return true;
		}

		// 3) Legacy SMTP constants.
		if ( defined( 'SMTP_HOST' ) && defined( 'SMTP_PORT' ) ) {
			return true;
		}

		// 4) Any plugin hooking phpmailer_init usually forces SMTP.
		if ( has_action( 'phpmailer_init' ) ) {
			return true;
		}

		return false;
	}
}

// Handle notice dismissal (per user).
add_action(
	'admin_init',
	function () {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( isset( $_GET['slmc_dismiss_smtp'], $_GET['_wpnonce'] ) ) {
			$dismiss_raw = wp_unslash( $_GET['slmc_dismiss_smtp'] );
			$nonce_raw   = wp_unslash( $_GET['_wpnonce'] );

			$dismiss = sanitize_text_field( $dismiss_raw );
			$nonce   = sanitize_text_field( $nonce_raw );

			if ( '1' === $dismiss && wp_verify_nonce( $nonce, 'slmc_dismiss_smtp' ) ) {
				update_user_meta( get_current_user_id(), 'slmc_dismiss_smtp_notice', 1 );
				wp_safe_redirect(
					remove_query_arg(
						array(
							'slmc_dismiss_smtp',
							'_wpnonce',
						)
					)
				);
				exit;
			}
		}
	}
);

// Show the notice only on the plugin settings page in admin.
add_action(
	'admin_notices',
	function () {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$on_plugin_page = ( isset( $_GET['page'] ) && SLMC_SETTINGS_PAGE_SLUG === $_GET['page'] );
		if ( ! $on_plugin_page ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), 'slmc_dismiss_smtp_notice', true ) ) {
			return;
		}

		$force_linking = ( get_option( 'storelinkformc_force_link', 'no' ) === 'yes' );

		if ( $force_linking && ! slmc_is_smtp_configured() ) {
			$dismiss_url = wp_nonce_url( add_query_arg( 'slmc_dismiss_smtp', '1' ), 'slmc_dismiss_smtp' );

			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>' . esc_html__( 'StoreLink for MC:', 'storelinkformc' ) . '</strong> ';
			echo esc_html__( 'It looks like your site does not have an authenticated SMTP configured. With Force email linking enabled, some users might not receive the verification code due to WordPress/hosting limits or spam filters.', 'storelinkformc' ) . '</p>';
			echo '<p>' . esc_html__( 'Recommended: install and configure an SMTP plugin (e.g. WP Mail SMTP) and add SPF/DKIM to your domain.', 'storelinkformc' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Set up SMTP', 'storelinkformc' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Got it, don’t show again', 'storelinkformc' ) . '</a></p>';
			echo '</div>';
		}
	}
);
