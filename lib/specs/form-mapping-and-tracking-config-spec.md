# Form Mapping & Tracking Configuration

**Date:** 2026-05-30
**Priority:** P0 (blocks real customer setup — Acme)
**Status:** Draft
**Branch:** `feat/form-mapping-config`
**Repo:** `mbuzz-wp` (plugin). Format follows `multibuzz/lib/specs/GUIDE.md`.

---

## Summary

Give a WordPress admin a no-code way to **set up how their forms map to mbuzz** — which forms are tracked, whether each is a conversion or an event, what the conversion is called, and **how every form field maps to mbuzz's vocabulary** (identity traits, the cross-system `user_id`, revenue, or named properties), plus capturing the **page as a location**. Today the plugin only accepts an API key and then *hardcodes* "any Contact Form 7 submit → a `lead` conversion, passing every field through verbatim." That is invisible, uncontrollable, fires on forms the admin never intended, and — on a site that collects family data — silently ships children's names and DOBs to mbuzz. This spec replaces that auto-magic with an **explicit, admin-configured, opt-in mapping** that is the single source of truth applied at submit time.

Non-technical framing: *"After I paste my API key, I open Mbuzz → Conversions, I see my forms, and for each one I say 'this is an enquiry, this field is the email, capture the location, call this field team_size' — and that's the whole setup."*

---

## Current State

- `src/Integrations/ContactForm7.php` hooks `wpcf7_submit` and, on `mail_sent`/`demo_mode`, **always** fires `conversion('lead', …)`: auto-detects the email (validation), maps name/phone by regex to canonical traits, and passes **every** posted field through to `properties`. No per-form control, no field naming, no page/location capture, no event option.
- `src/Settings/Page.php` exposes only: API key, Enable tracking, Track admins, Debug, plus a diagnostics card. No mapping surface.
- `src/Settings/Repository.php` stores one option (`mbuzz_attribution_settings`) with `wp-config.php` constant overrides.
- Identity-on-login/register exists in `src/Identity/Hooks.php` (separate, fine).
- Backend canonical identity traits (the only ones hashed into Meta/Google match keys) are `email`, `phone`, `first_name`, `last_name` (verified in `multibuzz/app/constants/canonical_identity_traits.rb`). Everything else stays as free-form JSONB properties. The conversion endpoint joins cross-system on `user_id` (= `Identity#external_id`), exact match.

### Data Flow (Current)

```
CF7 submit (wpcf7_submit, status ok)
  → ContactForm7::onSubmit
      → detect email (hardcoded)        → identify(email, {email,phone,first_name,last_name})
      → ALL fields → properties (raw)   → conversion('lead', { user_id: email, properties })
```

Problem: no admin input anywhere in that chain.

---

## Proposed Solution

A **field-map store** (per form, persisted) edited through a **Conversions** admin screen, applied by a **map resolver** at submit time. The CF7 integration becomes a thin adapter over a generic mapping engine, so Gravity Forms / WPForms / Fluent can plug in later without touching the engine.

**Behavior change (intentional):** tracking is now **opt-in**. A form with no saved map (or `track_as: off`) fires **nothing**. This removes the unconfigured PII/noise firing. On upgrade, existing installs pause until forms are configured; the screen offers an auto-detected suggested map the admin reviews and saves.

### Data Flow (Proposed)

```
CF7 submit (status ok)
  → ContactForm7 adapter: { source:'cf7', form_id, form_title, posted, page_id, page_url }
  → TrackingEngine::handle(source-event)
      → FieldMapRepository.for('cf7', form_id)      → FieldMap (or null → STOP)
      → FieldMap.resolve(posted, page)               → ResolvedHit {
                                                          track_as, type,
                                                          user_id, traits{canonical},
                                                          properties{named} + location }
      → if traits/user_id present → identify(user_id, traits)
      → track_as=conversion → conversion(type, {user_id, properties})
        track_as=event      → event(type, properties)
```

### The mapping model

**Storage (revised in Pass 2): per-form, in the form's own meta** — not one global option. For CF7 the map is stored as post meta (`_mbuzz_form_map`) on the `wpcf7_contact_form` post, edited in the CF7 editor panel, so it travels with the form (export/import), never orphans on form deletion, and is never autoloaded. A small global option (`mbuzz_attribution_settings`, `autoload=false`) holds cross-form toggles (identity-on-login). `FieldMapRepository::for(source, form_id)` abstracts retrieval per source (CF7 post meta now; GF feeds / Fluent form-meta later). The per-form map shape:

```
{ "cf7": { "7": {
  "enabled": true,
  "track_as": "conversion",          // conversion | event | off
  "type": "enquiry",       // conversion_type or event name (sanitized slug)
  "capture_page_as": "location",       // property name for the page title, or null
  "fields": {
    "CustomerRef":       { "role": "user_id" },                  // the join key — a non-PII id is preferred
    "CustomerEmail":       { "role": "trait", "key": "email" },    // arbitrary trait name; backend hashes 'email' for ad-matching
    "FirstName":   { "role": "trait", "key": "first_name" },
    "MobilePhone": { "role": "trait", "key": "phone" },
    "Postcode":          { "role": "property", "key": "postcode" },
    "TeamSize":  { "role": "property", "key": "team_size" },
    "Child1FirstName":   { "role": "ignore" },
    "comments":          { "role": "property", "key": "notes" }
  }
} } }
```

**Roles — the plugin is schema-agnostic; it never dictates trait names:**

- **`user_id`** — one field designates the cross-system join key (`external_id`). It can be *anything* the customer controls; a **non-PII unique ID is preferred** so PII isn't sent to mbuzz. The plugin **never auto-assigns `user_id` to an email** — the admin chooses it. If no field is `user_id`, the conversion still attributes via the visitor cookie; no identity is created.
- **`trait`** — any identity attribute, with a **free `key` the admin names**. The backend *independently* hashes a trait that happens to be named `email`/`phone`/`first_name`/`last_name` into ad match keys, but the plugin neither requires, restricts, nor knows about that — it just passes traits through.
- **`property`** — any free-keyed attribute attached to the conversion.
- **`revenue`**, **`currency`**, **`ignore`**.

### Auto-detection (a starting point the admin edits — never a schema)

`AutoDetector` pre-fills a suggested map from a form's field names to make setup fast. It is only ever a suggestion:

- **It does NOT auto-pick `user_id`.** The admin must designate the identity field; the UI nudges toward a **non-PII unique ID** and warns that mapping email/phone sends PII to mbuzz.
- Name/email/phone-looking fields → suggested as `trait` with a sensible key (`email`/`first_name`/`last_name`/`phone`) — surfaced as a convenience because those names are ad-hashed downstream; fully editable/removable.
- Tracking fields (`gclid`, `fbclid`, `utm_*`, `source`) → `ignore` (the session already captures them).
- PII-sensitive (`/child|dob|birth|age/i`) → `ignore`, so sensitive/child data is **opt-in**, never captured by accident.
- Everything else → `property`, key = `snake_case(field_name)`.
- `capture_page_as` defaults to `null`.

### Key Files

| File | Purpose | Change |
|------|---------|--------|
| `src/Tracking/Roles.php` | Role allowlist (`user_id`, `trait`, `property`, `revenue`, `currency`, `ignore`) for sanitization | **New** |
| `src/Tracking/FieldMap.php` | Value object: one form's map; `resolve(posted, page): ResolvedHit` — schema-agnostic, arbitrary trait/property keys | **New** |
| `src/Tracking/ResolvedHit.php` | DTO: `{track_as, type, user_id, traits, properties}` | **New** |
| `src/Tracking/AutoDetector.php` | Field names → suggested `FieldMap` (defaults + PII guard) | **New** |
| `src/Tracking/FieldMapRepository.php` | `for(source, form_id)` resolves a form's map per source (CF7 → `_mbuzz_form_map` post meta); save/sanitize | **New** |
| `src/Tracking/TrackingEngine.php` | Source-event → identify + conversion/event via the map | **New** |
| `src/Integrations/FormSource.php` | Interface exposing fields as `(id, name, label, value)` + `formId/formTitle/page` — normalizes GF sub-IDs and keyed arrays | **New** |
| `src/Integrations/ContactForm7.php` | `FormSource` adapter; delegates to `TrackingEngine`; drops the hardcoded auto-fire | **Refactor** |
| `src/Settings/Cf7EditorPanel.php` | Per-form **Mbuzz panel in the CF7 form editor** (`wpcf7_editor_panels`) + save (`wpcf7_save_contact_form`) | **New** |
| `src/Settings/FormMapFields.php` | Array-sanitize the panel map → `FieldMapRepository` (post meta) | **New** |
| `src/Settings/ConversionsPage.php` | Central **overview** screen (tracked forms, status, links into each editor) + global toggles | **New** |
| `src/Plugin.php` | Register the editor panel, overview submenu, engine wiring | **Edit** |

---

## All States

| State | Condition | Expected behavior |
|-------|-----------|-------------------|
| Unconfigured form | no map for `(source, form_id)` | **Nothing fires** (opt-in). Admin sees it in the list as "Not tracked" with a "Use suggested map" action. |
| `track_as: off` | map exists, disabled | Nothing fires. |
| Conversion happy path | map enabled, `conversion` | `identify(user_id, traits)` then `conversion(type, {user_id, properties + location})`. Traits are whatever the admin mapped — arbitrary keys. |
| Event | `track_as: event` | `event(type, properties)`. No conversion. No revenue. |
| No `user_id` mapped | no field roled `user_id` | `user_id` omitted; conversion attributes via the visitor cookie; `identify` skipped. The plugin never substitutes an email. |
| Multi-value field | posted value is an array (checkbox group) | Identity uses first scalar; property keeps the array. |
| Missing field | mapped field absent from this submission | Skipped silently (optional fields). |
| `ignore` role | field mapped to ignore (incl. PII defaults) | Never sent — not in traits, not in properties. |
| `capture_page_as` set | property name configured | Page title added as that property (e.g. `location: "Downtown Office"`); page id/url also added under `mbuzz_page_*` for audit. |
| Revenue field | role `revenue` (+ optional `currency`) | Parsed to float → conversion `revenue`/`currency`. Non-numeric → omitted. |
| Submission failed CF7 validation | status ∉ {mail_sent, demo_mode} | Nothing fires (unchanged gate). |
| Tracking disabled / no key | master switch off or `sdkReady=false` | Nothing fires (engine short-circuits). |
| Admin saves bad input | unknown role, empty type, dup property keys | Sanitized: unknown role → `ignore`; empty type → form-title slug; dup keys → last wins, surfaced as a notice. |

---

## Implementation Tasks (TDD order)

Each phase: write tests from the acceptance criteria (RED), implement (GREEN), refactor, run full suite.

### Phase 1 — Mapping engine (pure PHP, no WP)

- [ ] **1.1** `CanonicalTraits` constant + `FieldMap`/`ResolvedHit` value objects.
- [ ] **1.2** RED: `FieldMapTest` — `resolve(posted, page)` cases (see Testing). GREEN.
- [ ] **1.3** RED: `AutoDetectorTest` — defaults, tracking-field ignore, PII-default-ignore, snake_case. GREEN.
- [ ] **1.4** `FieldMapRepository` with sanitize (roles, keys, type slug). RED `FieldMapRepositoryTest` (Brain Monkey for `get_option/update_option`). GREEN.

### Phase 2 — Tracking engine + CF7 adapter

- [ ] **2.1** `FormSource` interface + `TrackingEngine::handle(FormSource)`.
- [ ] **2.2** RED: `TrackingEngineTest` — opt-in (no map → nothing), conversion path, event path, identify-before-conversion, location capture, disabled/no-key short-circuit. GREEN.
- [ ] **2.3** Refactor `ContactForm7` into a `FormSource` adapter (reads `_wpcf7_container_post` → page; `WPCF7_Submission` posted data) that calls the engine. Delete the hardcoded auto-fire. Update `ContactForm7Test` to the map-driven behavior.

### Phase 3 — Admin UI (per-form, in the form editor)

- [ ] **3.1** **CF7 editor panel (primary surface).** Add a "Mbuzz" panel to the CF7 form editor via `add_filter('wpcf7_editor_panels', …)`; render the per-form map (enable / track-as / type / capture-page-as + a field table with a role `<select>` and a mbuzz-name input per field), **pre-filled by `AutoDetector`** so the admin confirms rather than builds. Persist to the form's **post meta** on `wpcf7_save_contact_form` (the map travels with the form — export/import safe, no orphaned-form-id rows, not autoloaded). The field table emulates Gravity's `field_map` primitive (one dropdown per target, default pre-selected).
- [ ] **3.2** `FormMapFields::sanitize` runs in the `wpcf7_save_contact_form` handler (CF7 provides the nonce/capability in its own save flow; re-check `current_user_can('wpcf7_edit_contact_form')`). Because the map is a **variable-key, repeatable key→value structure**, use array sanitization, NOT single-value: `wp_unslash()` first, then iterate — `sanitize_key()` on field names + map keys, validate `role` against an allowlist (`identity|property|revenue|currency|ignore`), `sanitize_text_field()` on names/values (`array_map`/`map_deep`). Drop unrecognized. RED `FormMapFieldsTest`. GREEN.
- [ ] **3.3** **Central "Mbuzz → Conversions" overview** (submenu under the Mbuzz menu) — a read-mostly dashboard: each detected CF7 form with its track-as/type, "Tracked / Not tracked" status, and a link into the form's editor panel. Plus global toggles (identity-on-login) stored in the existing settings option via the Settings API. Re-check `current_user_can('manage_options')`; **escape every CF7-supplied field name** (`esc_attr`/`esc_html`) wherever echoed.
- [ ] **3.4** Accessibility: a `<label>` bound to every `<select>`/input in the map table; keyboard-navigable rows; status not by colour alone (per WP admin a11y standards).

### Phase 4 — Wiring, privacy, docs, release

- [ ] **4.1** Menu: keep the top-level **Mbuzz** menu (shipped 0.1.2) but make both **Settings** and **Conversions** `add_submenu_page` children of it. (Top-level is convention-justified only because the plugin is now multi-screen; a single page would be a Settings submenu.)
- [ ] **4.2** Register engine in `Plugin.php`; ensure the master switch / `track_admins` still gate.
- [ ] **4.3** **Privacy API** (required now, not deferred — this feature is what sends PII, incl. potentially children's data, to a third party): register `wp_privacy_personal_data_exporters` + `wp_privacy_personal_data_erasers` for the captured lead/identity data so it's discoverable and removable.
- [ ] **4.4** Update `readme.txt`, `README.md` (setup walkthrough), bump version, changelog.
- [ ] **4.5** Fix the stale `MBUZZ_ATTRIBUTION_VERSION` constant (diagnostics shows 0.1.0-alpha).

---

## Testing Strategy

TDD — every acceptance criterion below maps to a named test. Tooling already in repo: PHPUnit 10 + Brain Monkey + Mockery (`tests/Unit/...`).

### Unit tests

| Test | File | Verifies |
|------|------|----------|
| resolves canonical traits | `FieldMapTest::testMapsIdentityFieldsToCanonicalTraits` | `CustomerEmail/First/Last/Mobile` → `email/first_name/last_name/phone`; `user_id` = email |
| renames properties | `FieldMapTest::testMapsPropertyFieldsToNamedKeys` | `TeamSize` → `team_size` |
| ignore role drops field | `FieldMapTest::testIgnoredFieldsNeverEmitted` | role `ignore` (incl. child PII) absent from traits + properties |
| multi-value handling | `FieldMapTest::testMultiValueFieldKeepsArrayInPropertiesScalarInIdentity` | array field → array property, first scalar for identity |
| missing field tolerated | `FieldMapTest::testMissingMappedFieldSkipped` | optional field absent → no error |
| revenue parsing | `FieldMapTest::testRevenueAndCurrencyRoles` | numeric → float; non-numeric omitted |
| page → location | `FieldMapTest::testCapturePageAsProperty` | `capture_page_as='location'` → `location` = page title |
| auto-detect identity | `AutoDetectorTest::testDetectsEmailFirstLastPhone` | regex/validation detection |
| auto-detect ignores tracking | `AutoDetectorTest::testIgnoresGclidFbclidUtm` | `gclid/utm_*/source` → ignore |
| auto-detect PII guard | `AutoDetectorTest::testChildAndDobDefaultToIgnore` | `Child1FirstName`, `Child1DOB` → ignore by default |
| store round-trip + sanitize | `FieldMapRepositoryTest::*` | save→load identity; unknown role → ignore; empty type → slug; dup keys handled |
| opt-in: no map → nothing | `TrackingEngineTest::testUnmappedFormFiresNothing` | the core behavior change |
| conversion path | `TrackingEngineTest::testConversionFiresIdentifyThenConversion` | identify + conversion(type) with mapped payload |
| event path | `TrackingEngineTest::testEventPathFiresEventNotConversion` | event(type), no conversion |
| short-circuits | `TrackingEngineTest::testSkipsWhenDisabledOrNoKey` | master off / `sdkReady=false` |
| CF7 adapter | `ContactForm7Test` (rewritten) | status gate; builds FormSource; page from `_wpcf7_container_post`; delegates to engine |
| admin sanitize | `FormMapFieldsTest::*` | role/key/type sanitization; nonce/capability |

Transport-capture seam (`Mbuzz::getClient()->setTransport`) asserts the actual `/identify`, `/conversions`, `/events` payloads, as in the existing `ContactForm7Test`/`WooCommerceTest`.

### Manual QA (wp-env)

1. Install zip, set key, open **Mbuzz → Conversions**: downtown form 7 listed, suggested map pre-filled (email/first/last/phone identity, children ignored).
2. Set type `enquiry`, `capture_page_as=location`, save.
3. Submit form (Acme-shaped fields) in wp-env → assert in debug.log: `identify` (canonical traits, no child data) + `conversion('enquiry')` with `location`, `team_size`, **no** `Child1*`.
4. A second, unmapped CF7 form → submit → **nothing** fires.

---

## Definition of Done

- [ ] All Phase 1–4 tasks complete; spec checked off.
- [ ] Full unit suite green; new tests cover every "All States" row.
- [ ] Manual QA in wp-env passes (mapped fires correctly; unmapped fires nothing; child PII excluded unless opted in).
- [ ] Version + changelog bumped; `MBUZZ_ATTRIBUTION_VERSION` constant fixed.
- [ ] Spec updated with final decisions, then archived.

---

## Key Decisions

| Decision | Choice | Why |
|----------|--------|-----|
| Auto-fire vs opt-in | **Opt-in** (no map → nothing) | Stops unconfigured PII/noise firing on live sites; the admin controls capture. |
| Trait schema | **none — fully arbitrary** | It's not the plugin's place to dictate what a customer sends. Admin maps any field to any trait name; the backend hashes `email`/`phone`/`first_name`/`last_name` for ad-matching independently, but the plugin stays a pass-through. |
| `user_id` source | **admin-designated; a non-PII unique ID preferred** | The customer's join key is theirs to choose — ideally a generated/CRM id so PII never reaches mbuzz. The plugin must never auto-default it to email. |
| PII fields default | `ignore` | Children's names/DOBs must be a conscious opt-in for a business. |
| Location source | page title via `_wpcf7_container_post` (+ url/id for audit) | The location is the page, not a field; works across enquiry + open-day forms. |
| Engine vs CF7-specific | generic engine + `FormSource` adapters | GF/WPForms/Fluent plug in later without touching mapping logic. |
| Storage | per-form map in the form's **post meta**; small global option (`autoload=false`) for cross-form toggles | Per-form config belongs with the form (Pass 2: that's where Gravity/Fluent put it) — travels on export/import, no orphaned rows, never autoloaded. |
| Config surface | **in the CF7 form editor** (panel), central screen = overview only | Pass 2: best-in-class integrations map per-form where you edit the form; a central screen is a dashboard, not the build surface. |
| UX differentiator | auto-mapping (`AutoDetector`) pre-fills | Pass 2: every incumbent is manual-only; pre-filled maps = the "seamless" edge. |

---

## WordPress best-practices alignment (researched 2026-05-30, cited)

A deep-research pass against authoritative sources (WordPress Developer Handbook, WPVIP security docs, WordPress Coding Standards, Plugin Guidelines) confirmed the core design and surfaced a few required adjustments. 22 claims verified, 3 refuted.

**Confirmed — keep as-is:**

- **One serialized option, not a custom table.** The Handbook reserves custom tables for data that *accumulates with use*; setup/config "should use the WordPress options mechanism." Our single JSON option (`mbuzz_attribution_form_maps`) is correct. — [creating-tables-with-plugins](https://developer.wordpress.org/plugins/creating-tables-with-plugins/), [apis/options](https://developer.wordpress.org/apis/options/)
- **Settings API + `register_setting` `sanitize_callback`.** Posting to `options.php` gives capability + nonce + `option_page`-tampering protection for free via `settings_fields()`; `sanitize_callback` is the canonical pre-save sanitization hook. Both the existing Settings screen and the new Conversions screen use this. — [settings-api](https://developer.wordpress.org/plugins/settings/settings-api/), [register_setting](https://developer.wordpress.org/reference/functions/register_setting/)
- **Capability re-check in the page callback** beyond the menu capability (admin pages are URL-reachable). — [add_options_page](https://developer.wordpress.org/reference/functions/add_options_page/)
- **Autoload** — superseded by Pass 2 §D: per-form maps now live in **post meta** (never autoloaded); the small global toggle option is set `autoload=false` explicitly (WP 6.6 changed the default to `null`).

**Required adjustments (folded into the tasks above):**

1. **Menu (Decision updated).** Convention puts a *single* config page as a **Settings submenu** (`add_options_page`), and the "top-level menu pairs with a matching submenu slug" pattern was *refuted* (0-3). We keep the top-level **Mbuzz** menu (shipped 0.1.2, owner asked for discoverability) **only because the plugin is now genuinely multi-screen** — the Handbook reserves top-level menus for "plugins introducing many related screens." → top-level **Mbuzz** parent with **Settings** + **Conversions** as `add_submenu_page` children (task 4.1). — [administration-menus/sub-menus](https://developer.wordpress.org/plugins/administration-menus/sub-menus/)
2. **Dynamic-input sanitization (task 3.2).** The field-map is a variable-key key→value structure → array sanitization, never single-value. — [WPCS: Sanitizing array input](https://github.com/WordPress/WordPress-Coding-Standards/wiki/Sanitizing-array-input-data)
3. **Late output escaping (task 3.3).** CF7-supplied field names are echoed into the UI → escape at output. — [apis/security/escaping](https://developer.wordpress.org/apis/security/escaping/)
4. **Privacy API exporter + eraser is now required (task 4.3), not deferred** — this feature is the thing that ships PII (incl. potentially children's data) to a third party. — [adding-the-personal-data-exporter](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/)

**Flagged — needs non-engineering input:**

- **Legal posture is directional, not verified.** Ignore-by-default for child fields aligns with *data-minimization* principles, but the specific obligations — GDPR lawful basis/consent, the Australian Privacy Act 1988 APPs (esp. **APP 8 cross-border disclosure** when PII goes to mbuzz's servers), and the OAIC's draft **Children's Online Privacy Code** — could not be verified as engineering facts and need **legal review** for a client handling children's data. Opt-in-by-default is the right *default*, not a compliance guarantee.
- **Not covered by the external literature** (grounded elsewhere, not a gap in the design): the CF7/`FormSource` integration layer (verified empirically in wp-env, §6 of the SDK plugin spec), and `php-scoper` bundling / `readme.txt` / prefixing / beta-distribution rules (standard WP.org submission requirements, tracked at submission time per SDK spec §2/§14).

---

## Best-practices research — Pass 2 (UX, integration architecture, autoload, privacy, distribution)

Second deep-research pass (24 sources, 24 claims verified, 1 refuted). This one changes the **UX surface**, not just details.

### A. Configure per-form, where the form is edited — not (only) a central screen

**Finding (high confidence):** the gold-standard integrations put field mapping **at the form level**, inside the form's own editor, not on a global screen. Gravity Forms uses per-form **feeds** (`GFFeedAddOn`); Fluent Forms nests it under each form's **Settings → Integrations**. — [GFFeedAddOn](https://docs.gravityforms.com/gffeedaddon/), [Fluent CRM integration](https://fluentforms.com/docs/fluentcrm-integration-with-fluent-forms/)

**Revision to the design:**
- **Primary surface = in the form editor, per form.** For Contact Form 7 (Acme's plugin), add a **"Mbuzz" panel to the CF7 form editor** via the `wpcf7_editor_panels` filter, saving the map to the form's post meta on `wpcf7_save_contact_form`. The admin maps the form *while editing the form* — the seamless path. (CF7 verified empirically; its panel/save hooks are the native extension point.)
- **Central "Mbuzz → Conversions" screen is demoted to an overview** — lists which forms are tracked, conversion type, last-fired, and links into each form's editor panel. It's a dashboard, not where you build maps.
- Later form plugins use their native frameworks: Gravity = a `GFFeedAddOn` feed; Fluent = its Integrations tab; WPForms = its provider pattern. The `FormSource` interface keeps the engine identical underneath.

### B. Auto-mapping is our differentiator — lean into it

**Finding:** every incumbent's mapping is **manual and visual**. Gravity's only zero-config affordance is `default_value` (pre-select a field by ID); Fluent is fully manual (shortcode-arrow selector, "+" to add rows). No one smart-matches by field name/type. — [field_map](https://docs.gravityforms.com/field_map-field/), [Fluent CRM](https://fluentforms.com/docs/fluentcrm-integration-with-fluent-forms/)

→ Our `AutoDetector` (email by validation, first/last/phone by name, page→location, child-PII ignored) means the admin **confirms** a pre-filled map instead of building one. That's the seamless edge — keep it front-and-location. Emulate Gravity's `field_map` UI primitive: one dropdown per target, restrict by field type, pre-selected default.

### C. The `FormSource` abstraction — one post-submission hook per plugin (confirmed)

Each major form plugin exposes exactly one clean server-side hook firing **after** validation + storage + notifications — the right seam:

| Plugin | Hook | Values |
|---|---|---|
| Contact Form 7 | `wpcf7_submit` + `WPCF7_Submission::get_posted_data()` | keyed by field name; page via `_wpcf7_container_post` *(verified empirically)* |
| Gravity Forms | `gform_after_submission($entry, $form)` | `rgar($entry, $field_id)` — **dot sub-IDs** `1.3`/`1.6` for composite Name fields → GF maps by **field ID**, others by name |
| WPForms | `wpforms_process_complete($fields, $entry, $form_data, $entry_id)` | keyed arrays |
| Fluent Forms | `fluentform/submission_inserted($id, $formData, $form)` | keyed arrays |

Sources: [gform_after_submission](https://docs.gravityforms.com/gform_after_submission/), [rgar](https://docs.gravityforms.com/rgar/), [wpforms_process_complete](https://wpforms.com/developers/wpforms_process_complete/), [Fluent actions](https://developers.fluentforms.com/hooks/actions/). **Design note:** the `FormSource` interface must expose fields as `(id, name, label, value)` so the GF adapter (ID + sub-ID) and the keyed-array adapters normalize the same way.

### D. Autoload — explicitly disable it (WP 6.6+ context)

**Finding:** WordPress 6.6 (Jul 2024) changed the `add_option`/`update_option` autoload default from `yes` to `null` (core auto-decides); options **> 150 KB** (filterable via `wp_max_autoloaded_option_size`) are no longer autoloaded unless `true` is passed. WP 6.4 added `wp_set_option_autoload()`. Autoloaded options all load into the single `alloptions` cache on **every** request (admin/AJAX/REST/cron); on VIP, exceeding 1 MB returns HTTP 503. — [Make Core 2024-06-18](https://make.wordpress.org/core/2024/06/18/options-api-disabling-autoload-for-large-options/), [Make Core 2023-10-17](https://make.wordpress.org/core/2023/10/17/new-option-functions-in-6-4/), [WPVIP autoloaded options](https://docs.wpvip.com/wordpress-on-vip/autoloaded-options/)

→ Store the maps option with **autoload explicitly `false`** (`register_setting(..., ['autoload' => false])`), since it's read only on submit + on the config screen. Deterministic across WP versions; keeps the per-request blob lean.

### E. Privacy & consent (sources identified; **legal review required**, not an engineering decision)

These sources were fetched and are authoritative, but adversarial verification didn't complete this pass — treat as *directionally cited*, confirm with counsel:

- **WordPress Consent API** — register the plugin as consent-aware and **gate tracking on `wp_has_consent('statistics'/'marketing')`** (the SDK plugin spec §11 already plans this; when the Consent API is absent, fall back to current behavior). — [wpconsentapi.org](https://wpconsentapi.org/), [wp-consent-level-api](https://github.com/WordPress/wp-consent-level-api)
- **Australian Privacy Act 1988 — APP 8 (cross-border disclosure):** sending PII to mbuzz's servers is a cross-border disclosure with accountability obligations. — [OAIC APP 8](https://www.oaic.gov.au/privacy/australian-privacy-principles/australian-privacy-principles-guidelines/chapter-8-app-8-cross-border-disclosure-of-personal-information)
- **OAIC Children's Online Privacy Code** (in development) — directly relevant to a site that collects family data capturing children's data. — [OAIC issues paper 2025](https://www.oaic.gov.au/__data/assets/pdf_file/0025/254662/Childrens-Online-Privacy-Code-Issues-Paper-2025-2-7-2025.pdf)

→ The spec's posture (ignore-child-fields-by-default + data-minimization + Privacy API exporter/eraser + Consent-API gating) is the defensible engineering default, but **whether opt-in/consent is legally required for this client is a legal question** — flag for the owner, don't settle in code.

### F. Distribution, i18n, accessibility (sources identified)

- **Bundled deps:** namespace-prefix the bundled `mbuzz-php` with **php-scoper** (`Mbuzz\Vendor\`, per SDK spec §2) to avoid collisions with another plugin shipping a different mbuzz-php; prefix global functions/options/hooks; no remote code loading; `readme.txt` stable tag must match. Tracked at WP.org submission. — [plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/), [safely using PHP deps](https://yoast.com/developer-blog/safely-using-php-dependencies-in-the-wordpress-ecosystem/)
- **i18n:** text domain `mbuzz-attribution`, all UI strings through `__()/esc_html__()` (already the pattern). — [internationalize your plugin](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/)
- **Accessibility:** the mapping screen must meet WP admin a11y standards — a `<label>` for every select, keyboard-navigable rows, status not conveyed by colour alone. — [WP accessibility standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/accessibility/)

### Net design changes from Pass 2

1. **Phase 3 reworked** (below): per-form **CF7 editor panel** (`wpcf7_editor_panels`) as the primary config surface; the central Conversions screen becomes an overview/dashboard.
2. **Storage:** autoload **explicitly false**.
3. **`FormSource`** exposes `(id, name, label, value)` to absorb Gravity's composite sub-IDs.
4. **Consent-API gating + Privacy exporter/eraser** confirmed in-scope; **legal posture flagged** for the owner.
5. **Auto-mapping** positioned as the headline UX differentiator.

---

## Form-plugin coverage

**There is no universal WordPress "form submitted" hook** — each form plugin has its own submission lifecycle (confirmed in Pass 2: CF7 `wpcf7_submit`, Gravity `gform_after_submission`, WPForms `wpforms_process_complete`, Fluent `fluentform/submission_inserted`). So coverage is per-adapter, in three tiers:

1. **Supported plugins → the no-code editor-panel UI.** One `FormSource` adapter per plugin (a single post-submission hook + field extraction normalized to `(id, name, label, value)`). **v1: Contact Form 7** (covers Acme and the most-installed form plugin). Adapter roadmap, prioritized by install base: **WPForms, Elementor Pro forms, Gravity Forms, Ninja Forms, Fluent Forms**, then Formidable / Forminator and the WordPress **core Form block** as it matures. Each adapter is small because the engine, mapping, storage, and UI are plugin-agnostic — only the hook + field-shape differ (e.g. Gravity's composite sub-IDs). Page-builder forms (Elementor, Divi, Bricks) each need their own adapter.
2. **Long tail / custom / theme / page-builder forms → developer escape hatch.** The procedural helpers (`mbuzz_conversion()`, `mbuzz_identify()`, `mbuzz_event()`, already shipped) wire **any** form from a few lines in its submit handler. Universal, but code rather than the no-code panel.
3. **Generic client-side auto-capture → explicitly rejected.** Listening to any `<form>` submit in JS would catch unknown forms but requires the JS pixel (contradicts the server-side-only architecture), misses AJAX-submitted forms, and captures fields/PII uncontrolled (the exact problem this spec removes).

**Coverage decision:** ship CF7; make further adapters a prioritized backlog by reach; rely on the helpers for everything else. "All WordPress forms" is achievable only via tier 1 (over time) + tier 2 (today, with code) — never via a single automatic mechanism.

---

## Documentation & in-plugin guidance

Config lives at the form level, but the admin still needs a **single place that explains the whole thing and points them to it** — and we should have **comprehensive hosted docs the plugin links to**. Two layers:

### In-plugin guidance (the central Mbuzz section is a help hub, not just a dashboard)

- **Mbuzz → Conversions overview** opens with a short **"How mbuzz tracking works"** explainer — sessions (automatic), **identity**, **events**, **conversions** — in plain language, then "Set up a form" with a **direct link/button into each detected form's editor panel**. Shows key/connection status and a "Last successful API call" line (reuse the diagnostics card).
- **Inline help in the CF7 editor panel**: per-row descriptions for what each role means (`identity/email` vs `property` vs `ignore`), **why child/DOB fields default to ignore** (privacy), and what "capture page as `location`" does. Tooltips + `description` text on every control.
- **Empty states** (Pass 2 UX best practice): "No forms tracked yet — open a form and add its Mbuzz panel," with a CTA, rather than a blank screen.
- All strings translatable (`__()/esc_html__()`, text domain `mbuzz-attribution`) and a11y-labelled.
- A persistent **"Documentation"** link on both surfaces → the hosted docs below.

### Hosted documentation (the comprehensive reference we link to)

One source of truth on mbuzz.co — the WordPress integration docs (`multibuzz/app/views/docs/_integrations_wordpress` + getting-started) — covering the **entire** picture so the in-plugin UI, `readme.txt`, and the GitHub README can all link to it:

- Install → activate → **add API key** (Mbuzz menu).
- **Concepts:** session/touchpoint, identity, event, conversion — what each is and when it fires.
- **Configure a form:** the editor panel, auto-mapping, field roles, naming, capture-location — with the downtown example.
- **The two-system model:** the lead is captured here; the **paid conversion is posted later by email** from the CRM or billing system and stitches back to the journey (depends on the `develop` backend deploy).
- **Privacy & consent:** Consent-API gating, what's captured, ignore-by-default for sensitive/child fields, the Privacy exporter/eraser, and a pointer to the owner's legal-review responsibilities (APP 8, Children's Code).
- **Developer helpers:** `mbuzz_conversion()/identify()/event()` for unsupported/custom/page-builder forms (tier-2 coverage).
- **Per-form-plugin guides:** added as each adapter ships (CF7 first).
- **Troubleshooting:** diagnostics card, `MBUZZ_DEBUG` log.

This **expands the docs task already in `multibuzz/lib/specs/wordpress_integration_spec.md`** (Implementation Tasks 3.2–3.4) from an "integration stub" into the full setup walkthrough above. Linked from: the plugin's overview screen + editor panel ("Learn more"), `readme.txt`, the GitHub README, and the multibuzz onboarding install partial.

---

## Out of Scope (this spec)

- **Tracked Button / event block** for click events (separate — SDK spec §8). The `track_as: event` plumbing here is the server-side half only.
- **Other form plugins** (GF, WPForms, Fluent, EDD) — the `FormSource` interface is designed for them; their adapters are follow-ups.
- **WooCommerce** mapping UI — Woo has its own fixed order→purchase shape; revisit whether it joins this model later.
- **WP Consent API gating** (SDK spec §11) — orthogonal; applies on top of any map.
- **Per-field transforms** (formatting, concatenation, computed values) — v1 maps 1:1 field→target.
- **multibuzz changes** — none; this is plugin-only and uses the existing API contract.

---

## Open Questions

1. Should `capture_page_as` also derive a normalized slug (`downtown`) alongside the raw page title, or is the title enough for dashboard grouping? (Lean: store both — `location` = title, `location_slug` = sanitized — decide during Phase 1.)
2. Do we want a global "default map for all CF7 forms" fallback, or strictly per-form? (Lean: per-form only in v1; a default invites the same accidental-capture problem.)
3. Identity-on-login (existing `Identity\Hooks`) — fold its on/off toggle into this Conversions screen, or leave separate? (Lean: surface it on the same screen for one coherent "what gets tracked" view.)
