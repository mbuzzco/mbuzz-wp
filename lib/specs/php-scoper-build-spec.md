# php-scoper build pipeline (WP.org distribution prerequisite)

**Date:** 2026-06-10
**Priority:** P1 (top blocker for WordPress.org submission)
**Status:** Draft
**Branch:** `feat/php-scoper-build`
**Repo:** `mbuzz-wp`. Format follows `multibuzz/lib/specs/GUIDE.md`.

---

## Summary

Bundle the `mbuzz/mbuzz-php` SDK under a plugin-private namespace prefix
(`Mbuzz\Vendor\`) at build time, so two plugins each shipping a (possibly
different) copy of the SDK can't collide. This is the **#1 hard blocker for the
WordPress.org plugin directory**: WP.org rejects bundled dependencies in a
shared global namespace because a class-name collision with another plugin's
copy fatals the site. The plugin's design already anticipates this (see
`src/helpers.php`: "After php-scoper runs, the SDK class lives at
`\Mbuzz\Vendor\Mbuzz\Mbuzz`") — this spec builds the pipeline that makes it real.

**No runtime behaviour changes.** Same SDK, same calls, just prefixed symbols in
the shipped zip. Dev and the test suite continue to run against the **unscoped**
SDK (`Mbuzz\Mbuzz`); scoping happens only when packaging a release.

---

## Current State

- `composer.json` requires `mbuzz/mbuzz-php ^1.2`; the SDK installs to
  `vendor/mbuzz/mbuzz-php/` with namespace **`Mbuzz`** (global, unscoped).
- The plugin references the SDK as `\Mbuzz\Mbuzz` in **8 `src/` files**
  (Plugin, Bootstrap, helpers, ContactForm7, FormMapFields, Cf7EditorPanel,
  ConversionsPage, ConversionsOverviewPresenter, Cf7PanelPresenter, FormSummary —
  via `use Mbuzz\Mbuzz;` / `Mbuzz::*`).
- **5 test files** reference `Mbuzz\Mbuzz` directly (the transport-capture seam
  `Mbuzz::getClient()->setTransport(...)`). Tests MUST keep working unscoped.
- The release zip is built ad-hoc (`composer install --no-dev` + zip); there is
  **no scoper step** and no `scoper.inc.php`.
- No sibling mbuzz repo has a php-scoper precedent — this is the first.

### The collision being prevented

```
Plugin A ships mbuzz-php 1.2 → class \Mbuzz\Mbuzz
Plugin B ships mbuzz-php 1.5 → class \Mbuzz\Mbuzz   ← same FQN, different code
WordPress loads both → "Cannot redeclare class" / wrong version wins → fatal
```

After scoping: Plugin A's is `\Mbuzz\Vendor\Mbuzz\Mbuzz`, B's is its own prefix → no clash.

---

## Proposed Solution

A **build-time** scoping step using `humbug/php-scoper`, producing a `build/`
(or `dist/`) tree with the vendor SDK prefixed and the plugin's references to it
rewritten, from which the release zip is made. Dev/test trees are untouched.

### The key decision: how the plugin's own `\Mbuzz\Mbuzz` calls get prefixed

The plugin's `src/` calls `\Mbuzz\Mbuzz` directly. Two options:

- **(A) Scope the plugin sources too (recommended).** php-scoper processes
  `src/` as well as `vendor/`, rewriting `use Mbuzz\Mbuzz` → `use Mbuzz\Vendor\Mbuzz\Mbuzz`
  in the built copy. Source stays unscoped (dev/tests use real `Mbuzz\Mbuzz`);
  only the built artifact is prefixed. The plugin's *own* namespace `Mbuzz\WP`
  is added to scoper's exclude list so it is NOT prefixed (it's the plugin, not a
  bundled dep). **This is the standard Yoast/WP approach.**
- (B) Leave `src/` referencing `\Mbuzz\Mbuzz` and only alias at the bootstrap.
  Fragile (every call site must resolve the alias); rejected.

→ **Go with (A).** Exclude `Mbuzz\WP\*` and the global `mbuzz_*` helper functions
from prefixing; prefix everything under `vendor/` + the plugin's references to the
SDK namespace.

### What must NOT be prefixed (scoper excludes)

1. **`Mbuzz\WP\*`** — the plugin's own namespace (it's the product, not a dep).
2. **Global `mbuzz_event` / `mbuzz_conversion` / `mbuzz_identify`** — the
   documented scope-stable theme surface (`helpers.php`). They stay on the global
   symbol table; their *bodies* get the prefixed SDK class, but the function names
   never change.
3. **WordPress core symbols** — `add_action`, `WP_REST_Response`, etc. (scoper
   excludes these via the WP stubs / a symbol list; never prefix host symbols).
4. **The `mbuzz_*` hooks/filters/option keys** — these are runtime strings, not
   PHP symbols; scoper doesn't touch them, but verify.

### Data Flow (build)

```
composer install --no-dev          # unscoped vendor
php-scoper add-prefix              # → build/: vendor + src prefixed to Mbuzz\Vendor\
composer dump-autoload (in build/) # regenerate autoload for prefixed classes
strip dev cruft + zip build/       # release artifact
```

### Key Files

| File | Purpose | Change |
|------|---------|--------|
| `scoper.inc.php` | Prefix `Mbuzz\Vendor\`; exclude `Mbuzz\WP\*`, the `mbuzz_*` functions, WP symbols; patchers if needed | **New** |
| `composer.json` | dev-require `humbug/php-scoper`; add `build`/`package` scripts | **Edit** |
| `bin/build.sh` (or composer script) | Orchestrate install→scope→dump→zip; the single packaging entrypoint | **New** |
| `.gitignore` | ignore `build/` / `dist/` | **Edit** |
| `mbuzz-attribution.php` | The autoload require already points at `vendor/autoload.php`; confirm it resolves the scoped autoloader in the built tree | **Verify** |

---

## All States / Edge Cases

| Case | Expectation |
|------|-------------|
| Dev install (`composer install`) | Unscoped `Mbuzz\Mbuzz`; `composer test:unit` green (unchanged) |
| Built release | All vendor + plugin SDK refs prefixed `Mbuzz\Vendor\Mbuzz\…`; `Mbuzz\WP\*` unprefixed |
| Theme calls `mbuzz_event()` | Works against the built plugin (function name unprefixed; body uses scoped class) |
| Two plugins, two SDK versions, both scoped | No class collision (distinct prefixes) |
| Scoper missed a dynamic class string | Caught by the built-artifact smoke test (wp-env load of the zip) — fail the build |
| `wp_localize_script` / REST routes / hook names | Unchanged (runtime strings, not symbols) |

---

## Implementation Tasks (TDD-adjacent — scoping is a build concern; verify by execution)

### Phase 1 — Scoper config
- [ ] **1.1** dev-require `humbug/php-scoper`; pin a version.
- [ ] **1.2** `scoper.inc.php`: prefix `Mbuzz\Vendor`; `exclude-namespaces` for
  `Mbuzz\WP`; `exclude-functions` for the three `mbuzz_*` helpers; WP symbol
  exclusion (php-scoper WordPress preset or `wordpress-stubs`).
- [ ] **1.3** Verify scoping a throwaway build: `grep` the built tree → vendor is
  `Mbuzz\Vendor\Mbuzz`, `src/Plugin.php` uses the prefixed class, `Mbuzz\WP` and
  `mbuzz_event` are untouched.

### Phase 2 — Build pipeline
- [ ] **2.1** `composer build` script (or `bin/build.sh`): clean → `composer
  install --no-dev` → `php-scoper add-prefix --output-dir=build` → `composer
  dump-autoload -o` in build → strip dev cruft → zip.
- [ ] **2.2** Make this the SINGLE packaging path (replaces the ad-hoc rsync/zip
  we've been doing by hand).

### Phase 3 — Verification (the gate that matters)
- [ ] **3.1** Unit suite green against unscoped source (no regression).
- [ ] **3.2** **wp-env smoke on the BUILT (scoped) zip** — per CLAUDE.md pre-ship
  gate: activate the built zip, logged-out front-end load with a key set → 200,
  no critical error, `/wp-json/mbuzz/v1/lead` → 204, `mbuzz_event()` callable.
  This is what proves scoping didn't break a dynamic reference.
- [ ] **3.3** Collision test (optional, strong): install the built plugin AND a
  second plugin bundling unscoped `Mbuzz\Mbuzz` in wp-env; confirm no fatal.

---

## Definition of Done

- [ ] `composer build` produces a zip whose `vendor/` + plugin SDK refs are
  prefixed `Mbuzz\Vendor\`, with `Mbuzz\WP\*` and `mbuzz_*` helpers unprefixed.
- [ ] Unit suite green (unscoped); built-zip wp-env smoke green (scoped).
- [ ] `php-scoper build pipeline` checked off in README "What's left".
- [ ] Packaging is one command, not the manual rsync/zip dance.

---

## Out of Scope (separate WP.org tasks — track but don't conflate)

- **External-service disclosure** in `readme.txt` (that the plugin transmits data
  to `api.mbuzz.co`, what's sent, privacy-policy link) — REQUIRED by WP.org, but a
  doc task, not the build. Do alongside, separately.
- **Stable (non-alpha) version** — WP.org expects a real release; bump when
  submitting, not here.
- **WP.org submission itself** (SVN, assets/banner, review) — follow-up once the
  blockers clear.
- **Self-hosted GitHub auto-updates** — an alternative distribution path; orthogonal.
- **Scoping the other SDKs/plugins** — none exist; this is the first and only.

---

## Open Questions

1. Scope `src/` in-place into `build/` (option A) vs a source transform — confirm
   php-scoper handles the plugin-namespace exclude cleanly so `Mbuzz\WP` classes
   aren't accidentally prefixed (they reference `Mbuzz\Mbuzz`, which IS prefixed —
   scoper must rewrite the *reference* without prefixing the *referrer's* namespace).
2. WordPress symbol exclusion: php-scoper's built-in WP support vs explicitly
   listing via `php-stubs/wordpress-stubs`. (Lean: wordpress-stubs, deterministic.)
3. Does `mbuzz-php` itself reference any global/un-namespaced symbols that need
   special patchers? (Audit during 1.3.)
