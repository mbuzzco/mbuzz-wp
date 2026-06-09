# mbuzz-wp

WordPress plugin for the [Mbuzz](https://mbuzz.co) multi-touch attribution platform. Wraps the [mbuzz/mbuzz-php](https://github.com/mbuzzco/mbuzz-php) SDK with WordPress-native hooks: WooCommerce, EDD, form plugins, identity, consent, multisite.

Ships to WP.org under the slug **`mbuzz-attribution`** (the user-facing plugin name) — `mbuzz-wp` is just the repo name, matching the `mbuzz-php` / `mbuzz-ruby` SDK repos.

**Status: 0.3.0-alpha.** Server-side sessions, opt-in Contact Form 7 field mapping, embedded/third-party form capture (a first-party REST endpoint + JS helper), identity stitching, WooCommerce conversions, WP Consent API gating, and the Privacy API exporter/eraser are wired and verified end-to-end. The remaining form-plugin adapters, php-scoper build, and WP.org packaging are in progress. Full design lives in `lib/specs/`.

## Getting started

mbuzz tracks the whole marketing journey **server-side** — every page view becomes a touchpoint, and form submissions and purchases become attributed conversions — with no JS pixel required. The transaction itself can even happen in another system (a CRM or billing system): as long as it later reports the conversion with the same email, mbuzz links it back to the marketing journey this plugin captured.

### Requirements

- WordPress 6.5+ and PHP 8.1+
- A [mbuzz](https://mbuzz.co) account and an API key — use an `sk_test_…` key to trial, `sk_live_…` for production

### 1. Install the plugin

Until the WordPress.org listing is live, install from the release zip:

1. Download the latest `mbuzz-attribution.zip` from [GitHub releases](https://github.com/mbuzzco/mbuzz-wp/releases).
2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the zip, **Install Now**, then **Activate**.

Building from source instead? Run `composer install --no-dev -o` and zip the plugin folder — `vendor/` must be included.

### 2. Add your API key

Open the **Mbuzz** menu in your wp-admin sidebar (funnel icon), paste your key into the **API key** field, and **Save Changes**.

Start with an `sk_test_*` key and confirm everything flows into the **Test** view of your dashboard, then switch to `sk_live_*`. Managed hosts and multisite can instead lock the key via a `wp-config.php` constant (`define('MBUZZ_API_KEY', 'sk_live_…');`), which the settings field then shows as locked.

### 3. That's it — here's what's tracked automatically

| What | How |
|---|---|
| **Page touchpoints** | Every front-end page view creates a server-side session (channel, referrer, UTM). Logged-in admins are excluded by default. |
| **Contact Form 7 leads** | **Opt-in, per-form.** Open a form's **Mbuzz** tab and map each field (user ID / identity trait / property / revenue / currency / ignore) and whether the form records a conversion or an event. Nothing fires until a form is configured — sensitive fields (children, DOBs) default to *ignore*. |
| **Embedded / third-party forms** | Forms that submit in the browser to another system (a CRM or scheduling widget) and never reach WordPress. A small JS helper, `window.mbuzz.captureLead(...)`, POSTs the lead to a first-party endpoint that resolves the visitor server-side — see **Custom tracking** below. |
| **Identity** | WordPress logins and registrations link the visitor to a known user. |
| **WooCommerce** | Purchases, refunds, and first-order acquisition (when WooCommerce is active). |

### 4. Verify

Submit one of your configured forms (logged out, so you aren't excluded as an admin), then open your mbuzz dashboard (the **Test** view if you used an `sk_test_*` key) — you should see the new session, an `identify`, and the form's event/conversion. Add `define('MBUZZ_DEBUG', true);` to log every API call to `wp-content/debug.log` while testing.

### Custom tracking (themes & snippets)

Three procedural helpers are available anywhere in your theme or a snippet plugin (server-side PHP):

```php
mbuzz_event('brochure_download', ['guide' => 'fees-2026']);
mbuzz_conversion('enquiry', ['user_id' => $email]);
mbuzz_identify($email, ['first_name' => $first, 'phone' => $phone]);
```

### Capturing an embedded / third-party form (client-side)

Some forms render and submit entirely in the browser to another company's domain (a CRM or scheduling widget) and never reach WordPress, so the server-side hooks above can't see them. For those, call the JS helper on the form's **success** callback. It POSTs to a first-party endpoint (`/wp-json/mbuzz/v1/lead`) that resolves the visitor from the (HttpOnly) `_mbuzz_vid` cookie server-side and fires `identify` + the event — so the lead still attributes to the visitor's journey, with no JavaScript access to the cookie:

```js
window.mbuzz.captureLead({
  type: 'lead',                         // event name (sanitized slug); defaults to 'lead'
  user_id: emailFromTheForm,            // the cross-system join key (a non-PII ID is preferred)
  traits: { phone: phoneFromForm, first_name: firstFromForm },
  properties: { plan: 'starter', external_lead_id: vendorLeadId }
});
```

Notes: call it only on genuine success (not on every attempt); the plugin captures only what you pass (it never scrapes field values); the endpoint accepts same-origin requests only; and `window.mbuzz` is provided by the plugin's front-end helper (loaded when an API key is set).

## Repo layout

```
mbuzz-attribution/
├── mbuzz-attribution.php       # WP plugin bootstrap (headers, autoload, PHP-floor guard)
├── composer.json               # Pulls mbuzz/mbuzz-php ^1.2
├── readme.txt                  # WP.org format
├── uninstall.php               # Option + transient cleanup
├── assets/js/mbuzz-capture.js  # window.mbuzz.captureLead — embedded-form helper
├── src/
│   ├── helpers.php             # mbuzz_event/conversion/identify — scope-stable theme surface
│   ├── Plugin.php              # Singleton, hook registration, switch_blog handling
│   ├── Bootstrap.php           # boot(settings): Mbuzz::init + onSuccess/onError wiring
│   ├── Tracking/               # Domain engine: FieldMap, ResolvedHit, AutoDetector, TrackingEngine
│   ├── Integrations/           # FormSource + Cf7FormSource/ContactForm7, WooCommerce
│   ├── Settings/               # Admin: Page, ConversionsPage, Cf7EditorPanel + presenters/sanitizers
│   ├── Rest/                   # LeadController + LeadRequest (first-party embed endpoint)
│   ├── Identity/               # wp_login, user_register, profile_update hooks
│   ├── Privacy/                # Consent (WP Consent API) + PersonalData (exporter/eraser)
│   ├── Visitor/                # CookieBootstrap — mints _mbuzz_vid server-side
│   └── Support/                # View (template loader), Links
├── templates/admin/            # Escaped-output views (CF7 panel, conversions overview)
└── tests/Unit/                 # PHPUnit + Brain Monkey
```

## Local development

```sh
composer install
composer test:unit
```

To run against a live WordPress, symlink the plugin into a `wp-env` install:

```sh
# In the mbuzz-attribution directory
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/mbuzz-attribution
```

### Dev environment (wp-env)

A `.wp-env.json` is included — it boots WordPress with this plugin **and Contact
Form 7** preloaded, `WP_DEBUG_LOG` on, and `MBUZZ_DEBUG` on. Requires Docker +
[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env).

```sh
# Put your sk_test_ key where it won't be committed (.wp-env.override.json is gitignored):
cp .wp-env.override.json.example .wp-env.override.json
# edit .wp-env.override.json → set MBUZZ_API_KEY to your sk_test_ key

npx wp-env start          # http://localhost:8888  (wp-admin: admin / password)
```

Verify a lead end-to-end:

1. Create a CF7 form (Contact → Forms) with an email field and embed it on a page.
2. Load the page (mints `_mbuzz_vid`, creates a session) and submit the form.
3. Check the conversion landed: the mbuzz dashboard for the test account, or
   `npx wp-env run cli wp eval 'var_dump(get_transient("mbuzz_attribution_last_call"));'`.
   On failure, `MBUZZ_DEBUG` logs the API error to `wp-content/debug.log`.

## Configuration

Configure in **Mbuzz → Settings** (API key + toggles). Managed hosts / multisite can override via `wp-config.php` constants instead, which the settings screen then shows as locked:

```php
define('MBUZZ_API_KEY', 'sk_live_…');
define('MBUZZ_ENABLED', true);
define('MBUZZ_DEBUG', false);
```

## What's left

Shipped and planned (design specs live in `lib/specs/`):

- [x] Plugin bootstrap, autoload, PHP-floor guard (§2, §3)
- [x] Settings storage + wp-config.php overrides (§4)
- [x] Switch_blog reset + re-init for multisite (§10)
- [x] Scope-stable theme helpers (§2)
- [x] onSuccess/onError observers wired to diagnostics transient (§4)
- [x] Identity hooks: wp_login, user_register (+ signup conversion), profile_update (§5)
- [x] WooCommerce: thankyou + processing + completed dedupe, refunds, first-paid detection, HPOS-safe meta, cookieless guest attribution via billing email (§6)
- [x] Server-side visitor bootstrap: plugin mints `_mbuzz_vid` on `template_redirect` so sessions/touchpoints record without a JS pixel (the SDK never mints it itself)
- [x] `track_admins` gate honored in the request path — logged-in admins bypass tracking by default (§4)
- [x] Contact Form 7: opt-in per-form field mapping in the form editor's Mbuzz tab (role per field; conversion or event); replaces the old auto-fire (§7)
- [x] Settings page UI: API key field, enable/track-admins/debug toggles, diagnostics card — key settable in wp-admin, no wp-config edit needed (§4)
- [x] Conversions overview screen + Mbuzz menu hub
- [x] WP Consent API gating across every capture surface (§11)
- [x] Privacy API exporter + eraser (§11)
- [x] Embedded / external form capture: first-party REST endpoint (`/wp-json/mbuzz/v1/lead`) + `window.mbuzz.captureLead()` JS helper
- [ ] WooCommerce Subscriptions renewal hook (§6 — deferred, paid extension)
- [ ] Other plugin integrations: EDD, Gravity, WPForms, Fluent, MemberPress, LearnDash (§7)
- [ ] Tracked Button Gutenberg block (§8)
- [ ] WP-CLI commands: status, test, flush, identify, conversion (§9)
- [ ] php-scoper build pipeline (§2)
- [ ] WP.org submission (§14)

## License

GPL-2.0-or-later. See WordPress.org plugin guidelines.
