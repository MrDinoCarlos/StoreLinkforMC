=== StoreLink for Minecraft by MrDino ===
Contributors: mrdinocarlos
Donate link: https://buymeacoffee.com/mrdino
Tags: minecraft, woocommerce, delivery, virtual-items, integration, game, shop
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.27
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

= Version 1.0.27 =

- Added automatic database table creation on plugin activation.
- Added silent self-repair routine to recreate missing tables when detected.
- Added automatic replacement of WooCommerce Checkout with the `[woocommerce_checkout]` shortcode on activation for full compatibility.
- Added manual "Rebuild Tables" and "Force Classic Checkout" buttons in the plugin settings page.
- Added localized greeting and delivery message on the order confirmation page, showing the player’s Minecraft username and delivery notice for synced or gifted items.
- Improved Minecraft checkout field behavior:
  - Field now becomes editable only when “🎁 This is a gift” is selected.
  - Field automatically clears when gifting and shows proper placeholder.
  - Dynamic label updates to reflect “required”, “auto-linked”, or “disabled” states.
- Improved JavaScript logic to reapply checkout field state after WooCommerce AJAX refresh.
- Improved English UI and help texts across admin pages and checkout labels for clarity.
- Fixed optional/required inconsistencies in the Minecraft username field under different user states.
- Fixed delivery confirmation text not showing the recipient name for gifted purchases.
- Fixed minor layout inconsistencies in admin settings sections.
- Changed internal script enqueue logic for better compatibility with recent WooCommerce versions.
- Changed activation routine to automatically enforce classic checkout template when using block-based checkout.
- Cleaned up code structure and minor performance optimizations.



== License ==

This plugin is open-source software licensed under the GPL v2 or later.
