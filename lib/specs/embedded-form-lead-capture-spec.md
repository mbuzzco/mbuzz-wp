# Embedded / External Form Lead Capture

**Date:** 2026-06-07
**Priority:** P1 (unlocks capture of JS-embedded third-party forms that never hit WordPress)
**Status:** Draft
**Branch:** `feat/embedded-form-capture`
**Repo:** `mbuzz-wp` (plugin). Format follows `multibuzz/lib/specs/GUIDE.md`.

---

## Spike First (validate assumptions before building)

This design rests on four assumptions that are cheaper to test than to build around.
**Spike them with throwaway code before writing any plugin code** — the entire flow can
be proven end-to-end using a code-snippets plugin (server half) + one page edit (client
half), touching nothing in the repo and fully reversible.

### Risky assumptions

1. **Vendor callback fires on success and yields an id.** The embedded widget invokes a
   success callback we can hook, passing a usable reference (e.g. a lead id).
2. **Field values are still readable at callback time.** The submitted values (email,
   phone, …) remain in the DOM when the callback fires — they may be torn down by a
   confirmation screen or redirect. *(Highest-risk assumption.)*
3. **HttpOnly `_mbuzz_vid` is readable server-side** on a same-origin first-party REST
   request (the whole premise — client JS can't read it, the endpoint can).
4. **The lead lands with a real `visitor_id`**, surviving any post-submit redirect
   (navigation race) via `sendBeacon`/keepalive.

### Spike A — server half (throwaway REST route via a snippets plugin)

Proves #3 and #4 by reusing the shipped `mbuzz_*` helpers. Run-everywhere snippet:

```php
add_action('rest_api_init', function () {
    register_rest_route('mbuzz-spike/v1', '/lead', [
        'methods'  => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) {
            $b = $req->get_json_params();
            $vid = $_COOKIE['_mbuzz_vid'] ?? '(none)';            // assumption #3
            error_log('[mbuzz-spike] vid=' . $vid . ' body=' . wp_json_encode($b));
            if (function_exists('mbuzz_identify') && !empty($b['user_id'])) {
                mbuzz_identify($b['user_id'], $b['traits'] ?? []);
            }
            if (function_exists('mbuzz_event')) {
                mbuzz_event($b['type'] ?? 'lead_spike', $b['properties'] ?? []);
            }
            return new WP_REST_Response(null, 204);
        },
    ]);
});
```

### Spike B — client half (one embed instance)

Wire the vendor widget's success callback (or a capture-phase `submit` listener for a
native embed) to read fields and beacon them. Proves #1, #2, #4:

```js
// inside the embed's success callback (receives the vendor's lead id):
function (leadId) {
  var q = function (key) {            // selector pattern is vendor-specific
    var el = document.querySelector('[data-field="' + key + '"]');
    return el ? el.value : null;
  };
  var payload = {
    type: 'lead_spike',
    user_id: q('email'),
    traits: { phone: q('phone'), first_name: q('firstName'), last_name: q('lastName') },
    properties: { location: '<from page>', external_lead_id: leadId }
  };
  console.log('[mbuzz-spike] callback', leadId, payload);  // #1 + #2
  navigator.sendBeacon('/wp-json/mbuzz-spike/v1/lead',
    new Blob([JSON.stringify(payload)], { type: 'application/json' }));  // #4
}
```

### Pass/fail signals

| Observe | Confirms |
|---------|----------|
| Browser console logs the callback + a populated payload | #1 callback fires, #2 fields readable (null values → read on submit-click instead) |
| `debug.log` shows `[mbuzz-spike] vid=<hex>` (not `(none)`) | #3 HttpOnly cookie readable server-side |
| mbuzz dashboard Events shows the spike event **with a `visitor_id`** | #4 lands + survives redirect |

Use a throwaway event name (`lead_spike`) so test data is trivial to delete. Test
logged-out / single browser window (admins are excluded; a churned cookie breaks #4).
**If #2 fails** (fields gone at callback time), revise the spec to read field values on
the submit-click *before* the vendor tears the form down — decide this *before* building.

**Exit criteria:** all four green → lock the spec, build the real endpoint (sanitization,
origin gate, enqueue, TDD) as hardening of a proven path. Then delete the snippet and
revert the page edit.

### Spike result (2026-06-07) — VALIDATED, with one design lesson

Ran against a live embedded vendor widget (success callback → throwaway snippet →
throwaway REST route reusing `mbuzz_identify`/`mbuzz_event`). All four assumptions held:

| # | Assumption | Result |
|---|-----------|--------|
| 1 | Callback fires on success, yields a lead id | ✅ console logged the callback + id |
| 2 | Field values readable at callback time | ✅ email/phone/name all populated (the riskiest one — confirmed safe) |
| 3 | HttpOnly `_mbuzz_vid` readable server-side | ✅ endpoint received the POST; identity persisted |
| 4 | Lead lands attributed | ✅ `identify` landed (identity created server-side); **the `event` was correctly rejected `422 visitor_not_found`** — see lesson below |

**Design lesson that changes the build (the #4 nuance):** the throwaway snippet called
`identify` then `event(type, properties)` with **no `identifier` on the event**. On a
fresh visitor whose `/sessions` hadn't landed yet, the event hit the backend's
"bare cookie, no identifier → 422" guard (see multibuzz `events_visitor_resolution_
create_on_demand.md`) and was dropped, even though `identify` succeeded. **The
production endpoint MUST send the identifier *on the event* (not only via a separate
identify call)** so the backend's create-on-demand resolves/creates the visitor and the
event is accepted. This is now a hard requirement below (see Proposed Solution + All
States), not an open question.

Side finding (separate follow-up, not blocking): a phone trait in AU local format
(`"0449 393 933"`) did **not** hash server-side (`phone_e164_sha256` was empty) — the
backend normalizer expects E.164. Tracked as a separate concern (plugin-side E.164
normalization, or backend accepting local formats); does not block this feature.

---

## Summary

Give site owners a way to feed **client-side / third-party embedded forms** into mbuzz
— forms that render and submit entirely in the browser (often POSTing to a vendor's
own domain) and therefore **never trigger a WordPress server hook** the way Contact
Form 7 does. A small first-party REST endpoint plus a tiny documented JS helper let a
theme/snippet report a lead on submit; the endpoint resolves the visitor server-side
(from the `_mbuzz_vid` cookie) and fires the same `identify` + `event` path the CF7
integration already uses. Tracking stays **opt-in, schema-agnostic, and PII-minimal**:
the site owner chooses what to send; the plugin never auto-scrapes field values.

Non-technical framing: *"My booking/enquiry widget is loaded from another company and
posts straight to them — WordPress never sees it. This gives me one line of JS to tell
mbuzz 'a lead just happened, here's the email,' so it still attributes to the visitor's
marketing journey."*

---

## Current State

- `Tracking\TrackingEngine` + `Integrations\FormSource` handle **server-side** form
  submissions (CF7 today): a WP hook fires, the engine resolves the saved map, and
  calls `identify`/`event`/`conversion`.
- The plugin has **no REST routes** (`grep register_rest_route` → none) and **no JS
  enqueue infrastructure** (`grep wp_enqueue_script` → none).
- Theme/snippet code can call `mbuzz_event()` / `mbuzz_identify()` (PHP helpers in
  `src/helpers.php`) — but only from **server-side** PHP. There is no path for a
  **browser-side** event from a form WordPress never processed.
- The visitor id (`_mbuzz_vid`) is an **HttpOnly** cookie (`Visitor\CookieBootstrap`
  / SDK `CookieManager`), so client JS cannot read it to attach to a call itself.

### The gap

A form that submits via JS to a third party (rendered into the page DOM by a vendor
script, or posting cross-domain via fetch/XHR) produces **no WordPress request**, so
neither the CF7 adapter nor any server hook sees it. The lead — and its marketing
attribution — is lost. (A cross-origin **iframe** form is a harder case; see Out of
Scope.)

---

## Proposed Solution

A **first-party capture endpoint** + a **scope-stable JS helper**, both thin glue over
the existing domain.

```
Embedded form submit (success, in the browser)
  → site-owner snippet calls  window.mbuzz.captureLead({...})
  → POST (sendBeacon/fetch keepalive) to  /wp-json/mbuzz/v1/lead
      → endpoint runs in WordPress with the request's cookies
      → reads _mbuzz_vid SERVER-SIDE (HttpOnly is fine here)
      → Consent::granted()? else 204 no-op
      → Mbuzz::identify(user_id, traits)              (when a user_id is supplied)
      → Mbuzz::event(type, properties, identifier)    (identifier ALWAYS passed when user_id present)
  → 204 No Content
```

The browser never needs the visitor id; the endpoint resolves it from the cookie that
rides the same-origin request. This keeps the server-side-only architecture intact and
sidesteps the HttpOnly limitation entirely.

**Hard requirement — identifier on the event (the spike's #4 lesson).** The event MUST
carry the `identifier` (the `user_id`), not rely on the preceding `identify` call to
have created the visitor. On a fresh visitor whose `/sessions` POST hasn't landed yet,
an event with a cookie but **no identifier** is rejected `422 visitor_not_found` by the
backend's anti-orphan guard; an event **with** an identifier triggers create-on-demand
and is accepted (see multibuzz `events_visitor_resolution_create_on_demand.md`). The
SDK's `event()`/`track()` accepts an identifier; the endpoint passes it whenever
`user_id` is present. (If the bundled SDK build doesn't surface an identifier arg on
`event()`, the endpoint sends `conversion`-style or the raw call that does — verify
against the bundled `mbuzz-php` at build time; do NOT ship the naive identify-then-bare-
event ordering the spike proved drops leads.)

### Why a JS helper (not just "call the REST route")

- **Scope-stable surface.** Like the PHP `mbuzz_*` helpers, `window.mbuzz.captureLead`
  is the documented, stable call site; the snippet on a page stays a one-liner even if
  internals change.
- **Navigation race handling.** Embedded forms often redirect on success; the helper
  uses `navigator.sendBeacon` (falling back to `fetch(..., {keepalive:true})`) so the
  POST survives the redirect — the client-side analogue of the server-side deferred-send
  race we already know about.
- **Consistency.** One helper, one endpoint, reused by any embed (not vendor-specific).

### The payload (schema-agnostic, owner-chosen)

```js
window.mbuzz.captureLead({
  type:    'lead',                 // event name (sanitized slug); required
  user_id: 'parent@example.com',   // join key (optional; a non-PII id is preferred)
  traits:  { phone: '...', first_name: '...', last_name: '...' }, // optional, owner-named
  properties: { location: 'Downtown', external_lead_id: 'abc123' } // optional, owner-named
})
```

- **`user_id`** → the cross-system join key (mirrors the CF7 role). When present, the
  endpoint calls `identify(user_id, traits)` **and** fires the `event` **carrying the
  identifier** (see Hard requirement above — the event must not be sent identifier-less).
  The later conversion (reported by the owner's other system) stitches on this same id.
- **`traits`** / **`properties`** → arbitrary owner-named keys. The plugin dictates no
  schema (consistent with the form-mapping spec). The owner reads these from their
  form's DOM in their snippet — the plugin never scrapes fields itself.
- The owner is responsible for calling this **only on genuine success** (e.g. from the
  vendor widget's success callback, or after validation), not on every attempt.

### Key Files

| File | Purpose | Change |
|------|---------|--------|
| `src/Rest/LeadController.php` | `register_rest_route('mbuzz/v1','/lead', …)`; validate + sanitize + delegate | **New** |
| `src/Rest/LeadRequest.php` | Value object: sanitize the JSON body (type slug, user_id, arbitrary traits/properties) — array sanitization, mirrors `Settings\FormMapFields` | **New** |
| `src/Integrations/EmbedFormSource.php` *(optional)* | A `FormSource` built from the payload so the **engine** path is reused verbatim | **New (consider)** |
| `assets/js/mbuzz-capture.js` | `window.mbuzz.captureLead(payload)` → sendBeacon/keepalive POST | **New** |
| `src/Plugin.php` | `rest_api_init` → register route; `wp_enqueue_script` the helper on front end | **Edit** |
| `src/Tracking/Source.php` | add `Source::EMBED = 'embed'` | **Edit** |

### Upgrade safety (no config loss on plugin update)

Shipping this feature is a normal **plugin file update** — it does not touch the
database, so **nothing already configured is lost**:

- Per-form maps live in **post meta** (`_mbuzz_form_map`) — DB, untouched by updates.
- API key + settings live in the **options table** — DB, untouched.
- Per-page embed snippets live in **page/post content** (e.g. WPBakery blocks) — DB,
  untouched.

A plugin update only swaps the PHP/JS in `wp-content/plugins/`. Once the JS helper
ships, per-page embed callbacks collapse from the inline spike block to a one-liner
(`callback: window.mbuzz.captureTourLead` or `callback: fn → window.mbuzz.captureLead`),
and the migration is incremental: existing inline snippets keep working until swapped.

---

## Security (a public, unauthenticated, cross-cutting write path — treat carefully)

This is the plugin's first **externally-POSTable** endpoint, so the bar is higher than
the CF7 panel (which rides CF7's nonce). Decisions:

- **Unauthenticated by design** (anonymous visitors submit leads), but:
  - **Same-origin gate.** Verify `Origin`/`Referer` is the site host; reject otherwise.
    A REST nonce is *not* reliably available to an arbitrary embed snippet, so origin
    is the practical gate. (Document the trade-off; allow a filter to tighten.)
  - **Consent gate.** `Consent::granted()` first — no consent → `204`, fire nothing.
  - **Master switch / sdkReady.** Same short-circuits as every other capture entry.
  - **Rate / size limits.** Cap body size; cap fields/keys count (reuse the
    property-key limits idea). Reject oversized payloads `400`.
  - **No reflection.** Endpoint returns `204 No Content` — never echoes input (no XSS
    surface), never confirms whether an id existed (no enumeration).
- **Array sanitization** (variable-key traits/properties), never single-value:
  `sanitize_key` mbuzz keys, `sanitize_text_field` values, slug the event `type`,
  validate/normalize `user_id` as text. Mirror `Settings\FormMapFields::sanitize`.
- **PII posture.** The plugin captures **only what the owner explicitly passes**. It
  never reads form fields itself (the research on Mixpanel/PostHog confirms event+
  allowlist, never raw values, as the safe default). Docs must warn: mapping email as
  `user_id` sends PII to mbuzz — a non-PII id is preferred.
- **`wp_unslash` then sanitize**; treat every value as hostile.

---

## All States

| State | Condition | Expected behavior |
|-------|-----------|-------------------|
| Happy path | valid body, consent on, sdk ready | `identify(user_id, traits)` (if user_id) → `event(type, properties)`; `204` |
| No user_id | body omits user_id | `identify` skipped; `event` still fires; attribution via visitor cookie |
| No consent | `Consent::granted()` false | nothing fires; `204` |
| Disabled / no key | master off or `sdkReady` false | nothing fires; `204` |
| Cross-origin POST | `Origin`/`Referer` ≠ site host | `403`; fire nothing |
| Missing `type` | required field absent | `event` name falls back to a default slug (`lead`)? **Open Q** — or `400`. |
| Oversized / too many keys | body exceeds caps | `400`; fire nothing |
| Empty / non-JSON body | malformed | `400` |
| Multi-value trait | array passed for a trait | identity uses first scalar; property keeps array (mirror `FieldMap::resolve`) |
| Visitor cookie absent | new/cookieless browser | event sent without visitor_id; backend resolves via user_id/create-on-demand (see multibuzz events spec) |

---

## Implementation Tasks (TDD order)

### Phase 1 — Endpoint + sanitization (pure-ish, WP mocked)
- [ ] **1.1** `Source::EMBED` constant.
- [ ] **1.2** RED `LeadRequestTest` — sanitization: type slug, user_id text, arbitrary
  trait/property keys via `sanitize_key`, value `sanitize_text_field`, multi-value rule,
  size/key caps, drop-unknown. GREEN.
- [ ] **1.3** RED `LeadControllerTest` — delegates to engine/SDK via the transport seam:
  identify-then-event, no-user_id path, consent off → no-op, origin mismatch → 403,
  disabled/no-key short-circuit. GREEN. (Brain Monkey for WP REST + cookie + origin.)

### Phase 2 — Wiring
- [ ] **2.1** `register_rest_route('mbuzz/v1', '/lead', …)` on `rest_api_init` in `Plugin`.
- [ ] **2.2** Enqueue `assets/js/mbuzz-capture.js` on the front end (respecting
  `track_admins` / non-admin, same gating as the session path).

### Phase 3 — JS helper
- [ ] **3.1** `window.mbuzz.captureLead(payload)` → sendBeacon (fallback fetch keepalive)
  POST JSON to the route; no-op if payload invalid. Tiny, dependency-free, CSP-friendly
  (external file, not inline).
- [ ] **3.2** Document the success-only contract + the navigation-race rationale.

### Phase 4 — Docs
- [ ] **4.1** README + hosted-docs section: "Capture an embedded / third-party form",
  with a **generic** example (success-callback → `captureLead`), the PII/`user_id`
  guidance, and the same-origin requirement. **No customer-specific vendor names.**

---

## Testing Strategy

Transport-capture seam (`Mbuzz::getClient()->setTransport(...)`) asserts the actual
`/identify` and `/events` payloads, exactly as `ContactForm7Test` does. Endpoint tests
mock the WP REST request, cookies, and `Origin` header via Brain Monkey. JS helper is
verified in wp-env, not unit tests (consistent with the templates policy).

| Test | Verifies |
|------|----------|
| `LeadRequestTest::*` | sanitization, caps, multi-value, slug fallback |
| `LeadControllerTest::testIdentifyThenEvent` | happy path payloads |
| `LeadControllerTest::testNoUserIdSkipsIdentify` | event-only path |
| `LeadControllerTest::testConsentOffNoOp` | consent gate |
| `LeadControllerTest::testRejectsCrossOrigin` | 403 on origin mismatch |
| `LeadControllerTest::testShortCircuitsWhenDisabled` | master off / no key |

### Manual QA (wp-env)
1. Page with a JS form posting to a dummy third-party; on its success callback call
   `window.mbuzz.captureLead({type:'lead', user_id:'a@b.com', traits:{phone:'…'}})`.
2. Submit logged-out → assert `identify` + `event('lead')` with visitor_id in debug.log.
3. Submit with no `_mbuzz_vid` → event still sent (cookieless); backend resolves.
4. Cross-origin `curl` to the route → `403`.

---

## Out of Scope

- **Cross-origin iframe forms.** A form rendered inside a `<iframe src="otherdomain">`
  is unreadable from the parent page by the same-origin policy — **no client-side
  capture is possible**. Requires the embed vendor to emit `postMessage`/a JS callback,
  or a **server-to-server webhook** from the vendor. Tracked separately; not solvable in
  this plugin.
- **Auto-capture of arbitrary forms.** This is an **opt-in, owner-instrumented** helper,
  not a generic DOM autocapture. Auto-scraping field values is explicitly rejected on
  PII grounds (consistent with the form-mapping spec's opt-in stance).
- **The downstream paid-conversion / CRM-enrolment webhook.** Closing the loop from the
  owner's CRM (conversion keyed on the same `user_id`) is a **multibuzz / server-to-
  server** concern, not WordPress-plugin surface.
- **Visual field-mapping UI for embeds.** v1 is code/snippet-driven (the owner reads
  their own form's fields). A no-code mapping panel could follow if demand warrants.
- **Phone → E.164 normalization.** The spike showed a local-format phone trait
  (`0449 393 933`) did not hash for ad-matching server-side. Whether to normalize in the
  plugin or fix the backend normalizer is a **separate follow-up**, not part of this
  feature. (The plugin still forwards the trait verbatim, schema-agnostic.)

---

## Open Questions

1. Missing `type`: default to `lead` slug, or hard `400`? (Lean: default to `lead` for
   resilience, mirroring `FieldMap::DEFAULT_TYPE`.)
2. Same-origin enforcement: `Origin` header only, or also a short-lived REST nonce
   localized into the enqueued script for first-party pages (tighter, but breaks the
   "paste one line anywhere" simplicity)? (Lean: Origin gate + optional nonce filter.)
3. Reuse `TrackingEngine` via an `EmbedFormSource` (a map applied server-side), or call
   `identify`/`event` directly from the controller with the already-resolved payload?
   (Lean: direct — the payload is already resolved client-side; a map adds nothing here.)
4. Should the helper also expose `window.mbuzz.captureConversion(...)` for owners whose
   embed *is* the paid step? (Lean: defer; leads first.)
