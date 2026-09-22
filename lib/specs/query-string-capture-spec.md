# Keep the landing page's query string on the cached-page session path

**Date:** 2026-09-22
**Priority:** P0 — every paid click on every cached WordPress site is lost
**Status:** Shipped — `v0.7.1-alpha` installed on BSA 2026-09-22 14:41 AEST and confirmed in production. Phase 4 docs outstanding
**Repo:** `mbuzz-wp`. Format follows `mbuzz/lib/specs/GUIDE.md`.
**Related:** `mbuzz/lib/specs/capi_deployment_spec.md` §3 (where this was found);
`mbuzz/lib/specs/old/page_cache_attribution_rollout_spec.md` (the path that introduced it).

---

## Summary

On a cached page, the plugin records the visitor's session through its own REST endpoint. That
endpoint keeps the page's **path** and throws the **query string** away, so the session never
sees `fbclid`, `gclid` or any `utm_*`. Every ad click on a cached site therefore arrives in mbuzz
as organic, social or direct. At BSA that is ~19,000 Meta ad visits a month recorded as
`organic_social`, and almost certainly the Google shortfall too ($206k of paid search → 138
`paid_search` sessions in 90 days). Attribution, CAPI and every channel report downstream are
built on it. The fix is one line; the release is the work.

---

## Current State

### Evidence (production, 2026-09-22)

| Observation | Source |
|---|---|
| 19,463 of 27,919 BSA sessions in 30 days are `organic_social`; 99% in the FB/IG in-app browser; **0** carry `fbclid`, `_fbc` or any UTM | read-only runner |
| 73 sessions (~2/day) did keep an `fbclid` — consistent with uncached server renders | read-only runner |
| Controlled visit to `…/murray-bridge-childcare/?fbclid=MBZTEST20260922&utm_source=facebook&utm_medium=paid_social&utm_campaign=mbuzz_test` → session `direct`, `click_ids: {}`, `utm: {}` | Vlad, 13:55 AEST |
| The plugin posted `"url" => "https://brightstepsacademy.com.au/centres/murray-bridge-childcare/"` for that session — **no query string** | production request log |
| BSA's redirects (http→https, www→apex) preserve `?fbclid=` | curl |

### Data flow (current)

```
browser  ──POST /wp-json/mbuzz/v1/session { url: location.href, referrer }──▶  Plugin.php:186
  SessionController::pageContext()    esc_url_raw(url)            ✅ full URL kept
  SessionController::recordSession()  REQUEST_URI = PHP_URL_PATH  ❌ query dropped here
  Mbuzz::initFromRequest()            Context::extractUrl() = scheme://host + REQUEST_URI
  ──POST /api/v1/sessions { url: "https://host/path/" }──▶  mbuzz  → no click IDs, no UTMs
```

`src/Rest/SessionController.php`, `recordSession()`:

```php
$path = wp_parse_url($page[self::PARAM_URL], PHP_URL_PATH);
$_SERVER['REQUEST_URI'] = is_string($path) && $path !== '' ? $path : '/';
```

The non-cached path is unaffected: there `REQUEST_URI` is the real request's, query included.
The PHP SDK's own cache endpoint (`mbuzz-php` 2.0 `SessionEndpoint`) forwards the full URL and does
not have this bug — it is plugin-only.

---

## Proposed Solution

Rebuild `REQUEST_URI` the way a web server presents it: **path, plus `?query` when there is
one.** Fragments are never part of `REQUEST_URI` (a browser does not send them), so they are
dropped — `location.href` includes them.

A small pure function in `Tracking\` (no WordPress), e.g. `RequestUri::fromPageUrl(string): string`,
so the rule is unit-testable without Brain Monkey and the controller stays glue. Named constants for
the `/` default, per the plugin's no-magic-strings rule.

### Key Files

| File | Change |
|---|---|
| `src/Rest/SessionController.php` | `recordSession()` sets `REQUEST_URI` from the new function |
| `src/Tracking/RequestUri.php` (new) | path + query from a page URL; fragment dropped; `/` default |
| `tests/Unit/Tracking/RequestUriTest.php` (new) | the All States table below |
| `tests/Unit/Rest/SessionControllerTest.php` | the SDK sees the query during `initFromRequest()` |
| `tests/Integration/page-cache.sh` | new scenario: click IDs survive a cached page (see Testing) |
| `mbuzz-attribution.php`, `readme.txt` | version bump + changelog |

No UI. No mockup.

---

## All States

| State | Page URL | `REQUEST_URI` handed to the SDK |
|---|---|---|
| Ad click | `https://site/centres/x/?fbclid=AB&utm_source=facebook` | `/centres/x/?fbclid=AB&utm_source=facebook` |
| No query | `https://site/centres/x/` | `/centres/x/` (unchanged behaviour) |
| Query on the root | `https://site/?gclid=G1` | `/?gclid=G1` |
| Fragment | `https://site/x/?utm_source=a#form` | `/x/?utm_source=a` |
| Empty query marker | `https://site/x/?` | `/x/` |
| Encoded values | `…/?utm_campaign=spring%20sale` | encoding preserved, not decoded |
| Blank / unparseable URL | `''` | `/` (unchanged behaviour) |

---

## Key Decisions

| Decision | Choice | Why |
|---|---|---|
| Where the rule lives | Pure `Tracking\RequestUri` | Plugin's dependency rule: domain logic has no WordPress; unit-testable directly |
| Upgrade bundled `mbuzz-php` 1.2.0 → 2.0.0 in the same release? | **No** | 2.0 is breaking (adds its own cache bootstrap). A P0 one-line fix must not ride on it |
| Harness | Extend `tests/Integration/page-cache.sh` (wp-env + cache proxy) | The bug lives between layers — correct `pageContext`, correct SDK, wrong handoff. Only a real WordPress behind a real cache shows it |
| Harness read-back | wp-env → **local mbuzz**, read by visitor id from the dev verification endpoint; a test-only mu-plugin under `tests/Integration/` points the bundled SDK at the local API when the key is `sk_test_` | 1.2.0 has no `setApiUrlForTesting()`; nothing test-only ships in the zip. **Fallback** if the SDK cannot be redirected: the production API with the `sk_test_` key, read back by a unique `fbclid` token |
| Delivery | GitHub Release zip → manual upload on BSA | No auto-updates yet (`self-hosted-auto-updates-spec.md`, Draft). Manual replace once 500'd a live site, so the wp-env pre-ship gate is mandatory |

---

## Acceptance Criteria

- [x] Every row of **All States** holds (`RequestUriTest`)
- [x] During `recordSession()`, `Mbuzz::initFromRequest()` sees `REQUEST_URI` with the query (`SessionControllerTest`)
- [x] `REQUEST_URI` is restored after the call, as today
- [x] **Harness RED on the current plugin:** a cached page loaded with `?fbclid=<token>&utm_source=facebook&utm_medium=paid_social` produces a session with **no** `fbclid` and no UTM
- [x] **Harness GREEN after the fix:** the same session carries `click_ids.fbclid == <token>` and `utm_source == facebook`
- [x] Existing `page-cache.sh` checks still pass (visitor minted, distinct ids, stripped Set-Cookie)
- [x] wp-env pre-ship gate: plugin active, logged-out front-end page with an API key → HTTP 200, no critical error
- [x] Release zip built with `bin/build.sh`, attached to a GitHub Release, downloaded back and its version checked
- [x] **On BSA after upgrade:** a controlled visit with `?fbclid=…` yields a session with the click ID, and `paid_social` sessions per day move from ~2 toward the ad volume

---

## Implementation Tasks

### Phase 1 — Harness first (RED)

- [x] **1.1** ~~Test-only mu-plugin pointing the SDK at a local API~~ — **not possible on 1.2.0**: `recordSession()` re-runs `Bootstrap::boot()` on a first visit, which resets any redirect, and `setApiUrlForTesting()` only exists from 2.0.0. **Took the fallback:** the production API as the `sk_test_` account (as `page-cache.sh` already did), read back by the minted visitor cookie via a read-only runner (`MBUZZ_SESSION_LOOKUP` overrides the reader)
- [x] **1.2** `page-cache.sh`: click-ID scenario added. It sends a real browser user agent — curl's own is classified a bot and the session vanishes, which first read as a different bug
- [x] **1.3** **RED for the stated reason** on the unfixed plugin: *"the session reached the API without the query string (fbclid= utm_source=)"*

### Phase 2 — Fix (GREEN)

- [x] **2.1** `RequestUriTest` RED → `Tracking\RequestUri` GREEN (9 states)
- [x] **2.2** `SessionControllerTest`: the session payload's `url` keeps the query (RED: `http://example.com/centres/beresfield/`) and `REQUEST_URI` is restored → `recordSession()` uses `RequestUri`
- [x] **2.3** Full unit suite green — 174 tests (5 pre-existing deprecations in untouched test files)
- [x] **2.4** `page-cache.sh` GREEN, 7/7

### Phase 3 — Ship

- [x] **3.1** `0.7.1-alpha` (`6a1bf3f`): header, `MBUZZ_ATTRIBUTION_VERSION`, `Stable tag`, changelog
- [x] **3.2** wp-env pre-ship gate: active at 0.7.1-alpha, `sk_test_` key, logged-out pages 200, no critical error, session beacon present; the debug log shows the harness session accepted by the API as `paid_social`
- [x] **3.3** `bin/build.sh` → `mbuzz-attribution-0.7.1-alpha.zip` (116K, 100 files, no tests/specs). Published as pre-release `v0.7.1-alpha`; downloaded back — sha256 `433307a7…4242` matches the build. (`gh release create --target` needs the full SHA; a short one is a 422.) SDK still unscoped, same as every prior release — php-scoper is not built yet
- [x] **3.4** Vlad uploaded to BSA (0.7.0-alpha → 0.7.1-alpha, 14:41 AEST). Logged-out pages 200, no critical error, beacon present. **Controlled visit `?fbclid=MBZFIX20260922` → `sess_d10r…` `paid_social`, `fbclid` and all three UTMs kept** (the same visit at 13:55 was `direct`, empty). **First ~19 minutes of real traffic: 22 sessions — 15 `paid_social` with `fbclid`, 3 `paid_search`**, against ~2 `paid_social` a day before

### Phase 4 — Docs

- [ ] **4.1** `mbuzz/lib/docs/runbook/publishing_sdks.md`: WordPress row + how a WP release is verified
- [ ] **4.2** `mbuzz/CLAUDE.md` names `rake sdk:wp`, which does not exist — point it at `mbuzz-wp/tests/Integration/page-cache.sh`
- [ ] **4.3** `mbuzz/lib/docs/sdk/sdk_registry.md` version + changelog line

---

## Testing Strategy

| Test | File | Verifies |
|---|---|---|
| All States | `tests/Unit/Tracking/RequestUriTest.php` | path + query, fragment, blanks, encoding |
| Handoff | `tests/Unit/Rest/SessionControllerTest.php` | the SDK sees the query; `$_SERVER` restored |
| End to end | `tests/Integration/page-cache.sh` | real WordPress, real cache, real SDK wire → session carries the click ID |
| **Cached page, real browser** | `page-cache.sh` Mode 3 | a cache whose key **ignores the query** (Cloudflare "Ignore Query String") serves the stored page for `/?fbclid=…`; headless Chrome runs the page's own inline script; the session carries the click. RED on the pre-fix controller, GREEN on the fix (added 2026-09-22 at Vlad's request) |

**Harness defect found while adding Mode 3:** the proxy sent `Host: localhost:8899`, so WordPress answered every proxied page with a canonical 301 to `:8888` — the cache had been storing redirects, not pages, since the harness was written. A redirect cached for the bare URL then replayed to `?fbclid=` visits without the query. The proxy now sends the site's own host, as a real CDN does. The earlier modes still held (they test the endpoint), but "a cache HIT serves HTML" is only now literally true.
| Production | controlled visit on BSA | the click ID survives on the live site |

---

## Definition of Done

- [ ] Acceptance criteria met; harness went RED then GREEN
- [ ] Released, verified on the GitHub Release, installed on BSA, confirmed by a controlled visit
- [ ] Runbook, registry doc and `mbuzz/CLAUDE.md` updated
- [ ] Spec updated with final state, then moved to `lib/specs/old/`

---

## Out of Scope

- Upgrading the bundled `mbuzz-php` to 2.0.0
- Self-hosted auto-updates (its own spec)
- Recovering historical sessions — the query strings were never stored anywhere, so the last ~months of BSA's paid clicks cannot be reattributed
- The send-rate popover and the "send every mapped event" mode (`capi_deployment_spec.md`)
