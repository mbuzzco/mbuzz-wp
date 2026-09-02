=== Mbuzz Attribution ===
Contributors: mbuzz
Tags: attribution, analytics, woocommerce, marketing, conversion-tracking
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.6.1-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side multi-touch attribution for WordPress and WooCommerce.

== Description ==

Mbuzz Attribution wires WordPress and WooCommerce to the Mbuzz attribution
platform. Conversions, sessions, and identity events flow server-side — no
JavaScript pixel required. (One small optional helper script loads only if you
choose to capture embedded/third-party forms; see the changelog.)

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

= 0.6.1-alpha =
* Fixed: a value list added through "Add a field that isn't listed" was discarded when you saved. The field itself was kept, so the form sent the other plugin's own wording as the event name until you saved a second time. Fields added that way now behave exactly like the ones detected automatically.

= 0.6.0-alpha =
* **One form can now send different event names depending on what the visitor did.** Some plugins render a form in more than one mode — an enquiry and a booking, say — and post which mode it was as a hidden field. Give that field the "Event / conversion name" role and list what each of its values should be called, one `posted value = event name` per line. A value you haven't listed falls back to the form's own event name, so a change on the other plugin's side can never silently rename your events.

= 0.5.0-alpha =
* **Tracking now works on sites behind a full-page cache.** Cloudflare, WP Rocket, Varnish, LiteSpeed and most managed hosts serve pages without running PHP, so nothing established the visitor and every form submission from a cached page was silently discarded. Pages no longer set the visitor cookie at all — a small first-party request does, on a route caches never store. Your identity cookie is still created by the server, stays HttpOnly, and keeps its full two-year life.
* Fixed: on a cache that stores cookies, every visitor could be given the same ID and appear as one person. Cached pages carry no cookie now, so this cannot happen.

= 0.4.5-alpha =
* Diagnostics now report when the visitor cookie could not be set at all. If something on a page sends output before mbuzz can set the cookie, every submission from that page is dropped — previously with no explanation anywhere.

= 0.4.4-alpha =
* Diagnostics now distinguish a submission that was sent from one the SDK dropped. An event with no visitor to attribute it to is never sent — the plugin previously reported it as sent anyway.

= 0.4.3-alpha =
* Diagnostics now show the **last form submission** the site saw and what became of it — sent, or the reason it wasn't: no mapping, tracking off for that form, consent withheld, or Contact Form 7 reporting the submission didn't complete. A form that silently fires nothing is otherwise indistinguishable from one whose call was rejected.

= 0.4.2-alpha =
* Fixed: 0.3.2 stopped listing Conditional Fields groups in the mapping table. The scanned field list also determines what a save keeps, so saving a form after that release could drop fields from its mapping. Only the submit button is excluded again.

= 0.4.1-alpha =
* Fixed: submissions were only tracked when Contact Form 7 managed to send the notification email. A form whose mail is switched off or misconfigured — common when a CRM handles the lead instead — submitted fine and was never recorded. Submissions are now tracked whenever the form is accepted and processed, regardless of what happens to the email. Spam, validation failures and aborted submissions are still ignored.

= 0.4.0-alpha =
* New **Event / conversion name** field role. A form that submits in more than one mode — an enquiry now, a tour booking later, from the same form — can name each submission from a field's value instead of being locked to one name. Leave the field out of a submission and the form's configured name is used.
* You can now map a field that isn't listed. Some plugins add their own hidden inputs to a form; they're submitted with it but aren't Contact Form 7 fields, so they never appeared in the mapping table. Enter the input's name to map it.

= 0.3.2-alpha =
* Fixed: the "mbuzz name" box was hidden on every row, including fields that had a name saved. Nothing was lost — the values were still stored — but a mapped field looked unmapped. The panel now leaves a name visible unless it is certain the role doesn't use one.
* Layout containers are no longer listed as mappable fields. Conditional Fields groups (group-1, group-2) and multi-step markers (cf7mls_step-1) are form structure, not data, so they no longer clutter the mapping table.

= 0.3.1-alpha =
* Fixed: on Contact Form 7 6.1 and later, the mapping panel's JavaScript was displayed as text at the bottom of the Mbuzz tab instead of running. CF7 now filters editor-panel markup and removes inline scripts, so the panel's behaviour ships as a proper asset. The "mbuzz name" box once again shows and hides as you change a field's role.

= 0.3.0-alpha =
* Embedded / external form capture: a first-party endpoint and a small JavaScript helper (window.mbuzz.captureLead) let you report a lead from forms that submit in the browser to a third party (e.g. an embedded CRM or scheduling widget) and never reach WordPress. The visitor is resolved server-side, so attribution still works with no JavaScript access to the visitor cookie.
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
