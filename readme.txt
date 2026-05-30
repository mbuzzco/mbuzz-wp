=== Mbuzz Attribution ===
Contributors: mbuzz
Tags: attribution, analytics, woocommerce, marketing, conversion-tracking
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.2-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side multi-touch attribution for WordPress and WooCommerce.

== Description ==

Mbuzz Attribution wires WordPress and WooCommerce to the Mbuzz attribution
platform. Conversions, sessions, and identity events flow server-side —
no extra JavaScript on your pages beyond the existing pixel.

= Highlights =

* Zero-config tracking once you paste your API key.
* WooCommerce purchases, refunds, and subscription renewals.
* Identity stitching: links logged-in users to their anonymous sessions.
* Built-in conversions for Contact Form 7, Gravity Forms, WPForms, Fluent Forms.
* Easy Digital Downloads, MemberPress, LearnDash supported.
* WP-CLI commands for status, test, identify, conversion.
* WP Consent API aware.
* Zero added latency on rendered pages — all API I/O happens after the
  response is sent to the browser.

= Requirements =

* WordPress 6.5+
* PHP 8.1+
* WooCommerce 8.x+ (only if you use the WooCommerce integration)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/mbuzz-attribution/`.
2. Activate it through the **Plugins** screen.
3. Open the **Mbuzz** menu in the wp-admin sidebar and paste your API key.

== Frequently Asked Questions ==

= Does this replace the JavaScript pixel? =

No. The pixel still handles client-side signals (scroll depth, time-on-page)
that server-side hooks can't see. This plugin handles server-side events:
conversions, identity, sessions.

= Where's the reporting dashboard? =

Reporting lives at app.mbuzz.co. This plugin handles capture and configuration
only.

== Changelog ==

= 0.1.2-alpha =
* Top-level **Mbuzz** menu in the wp-admin sidebar (funnel icon) so the settings screen is easy to find, instead of buried under Settings.

= 0.1.1-alpha =
* Settings → Mbuzz screen: API key field, enable/track-admins/debug toggles, diagnostics card. Key can now be set in wp-admin (no wp-config edit required).

= 0.1.0-alpha =
* Server-side visitor bootstrap, page-session touchpoints, Contact Form 7 lead conversions with identity stitching, WooCommerce conversions, identity hooks.
