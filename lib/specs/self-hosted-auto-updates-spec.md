# Self-hosted auto-updates (Rung 2 distribution)

**Date:** 2026-06-10
**Priority:** P1 (highest value-per-effort distribution step; ends manual zip uploads)
**Status:** Draft
**Branch:** `feat/self-hosted-updates`
**Repo:** `mbuzz-wp`. Format follows `multibuzz/lib/specs/GUIDE.md`.
**Related:** `mbuzz-org/lib/specs/wordpress_plugin_distribution.md` (the ladder); Rung 1 (GitHub Releases) is done.

---

## Summary

Make installed sites receive **"update available" in wp-admin and one-click update**,
pulled from this repo's GitHub Releases — the same UX as a WordPress.org plugin, without
WP.org review. Today every fix means re-emailing a zip and a manual "Replace current with
uploaded" (which is outage-prone — it 500'd a live site once). Auto-updates remove that
whole class of risk: build → tag a Release → every site updates itself.

Mechanism: bundle [`YahnisElsts/plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker)
(PUC, v5.x) configured against this GitHub repo, pointing at the **built release asset**
(the zip with `vendor/` bundled) — not GitHub's source-only archive.

Non-technical framing: *"Customers stop emailing us 'how do I update?' — they just click
Update in their dashboard like any other plugin."*

---

## Current State

- **Rung 1 done:** `bin/build.sh` produces the installable zip; a GitHub Release
  (`v0.3.0-alpha`) attaches it. Anyone can download + manually upload.
- **No update mechanism in the plugin** — `mbuzz-attribution.php` has no update checker;
  installed sites never learn a new version exists.
- The current release is flagged **pre-release** (`isPrerelease: true`). **PUC ignores
  pre-releases** — so as-is, PUC would find *no* installable update. (Decision below.)
- The plugin folder/slug is `mbuzz-attribution`; header `Version: 0.3.0-alpha`.

---

## Proposed Solution

### The library: plugin-update-checker (PUC) v5

Established, widely used, supports GitHub Releases natively, uses WP's default upgrade UI.
Setup (in `mbuzz-attribution.php`, after autoload):

```php
use Mbuzz\Vendor\YahnisElsts\PluginUpdateChecker\v5\PucFactory; // namespace once scoped

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/mbuzzco/mbuzz-wp/',
    __FILE__,
    'mbuzz-attribution'
);
// Use the ATTACHED built zip (vendor bundled), NOT GitHub's source-only archive:
$updateChecker->getVcsApi()->enableReleaseAssets('/mbuzz-attribution-.*\.zip$/i');
```

`enableReleaseAssets($pattern)` is the linchpin: without it PUC offers GitHub's
auto-generated source zip, which has **no `vendor/`** → the updated plugin fatals on
activation (the exact missing-SDK failure mode). The regex targets our build asset.

### Version detection

PUC compares the installed plugin header `Version:` against the latest **non-pre-release**
GitHub Release's tag/version. No `Update URI` header needed (the repo URL is the
identifier). So the build/release flow already drives it — tag a release, sites see it.

### The pre-release / alpha decision (must resolve)

PUC skips releases marked pre-release, and "isn't smart enough to filter alpha/beta/RC"
from tag names. Two coupled choices:

1. **Stop marking releases pre-release** once we want auto-updates to flow — OR keep a
   convention where the *update channel* release is a normal release.
2. **Move off `-alpha` versions** for anything meant to auto-ship, since `0.3.0-alpha <
   0.3.0` in version compares and the alpha suffix muddies upgrade ordering.

→ **Recommendation:** when Rung 2 ships, cut a normal (non-pre-release) `0.3.0` (or
`0.4.0`) release as the first auto-update target. Keep alpha/pre-release tags for
internal/testing only. Document the channel: "Releases not marked pre-release are the
auto-update channel."

### Build/release flow (after this lands)

```
bin/build.sh                       # build zip (Rung 1, unchanged)
gh release create vX.Y.Z dist/...  # NORMAL release (not --prerelease) = auto-update channel
   → installed sites' PUC sees vX.Y.Z > installed → WP shows "update available"
   → admin clicks Update → WP downloads the attached built zip → activates
```

### Key Files

| File | Purpose | Change |
|------|---------|--------|
| `composer.json` | require `yahnis-elsts/plugin-update-checker` (prod dep, bundled) | **Edit** |
| `mbuzz-attribution.php` | instantiate PUC after autoload; `enableReleaseAssets(...)`; gate so it only runs in admin/cron (not every front-end request) | **Edit** |
| `src/Update/Updater.php` (optional) | wrap PUC setup behind a thin class (testable, no-magic-strings: repo URL, slug, asset pattern as constants) | **New (consider)** |
| `bin/build.sh` | ensure PUC's `vendor/` files survive the cruft-strip (it ships assets/CSS for Debug Bar — keep what PUC needs) | **Verify** |
| `lib/specs/php-scoper-build-spec.md` | PUC is a *second* bundled dep → must ALSO be scoped for WP.org (note the cross-dependency) | **Cross-ref** |

---

## All States

| State | Condition | Expected |
|-------|-----------|----------|
| New normal release exists | tag > installed, not pre-release, asset matches pattern | wp-admin shows update; one-click updates to the built zip |
| Only pre-release newer | latest is `--prerelease` | No update offered (intended — pre-releases are not the channel) |
| No newer release | installed == latest | "Up to date" |
| Asset pattern matches nothing | release has only source zip | **Must not offer the source zip** (would break) — PUC offers nothing; log/guard |
| Update check timing | admin/cron | Runs on WP's update cron (~12h) + Dashboard→Updates; NOT on every front-end hit |
| Rate limit (GitHub API) | unauthenticated 60/h | PUC caches; fine for low site counts. Document a token option if scale needs it |
| Rollback | bad release shipped | Cut a higher patch release; sites auto-move forward (no downgrade UI) — so the build+smoke gate before releasing is the safety net |

---

## Implementation Tasks (TDD where it has seams)

### Phase 1 — Library + wiring
- [ ] **1.1** `composer require yahnis-elsts/plugin-update-checker`; commit the bundled lib.
- [ ] **1.2** `Update\Updater` (or inline): constants for repo URL, slug, asset regex; PUC
  instantiated after autoload, admin/cron-gated. RED `UpdaterTest` asserts config wiring
  is callable + the asset pattern matches a real release-asset filename and rejects the
  source-zip name. (Mirror `PluginHookWiringTest` rigor — this is bootstrap code.)
- [ ] **1.3** Ensure `bin/build.sh` keeps the PUC files the runtime needs (don't over-strip
  vendor); add a build self-check that PUC's main file is present in the zip.

### Phase 2 — Channel + release flow
- [ ] **2.1** Decide + document the channel (non-pre-release = auto-update). Update
  `bin/build.sh` / release docs so a real release is `gh release create` WITHOUT
  `--prerelease`.
- [ ] **2.2** Cut a normal `0.3.0` (or next) release as the first auto-update target.

### Phase 3 — Verification (the gate that matters)
- [ ] **3.1** Unit suite green.
- [ ] **3.2** **wp-env end-to-end update test:** install an OLDER built zip (e.g. fake the
  header to `0.2.9`), publish/point at a newer normal release, run
  `wp plugin update mbuzz-attribution` (or trigger the update-check cron) → confirm it
  pulls the **built asset** (vendor present), activates, front-end 200, endpoint 204.
  This proves the asset-selection regex and that the updated zip isn't the broken
  source-only archive.
- [ ] **3.3** Negative test: a pre-release newer version is NOT offered.

---

## Definition of Done

- [ ] Installed sites see + apply updates from non-pre-release GitHub Releases, pulling the
  built (vendor-bundled) asset.
- [ ] wp-env end-to-end update test green (old → new, vendor intact, no fatal).
- [ ] Pre-releases correctly ignored.
- [ ] Channel documented; `bin/build.sh`/release flow updated; README "What's left" ticks
  "self-hosted auto-updates".
- [ ] Cross-referenced in the php-scoper spec (PUC is a second dep to prefix for WP.org).

---

## Out of Scope

- **WP.org submission** (Rung 3) — and note the channel conflict: a WP.org-listed plugin
  must update via WP.org only, so the WP.org build must DISABLE PUC. Track in the php-scoper
  / WP.org work; pick the single channel before submitting.
- **php-scoper** itself — separate spec. PUC just adds a second namespace to prefix.
- **A custom update *server*** (JSON metadata endpoint) — unnecessary; GitHub Releases is
  the source of truth.
- **Auto-update-by-default** (WP 5.5 auto-update opt-in per plugin) — surfacing the toggle
  is fine; forcing background auto-apply is a later policy decision.

---

## Open Questions

1. Normal-release channel vs a dedicated `stable` branch + JSON — lean normal-release
   (simplest, GitHub-native). Confirm.
2. GitHub API rate limit at scale (60/h unauthenticated, shared per server IP across all
   plugins using GitHub) — fine now; if many sites, ship a read-only token option or a
   thin caching proxy. Decide threshold.
3. Do we want the WP 5.5 "enable auto-updates" toggle visible for this plugin, or
   update-notice-only? (Lean: notice-only first; auto-apply is riskier for a tracking
   plugin that can 500 if a release is bad — and our safety net is the pre-release build
   gate, not background auto-apply.)
