=== MinecraftStoreLink ===
Contributors: mrdinocarlos
Donate link: https://nocticraft.com
Tags: minecraft, woocommerce, delivery, virtual-items, integration, game, shop
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.18
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store with a Minecraft server. Deliver in-game items when an order is completed, using a secure and customizable REST API.

== Description ==

**MinecraftStoreLink** allows you to automatically deliver Minecraft items or execute commands after a WooCommerce purchase is completed.

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
3. Visit **MinecraftStoreLink > Settings** to generate your API token.
4. Configure your Minecraft plugin to use that token and domain.
5. Optional: Configure delivery products and commands in the `products` section of your Minecraft plugin config.
6. Done! Orders from WooCommerce will now sync with Minecraft.

== Frequently Asked Questions ==

= Does this plugin connect directly to Minecraft? =
No. It exposes a secure REST API endpoint that your Minecraft server connects to for fetching pending deliveries.

= Is there a Pro version? =
Yes. The free version supports up to 3 product mappings. The Pro version offers unlimited mappings and additional integrations.

= Does it work with LiteSpeed Cache or WP Rocket? =
Yes. You should exclude the API routes `/wp-json/minecraftstorelink/v1/*` from caching. This ensures fresh data is always returned.

= Can I manage deliveries manually? =
Yes. Use the **Deliveries** admin page to edit, delete or reassign items per player.

== Debugging & Tools ==

The **Settings** page provides:

- 🧪 View current DB table structure.
- ♻️ Rebuild the `pending_deliveries` table.
- 🔑 Regenerate API token.
- 🧹 Flush WordPress object cache.
- 🛠 Access recommendation for WP phpMyAdmin or Adminer for deeper inspection.

== Screenshots ==

1. API token settings page
2. Delivery management table
3. Database rebuild and cache flush tools

== Changelog ==

= Version 1.0.18 =

+ Improved security of all database queries using `$wpdb->prepare()` with proper placeholders.
+ Fixed WP Plugin Checker errors related to unescaped output and missing text domains.
+ Escaped all translatable strings with `esc_html__()` for secure output in admin pages.
+ Deliveries admin page now uses fully prepared SQL statements, eliminating potential SQL injection risks and ensuring full compatibility with WordPress coding standards.
+ Improved code structure for delivery filters (status and player) with safer and cleaner dynamic query building.
+ Minor UI polish on the Pending Deliveries admin table for clarity and consistency.

= Version 1.0.16 =

+ Added automatic WordPress role assignment when a Minecraft account is linked.
+ New admin page “Sync Roles” to:
  + Select a default role for newly linked Minecraft accounts.
  + Create custom WordPress roles directly from the plugin.
  + Map WooCommerce products to specific roles (per-product role sync).
+ Roles are now removed if the user unlinks their Minecraft account.
+ Roles assigned by product purchase are now revoked if the order is cancelled, refunded, or fails.
+ Added [minecraftstorelink_account_sync] shortcode to display current Minecraft link status and unlink button (AJAX powered).


= Version 1.0.14 =

+ Added REST API endpoints for /request-link and /verify-link.
+ Sends verification code to user's email to confirm Minecraft ↔ WordPress link.
+ Links are now stored securely as user_meta (minecraft_player) only after validation.
+ Locked profile fields: user display name, first name, and nickname now reflect Minecraft username once linked.
+ Added checkout-fields-page admin panel to choose which WooCommerce fields appear at checkout.
+ Automatically inserts Minecraft username into checkout form (read-only if linked).
+ Minecraft username now appears in WooCommerce emails and admin order view.
+ Improved REST delivery endpoints with token protection and response formatting.


= 1.0.13 =
* Fixed issue with infinite delivery loop.
* Added protection for duplicated entries.
* Added tools to inspect and rebuild the delivery table.
* Improved compatibility with caching plugins.
* Added admin notices and debug options.

= 0.5.0 =
* Initial public release.
* Secure REST API for Minecraft integration.
* Basic delivery management interface.

== Upgrade Notice ==

= 1.0.13 =
Security, reliability and toolset update. Strongly recommended for all users.

== License ==

This plugin is open-source software licensed under the GPL v2 or later.
