=== Mbuzz Attribution ===
Contributors: mbuzz
Tags: attribution, analytics, woocommerce, marketing, conversion-tracking
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.3.0-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side multi-touch attribution for WordPress and WooCommerce.

== Description ==

Mbuzz Attribution wires WordPress and WooCommerce to the Mbuzz attribution
platform. Conversions, sessions, and identity events flow server-side —
no extra JavaScript on your pages beyond the existing pixel.

= Highlights =

* Server-side capture — sessions, identity, conversions — no JavaScript pixel required.
* Per-form, opt-in tracking: map each Contact Form 7 field to mbuzz (identity, conversion, or event). Nothing fires until you configure a form.
* Identity stitching: links a chosen user ID to the visitor and their prior anonymous sessions. A non-PII unique ID is preferred; the plugin never assumes one.
* WooCommerce purchases and refunds.
* Conversions overview: see at a glance which forms are tracked and what they send.
* Privacy-first: WordPress Privacy API exporter + eraser, and WP Consent API gating (defaults to the "marketing" category). Sensitive fields default to "ignore".
* Zero added latency on rendered pages — API I/O happens after the response is sent to the browser.

= Requirements =

* WordPress 6.5+
* PHP 8.1+
* WooCommerce 8.x+ (only if you use the WooCommerce integration)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/mbuzz-attribution/`.
2. Activate it through the **Plugins** screen.
3. Open **Mbuzz → Settings** in the wp-admin sidebar and paste your API key.
4. Open a Contact Form 7 form, switch to its **Mbuzz** tab, and map the fields you want to track.

== Frequently Asked Questions ==

= Does this replace the JavaScript pixel? =

No. The pixel still handles client-side signals (scroll depth, time-on-page)
that server-side hooks can't see. This plugin handles server-side events:
conversions, identity, sessions.

= Where's the reporting dashboard? =

Reporting lives at app.mbuzz.co. This plugin handles capture and configuration
only.

== Changelog ==

= 0.3.0-alpha =
* Embedded / external form capture: a first-party endpoint and a small JavaScript helper (window.mbuzz.captureLead) let you report a lead from forms that submit in the browser to a third party (e.g. a booking widget) and never reach WordPress. The visitor is resolved server-side, so attribution still works with no JavaScript access to the visitor cookie.
* Clearer Contact Form 7 mapping panel: the "mbuzz name" field is now hidden for roles that don't use one (user ID, revenue, currency, ignore).

= 0.2.0-alpha =
* Opt-in per-form field mapping for Contact Form 7: set each field's role (user ID, identity trait, property, revenue, currency, or ignore) and whether the form records a conversion or an event. Replaces the previous auto-fire behaviour — tracking now stays off until a form is configured.
* New **Mbuzz → Conversions** overview listing every form, its tracking status, and its field mapping; the Mbuzz menu is now a hub with Conversions and Settings.
* Identity now fires whenever a form maps a user ID (traits optional), for both conversions and events.
* Privacy: WordPress Privacy API personal-data exporter and eraser.
* Consent: WP Consent API gating on every capture surface (sessions, forms, identity, WooCommerce, theme helpers); defaults to the "marketing" category, filterable.
* Fixed the plugin version reported on the diagnostics card.

= 0.1.2-alpha =
* Top-level **Mbuzz** menu in the wp-admin sidebar (funnel icon) so the settings screen is easy to find, instead of buried under Settings.

= 0.1.1-alpha =
* Settings → Mbuzz screen: API key field, enable/track-admins/debug toggles, diagnostics card. Key can now be set in wp-admin (no wp-config edit required).

= 0.1.0-alpha =
* Server-side visitor bootstrap, page-session touchpoints, Contact Form 7 lead conversions with identity stitching, WooCommerce conversions, identity hooks.
