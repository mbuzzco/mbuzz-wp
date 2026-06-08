# Claude Development Guide — mbuzz-attribution (mbuzz-wp)

**The WordPress plugin must be as well-designed as every other mbuzz repo.** It being "just a WordPress plugin" is not a licence to write lazy, procedural, HTML-in-PHP code. Separation of concerns, no magic strings, tests first.

## CRITICAL rules

- **No magic strings.** Every domain value (roles, track-as, sources, option/meta keys, payload keys, hook names) is a named constant. The only deliberate literals: the i18n **text domain `mbuzz-attribution`** (translation tooling scans for the literal) and WordPress's **own CSS class names** in templates.
- **Separation of concerns (Rails-like).** Controllers/adapters are thin glue. Domain logic lives in `Tracking\`. Markup lives in **templates**, never echoed from a class. A **presenter** builds the view-model; the **template** does escaped output only — no logic, no queries, no business rules.
- **The plugin is a schema-agnostic conduit.** It never dictates what a customer sends. Traits/properties are arbitrary admin-named keys; `user_id` is admin-designated (a non-PII unique id is preferred); never auto-default `user_id` to an email. See `lib/specs/form-mapping-and-tracking-config-spec.md`.
- **Tracking is opt-in.** A form with no saved, enabled map fires nothing. Never capture PII (esp. children's data) without an explicit per-form mapping.
- **TDD.** Spec → RED test → GREEN code → refactor. Domain logic is pure and unit-tested; WP is mocked (Brain Monkey).
- **NEVER ship to a production site without a wp-env front-end smoke test first.** Green unit tests ≠ "boots in WordPress." Unit tests stub `add_action`, so hook-wiring / activation / enqueue fatals are invisible to them. Before building any zip for a live site, the plugin MUST be activated in wp-env and a **logged-out front-end page loaded with an API key set** (the `sdkReady` path) returning HTTP 200 with no "critical error". See the **Pre-ship gate** in Testing. (This rule exists because skipping it 500'd a live site: an instance method registered as a `[self::class, …]` static hook callback — green units, dead front end.)
- **No secrets in git.** No API keys/tokens. `.wp-env.override.json` (gitignored) holds local keys.
- **No AI attribution in commits.** Conventional commits, matching the other mbuzz repos.

## What this is

A WordPress plugin that wraps the **`mbuzz/mbuzz-php`** SDK (bundled in `vendor/`, php-scoper-prefixed at build) and feeds the **multibuzz** backend (`api.mbuzz.co`). It captures server-side sessions, identity, events, and conversions from WordPress — no JS pixel required. Reporting lives at app.mbuzz.co; this plugin is capture + configuration only.

## Architecture

```
src/
  Tracking/        Domain — pure PHP, no WordPress. The engine.
    Roles, TrackAs, Source, ConversionOptions   constants (the vocabulary)
    FieldMap, ResolvedHit                        value objects
    AutoDetector                                 suggestion logic
    FieldMapRepository                           persistence boundary (post meta)
    TrackingEngine                               orchestrates identify/conversion/event
  Integrations/    Adapters — one per form plugin, behind FormSource.
    FormSource (interface), Cf7FormSource, ContactForm7 (thin runtime adapter)
  Settings/        Admin — controllers + presenters (thin).
    Cf7EditorPanel (controller), Cf7PanelPresenter (view-model), FormMapFields (sanitize)
    Page, ConversionsPage, Repository, Fields
  Support/         Cross-cutting helpers.
    View            template loader
templates/         Views — escaped output only.
  admin/...
lib/specs/         Specs (follow multibuzz lib/specs/GUIDE.md)
tests/Unit/        PHPUnit + Brain Monkey + Mockery
```

**The dependency rule:** `Tracking\` knows nothing about WordPress or form plugins. `Integrations\` adapters translate a plugin's submission into a `FormSource`. `Settings\` controllers are glue between WP hooks and the domain. Templates know nothing but their view-model.

## Separation of concerns

| Layer | Responsibility | Must NOT |
|-------|----------------|----------|
| **Controller / adapter** (`Settings\*`, `Integrations\*`) | wire WP hooks, read request, delegate | contain business logic or markup |
| **Domain** (`Tracking\*`) | the rules (resolve a submission → calls) | touch `$_POST`, `echo`, or any WP UI function |
| **Presenter** (`*Presenter`) | build a pure view-model array from domain objects | call `__()`/`esc_*`/`echo` (templates own presentation) |
| **Template** (`templates/**`) | escaped HTML output from the view-model | hold logic, queries, or unescaped output |

## The view layer

- **`Support\View::render('admin/cf7-panel', $data)`** includes `templates/admin/cf7-panel.php` with `$data` in scope. `View::capture(...)` returns the string. Plain PHP templates — no engine bundled (WP.org-friendly).
- **Templates do escaped output only.** Every dynamic value through `esc_html` / `esc_attr` / `esc_url`. i18n labels via `esc_html__( '…', 'mbuzz-attribution' )`. No `if`-heavy logic, no data access — the presenter prepared everything.
- **Presenters produce pure arrays** (names, ids, current values, option lists) → unit-testable without WordPress. Display labels and escaping belong in the template.
- Reusable fragments are partials under `templates/admin/partials/`.

## Security (every admin write)

- Settings screens use the **Settings API** (`register_setting` + `settings_fields()` + `options.php`) → capability + nonce + option_page-tampering protection for free; the `sanitize_callback` is the sanitization seam.
- Custom save handlers (e.g. the CF7 panel, inside CF7's nonce-verified save) **re-check the capability** and **`wp_unslash()` then sanitize**.
- **Repeatable/variable-key input** (the field map) uses array sanitization: iterate, `sanitize_key` mbuzz keys, validate `role` against `Roles::ALL`, `sanitize_text_field` preserving the form field name. Never the single-value pattern.
- **Escape at output, late, in the template.** Never trust a form field name — it's user data.

## WordPress specifics

- **Storage:** per-form config = the form's **post meta** (travels with the form, no orphans, never autoloaded). Cross-form settings = one option with **`autoload = false`** (WP 6.6 made the default `null`; set it explicitly).
- **Menu:** top-level **Mbuzz** with `add_submenu_page` children (justified only because it's multi-screen; a single page would be a Settings submenu).
- **Privacy:** the plugin ships PII to a third party → `Privacy\PersonalData` registers a Privacy API exporter + eraser (the conduit stores almost nothing locally, so it discloses third-party processing + erases the one local record, `Identity\Hooks::META_LAST_IDENTIFIED_AT`). Legal posture (APP 8 cross-border, Children's Online Privacy Code, GDPR) is the owner's call, not an engineering assumption.
- **Consent:** every capture entry point (each `Integrations\`/`Identity\` adapter, the front-end session path, the `mbuzz_*` theme helpers) MUST early-return on `Privacy\Consent::granted()` before sending anything. The pure `Tracking\` domain never references consent — gating lives in the adapter/controller layer. `Consent::granted()` honors the **WP Consent API** (`wp_has_consent`) when present (default category `marketing`), defaults to allowed when absent, and is filterable (`mbuzz_consent_category`, `mbuzz_has_consent`).

## i18n & accessibility

- Text domain `mbuzz-attribution` on **every** user-facing string (`__`, `esc_html__`, `esc_attr__`). The literal is required — never a constant.
- Admin UI meets WP a11y standards: a `<label>` for every control, keyboard-navigable, status never by colour alone.

## Hooks & extensibility

- All custom hooks are prefixed `mbuzz_` and declared as constants (e.g. `TrackingEngine::FILTER_SKIP`). New form plugins are new `FormSource` adapters — the engine never changes.

## Testing

- PHPUnit 10 + **Brain Monkey** (WP function mocks) + **Mockery**. `composer test:unit`.
- **Pure domain** (`Tracking\`, presenters, sanitizers) is unit-tested directly. **SDK calls** are asserted via the transport-capture seam (`Mbuzz::getClient()->setTransport(...)`). Templates/rendering are verified in `wp-env`, not unit tests.
- `failOnRisky` is on — a Mockery-only test needs `$this->addToAssertionCount(1)`.
- **Hook-wiring guard.** `PluginHookWiringTest` captures every callback `Plugin::register()` wires and asserts each is `is_callable` (and that `[class, method]` callbacks declared static really are static). This is the unit-level backstop for the static-vs-instance hook bug; keep it green when adding hooks.

### Pre-ship gate (MANDATORY before any production upload)

Unit tests verify units in isolation; they cannot verify the plugin **boots inside WordPress** (they stub `add_action`). The gap between "tests pass" and "safe in production" for a WP plugin is exactly boot/activation/hook-wiring/enqueue — only a real WP load exercises it. So, in order, every time before zipping for a live site:

1. `composer test:unit` — green (incl. `PluginHookWiringTest`).
2. **wp-env front-end smoke** — the step that catches what units can't:
   - `npx @wordpress/env run cli wp plugin list` → confirm `mbuzz-wp` is **active**.
   - Set a test key so the `sdkReady` enqueue path runs:
     `npx @wordpress/env run cli wp eval '$o=get_option("mbuzz_attribution_settings"); $o=is_array($o)?$o:[]; $o["api_key"]="sk_test_smoke"; update_option("mbuzz_attribution_settings",$o);'`
   - Load a **logged-out** front-end page and assert no fatal:
     `curl -s -o /tmp/smoke.html -w "%{http_code}\n" "http://localhost:8888/?nocache=$(date +%s)"` → must be **200**, and `grep -c "critical error" /tmp/smoke.html` → must be **0**.
   - Reset the test key afterward.
3. Only then build the zip.

Deploy ordering on the live site: **replace + activate, confirm the front end renders, THEN purge cache.** Purging before confirming only exposes already-broken code to visitors (a cache purge is how the 500 above surfaced).

## Distribution

- Bundle `mbuzz-php` via **php-scoper** (`Mbuzz\Vendor\`) so two plugins shipping the SDK don't collide. `readme.txt` stable tag must match the header version. Releases: GitHub (beta) → WordPress.org.

## Specs & commits

- Specs live in `lib/specs/` and follow `multibuzz/lib/specs/GUIDE.md`. Write the spec, get review, then build.
- Commits: `type(scope): subject`. Types `feat|fix|refactor|test|docs|chore`. **No AI attribution.**
