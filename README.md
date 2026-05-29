# mbuzz-wp

WordPress plugin for the [Mbuzz](https://mbuzz.co) multi-touch attribution platform. Wraps the [mbuzz/mbuzz-php](https://github.com/mbuzzco/mbuzz-php) SDK with WordPress-native hooks: WooCommerce, EDD, form plugins, identity, consent, multisite.

Ships to WP.org under the slug **`mbuzz-attribution`** (the user-facing plugin name) — `mbuzz-wp` is just the repo name, matching the `mbuzz-php` / `mbuzz-ruby` SDK repos.

**Status: 0.1.0-alpha — scaffold only.** Wiring per `lib/specs/wordpress-plugin.md` (in the SDK repo) is in progress; see the spec for the full design and the per-section TODOs below.

## Repo layout

```
mbuzz-attribution/
├── mbuzz-attribution.php       # WP plugin bootstrap (headers, autoload, PHP-floor guard)
├── composer.json               # Pulls mbuzz/mbuzz-php ^1.2
├── readme.txt                  # WP.org format
├── uninstall.php               # Option + transient cleanup
├── src/
│   ├── helpers.php             # mbuzz_event/conversion/identify — scope-stable theme surface
│   ├── Plugin.php              # Singleton, hook registration, switch_blog handling
│   ├── Bootstrap.php           # boot(settings): Mbuzz::init + onSuccess/onError wiring
│   ├── Settings/
│   │   ├── Page.php            # Settings → Mbuzz screen (stub)
│   │   ├── Fields.php          # Sanitization (stub)
│   │   └── Repository.php      # Single-option storage + wp-config.php overrides
│   ├── Identity/               # (planned) wp_login, user_register, profile_update hooks
│   ├── Integrations/           # (planned) WooCommerce, EDD, CF7, Gravity, WPForms, ...
│   ├── Cli/                    # (planned) WP-CLI commands
│   ├── Privacy/                # (planned) Consent API + exporters/erasers
│   └── Block/                  # (planned) Tracked Button Gutenberg block
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

Settings UI is stubbed. For now, configure via `wp-config.php`:

```php
define('MBUZZ_API_KEY', 'sk_live_…');
define('MBUZZ_ENABLED', true);
define('MBUZZ_DEBUG', false);
```

## What's left

Roadmap follows `lib/specs/wordpress-plugin.md` in the SDK repo:

- [x] Plugin bootstrap, autoload, PHP-floor guard (§2, §3)
- [x] Settings storage + wp-config.php overrides (§4)
- [x] Switch_blog reset + re-init for multisite (§10)
- [x] Scope-stable theme helpers (§2)
- [x] onSuccess/onError observers wired to diagnostics transient (§4)
- [x] Identity hooks: wp_login, user_register (+ signup conversion), profile_update (§5)
- [x] WooCommerce: thankyou + processing + completed dedupe, refunds, first-paid detection, HPOS-safe meta, cookieless guest attribution via billing email (§6)
- [x] Server-side visitor bootstrap: plugin mints `_mbuzz_vid` on `template_redirect` so sessions/touchpoints record without a JS pixel (the SDK never mints it itself)
- [x] `track_admins` gate honored in the request path — logged-in admins bypass tracking by default (§4)
- [x] Contact Form 7: `wpcf7_submit` → `lead` conversion, auto-detected email → `user_id`, form id/title in properties, filter overrides (§7)
- [ ] Settings page UI: form, validation, diagnostics card (§4)
- [ ] WooCommerce Subscriptions renewal hook (§6 — deferred, paid extension)
- [ ] Other plugin integrations: EDD, Gravity, WPForms, Fluent, MemberPress, LearnDash (§7)
- [ ] Tracked Button Gutenberg block (§8)
- [ ] WP-CLI commands: status, test, flush, identify, conversion (§9)
- [ ] WP Consent API gating (§11)
- [ ] Privacy exporter + eraser (§11)
- [ ] php-scoper build pipeline (§2)
- [ ] WP.org submission (§14)

## License

GPL-2.0-or-later. See WordPress.org plugin guidelines.
