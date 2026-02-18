=== PipraPay Gateway ===
Contributors: piprapay
Tags: payment, gateway, WooCommerce, piprapay
Tested up to: 6.8
WC requires at least: 3.0
WC tested up to: 8.0
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A seamless and secure payment gateway integration for WooCommerce using PipraPay.

== Description ==

PipraPay Gateway provides an easy, secure, and reliable way to accept payments on your WooCommerce store. Features include:

- Integration with the PipraPay API
- Customizable payment settings
- Configurable checkout logo
- Secure webhook handling
- Order payment verification
- Compatibility with WooCommerce High-Performance Order Storage (HPOS)
- Compatibility with WooCommerce block-based checkout

Supported Gateways:

- bKash
- Rocket
- Nagad
- Upay
- Cellfin
- Tap
- Ok Wallet
- SSLCommerz
- Paypal
- Stripe
- Binance
- etc

== Installation ==

1. Upload the `piprapay-gateway` directory to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **WooCommerce > Settings > Payments** and enable "PipraPay Gateway".
4. Configure your API key, logo settings, and other options.

== Frequently Asked Questions ==

= What is PipraPay? =
PipraPay is a secure payment gateway that supports multiple currencies and payment methods.

= How do I configure the plugin? =
Go to **WooCommerce > Settings > Payments** and click on "Manage" under PipraPay Gateway.

= Can I customize the checkout logo? =
Yes, administrators can set a custom logo URL or upload an image via the media library.

= Is my data secure? =
Yes, all payment data is processed securely through the PipraPay API.

= Is this plugin compatible with WooCommerce HPOS? =
Yes, this plugin is fully compatible with WooCommerce High-Performance Order Storage (HPOS).

= Does this plugin support WooCommerce block-based checkout? =
Yes, the plugin supports WooCommerce block-based checkout, ensuring a seamless experience in the modern checkout flow.

== External Services ==

This plugin connects to the PipraPay API to process payments securely. The following data is sent to PipraPay:

- Customer name, email, and phone number
- Order amount and currency
- API key for authentication

This data is sent only during the payment process and is necessary to complete the transaction.

For more information, please review the PipraPay:
- [Terms of Service](https://piprapay.com/terms)
- [Privacy Policy](https://piprapay.com/privacy)

== Changelog ==

= 1.0.0 =
* Initial release

= 1.0.1 =
* Solved Order Status Update Issue
* Include Supported Gateways

= 1.0.2 =
* Fixed issue where pending orders incorrectly moved to failed status

= 1.0.3 =
* Added support for PipraPay 3.0.0
* Show / Hide icon option on checkout page
* Direct Settings link from the Plugins page
* Automatic order status handling for Digital Products (set to Processing)

= 1.0.4 =
* Added dynamic order currency support for all PipraPay versions
* Fixed URL whitelist issue in V3+
* Resolved minor bugs and improvements

== Upgrade Notice ==

= 1.0.0 =
Initial release

= 1.0.1 =
Solved Order Status Update Issue
Include Supported Gateways

= 1.0.2 =
Fixed issue where pending orders incorrectly moved to failed status

= 1.0.3 =
- Added support for PipraPay 3.0.0
- Show / Hide icon option on checkout page
- Direct Settings link from the Plugins page
- Automatic order status handling for Digital Products (set to Processing)

= 1.0.4 =
- Added dynamic order currency support for all PipraPay versions
- Fixed URL whitelist issue in V3+
- Resolved minor bugs and improvements