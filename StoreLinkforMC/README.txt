=== StoreLink for Minecraft by MrDino ===
Contributors: mrdinocarlos
Donate link: https://buymeacoffee.com/mrdino
Tags: minecraft, woocommerce, delivery, virtual-items, integration, game, shop
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.28
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store with a Minecraft server. Deliver in-game items when an order is completed, using a secure and customizable REST API.

== Description ==

**StoreLink for Minecraft** allows you to automatically deliver Minecraft items or execute commands after a WooCommerce purchase is completed.

Perfect for store owners who sell in-game items or ranks. This plugin connects your website to your Minecraft server securely, reliably, and easily.

**Main Features:**

- 🔗 Sync WooCommerce products with Minecraft items or commands.
- 🚀 Auto-delivery on order completion.
- 🔒 API token authentication for secure access.
- 📦 Delivery queue management with a pending/delivered system.
- 🛠 Admin tools for rebuilding database, viewing table structure, and debugging.
- 🔧 Supports LiteSpeed / WP Rocket / caching plugins safely.
- 🌍 Full REST API support.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install directly from the plugin repository.
2. Activate the plugin via the ‘Plugins’ screen in WordPress.
3. Visit **StoreLink for MC > Settings** to generate your API token.
4. Configure your Minecraft plugin to use that token and domain.
5. Optional: Configure delivery products and commands in the `products` section of your Minecraft plugin config.
6. Done! Orders from WooCommerce will now sync with Minecraft.

== Frequently Asked Questions ==

= Does this plugin connect directly to Minecraft? =
No. It exposes a secure REST API endpoint that your Minecraft server connects to for fetching pending deliveries.

= Is there a Pro version? =
Yes. The free version supports up to 3 product mappings. The Pro version offers unlimited mappings and additional integrations.

= Does it work with LiteSpeed Cache or WP Rocket? =
Yes. You should exclude the API routes `/wp-json/storelinkformc/v1/*` from caching. This ensures fresh data is always returned.

= Can I manage deliveries manually? =
Yes. Use the **Deliveries** admin page to edit, delete or reassign items per player.

== Debugging & Tools ==

The **Settings** page provides:

- 🧪 View current DB table structure.
- ♻️ Rebuild the `pending_deliveries` table.
- 🔑 Regenerate API token.
- 🧹 Flush WordPress object cache.
- 🛠 Access recommendation for WP phpMyAdmin or Adminer for deeper inspection.


== Changelog ==

= Version 1.0.28 =

- Added new “Force Minecraft account linking” option in plugin settings (enabled by default).
- Added ability to disable forced linking, replacing the “Gift” option with a direct Minecraft Username field at checkout.
- Added unified order metadata using `_slmc_target_type` (`linked`, `gift`, or `manual_username`) and a consistent `_minecraft_username` value.
- Added server-side Minecraft username verification respecting the “Premium / Any” policy, including PlayerDB lookup for Premium-only mode.
- Added detailed logging around /request-link API requests and wp_mail() results for easier debugging.
- Added friendly API response when the player’s email is not registered on WordPress (no longer silent).
- Added new Email Templates admin page with full visual editor (TinyMCE + Media Library support).
- Added support for customizable email subject and HTML body with placeholders: {site_name}, {user_email}, {verify_code}, {link_url}, and {player}.
- Added default responsive HTML email template with inline styles for better client compatibility.
- Added “Insert default” and “Reset to defaults” buttons for quick template management.
- Improved checkout UI and logic:
  - Field is now always visible and required when linking is disabled or a gift is selected.
  - Gift option automatically hides when force linking is off.
  - JavaScript updated to respect the new `force_link` flag and reduce field flicker.
  - Username is now prefilled and readonly when a linked account is detected.
- Improved “Thank You” page message:
  - Uses `_slmc_target_type` to correctly show delivery recipient.
  - Adds delivery info for both gift and manual username orders.
- Improved admin and email order views to always prioritize the order’s `_minecraft_username` over the user’s linked account.
- Improved delivery generation logic to use `_slmc_target_type` with full backward compatibility for `_minecraft_gift`.
- Improved error logging with player, email, and IP details for better traceability.
- Improved email sending logic to automatically replace placeholders and support {player} in messages.
- Improved content sanitization and HTML whitelisting to allow safe use of tables, inline styles, and images.
- Fixed checkbox in settings to correctly store “no” value when unchecked.
- Fixed missing bracket issue in `register_setting`.
- Fixed minor layout inconsistencies in checkout username field.
- Fixed issue where /request-link would silently fail if the WordPress user didn’t exist.
- Fixed potential email-sending failures now clearly logged to debug.log.
- Fixed email delivery issue by adding explicit From header and HTML content type in wp_mail().
- Fixed Cloudflare IP handling in rate-limiting to prevent false 429 errors.
- Cleaned up and optimized internal logic for better maintainability and WooCommerce compatibility.
- Verified successful email sending and linking flow between Minecraft and WordPress.


== License ==

This plugin is open-source software licensed under the GPL v2 or later.
