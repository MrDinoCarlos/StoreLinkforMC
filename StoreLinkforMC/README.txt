=== StoreLink for Minecraft by MrDino ===
Contributors: mrdinocarlos
Donate link: https://buymeacoffee.com/mrdino
Tags: minecraft, woocommerce, delivery, game, shop
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.35
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

= Version 1.0.35 =

- Added WooCommerce product variation support, allowing synced deliveries and role mappings to target specific variations instead of requiring separate products.
- Added product and variation identifiers to pending deliveries so the Minecraft plugin can keep compatibility while receiving more precise delivery data.
- Added unclaimed delivery expiration settings, with a default of 30 days for products that have not been claimed.
- Added automatic expiration tracking for unclaimed pending deliveries through `expires_at` and `expired` database fields.
- Added database self-healing and update routines to create or update required delivery columns without requiring a full reinstall.
- Added safer admin handling for delivery management actions, product selection, role mapping, checkout fields, CDN/cache settings, and email templates.
- Added Cloudflare CDN/cache compatibility improvements for StoreLinkforMC REST endpoints.
- Fixed fatal errors caused by duplicate plugin loading by adding a defensive plugin load guard.
- Fixed fatal errors when WooCommerce functions such as `is_checkout()` or `wc_get_order()` are unavailable.
- Fixed the SMTP admin notice include path so the correct admin notice file is loaded.
- Fixed undefined Cloudflare settings variables before saving Zone ID and API Token values.
- Fixed PHP compatibility by allowing nullable array request bodies in the Cloudflare API helper.
- Fixed Mojang username checks to handle `WP_Error` responses from failed remote requests.
- Fixed duplicated AJAX unlink handlers by keeping a single account unlink handler.
- Fixed text-domain formatting and translation domain usage to comply with WordPress Plugin Check requirements.
- Fixed unsafe or noisy Plugin Check warnings around sanitized `$_GET` and `$_POST` handling.
- Fixed direct database query warnings where the plugin needs to work with its custom pending deliveries table.
- Fixed delivery completion logic so WooCommerce order status updates remain guarded and compatible.
- Fixed legacy short expiration values so old 10-second settings are corrected back to the 30-day default.
- Removed the duplicate account unlink AJAX block from the main plugin file.
- Removed outdated unsafe input handling patterns that caused WordPress Plugin Check warnings.
- Removed the invalid uppercase text-domain value from the plugin header and translation calls.


== License ==

This plugin is open-source software licensed under the GPL v2 or later.
