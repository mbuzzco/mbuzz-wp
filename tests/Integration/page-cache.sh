#!/usr/bin/env bash
#
# Reproduces the page-cache attribution failure against a real WordPress.
#
# A full-page cache (Cloudflare "Cache Everything", WP Rocket, Varnish, LiteSpeed)
# serves HTML without invoking PHP. Nothing mints `_mbuzz_vid`, so no session is
# created and every later event or conversion is dropped by the SDK for having no
# visitor — silently, with no HTTP call and nothing logged.
#
# Unit tests cannot see this: every layer is correct in isolation. It only appears
# with a cache between the browser and the app, so this test puts one there.
#
# Usage:  bash tests/Integration/page-cache.sh
# Needs:  wp-env running (npx @wordpress/env start), docker, curl
#
# Exit 0 = the plugin survives a full-page cache. Exit 1 = it does not.
set -uo pipefail

WP_URL="${WP_URL:-http://localhost:8888}"
PROXY_PORT="${PROXY_PORT:-8899}"
PROXY_URL="http://localhost:${PROXY_PORT}"
PROXY_NAME="mbuzz-cache-proxy"
COOKIE="_mbuzz_vid"

pass=0; fail=0
ok()   { echo "  ✓ $1"; pass=$((pass+1)); }
bad()  { echo "  ✗ $1"; fail=$((fail+1)); }
info() { echo "→ $1"; }

cleanup() { docker rm -f "$PROXY_NAME" >/dev/null 2>&1 || true; rm -f /tmp/mbuzz-cache-*.conf; }
trap cleanup EXIT

# --- preflight -------------------------------------------------------------

if ! curl -sf -o /dev/null "$WP_URL/"; then
  echo "wp-env is not responding at $WP_URL — run: npx @wordpress/env start" >&2
  exit 2
fi

# --- the cache -------------------------------------------------------------
#
# Two modes, both documented real-world behaviour:
#   default : a cache HIT serves HTML without invoking PHP
#   strip   : Cloudflare under "Cache Everything" + an Edge TTL override removes
#             Set-Cookie and caches the asset, so even a MISS loses the cookie
#             https://developers.cloudflare.com/cache/concepts/cache-behavior/

start_proxy() {
  local mode="$1"
  local conf="/tmp/mbuzz-cache-${mode}.conf"
  docker rm -f "$PROXY_NAME" >/dev/null 2>&1 || true

  local strip=""
  if [ "$mode" = "strip" ]; then strip="proxy_hide_header Set-Cookie;"; fi

  cat > "$conf" <<CONF
proxy_cache_path /tmp/nc levels=1:2 keys_zone=z:10m inactive=60m;
server {
  listen ${PROXY_PORT};

  # The REST API is never full-page cached. WP Rocket and Cloudflare APO both
  # exclude it by default, which is why it is a safe place to mint. Modelling
  # the proxy as caching /wp-json too would test a configuration nobody runs.
  location /wp-json/ {
    proxy_pass http://host.docker.internal:8888;
    proxy_set_header Host \$host:${PROXY_PORT};
    add_header X-Cache-Status "BYPASS" always;
  }

  location / {
    proxy_pass http://host.docker.internal:8888;
    proxy_set_header Host \$host:${PROXY_PORT};
    proxy_cache z;
    proxy_cache_valid any 10m;
    proxy_cache_key "\$scheme\$request_method\$host\$request_uri";
    # A full-page cache ignores the origin's cookie/no-cache signals — that is
    # exactly what "Cache Everything" does, and what makes this bug possible.
    # "Cache Everything" caches regardless of what the origin says — including
    # responses carrying Set-Cookie, which is what makes visitor collapse possible.
    proxy_ignore_headers Set-Cookie Cache-Control Expires Vary X-Accel-Expires;
    ${strip}
    add_header X-Cache-Status \$upstream_cache_status always;
  }
}
CONF

  docker run -d --name "$PROXY_NAME" -p "${PROXY_PORT}:${PROXY_PORT}" \
    --add-host=host.docker.internal:host-gateway \
    -v "$conf:/etc/nginx/conf.d/default.conf:ro" nginx:alpine >/dev/null

  for _ in $(seq 1 30); do
    curl -sf -o /dev/null "$PROXY_URL/" && return 0
    sleep 1
  done
  echo "proxy did not come up" >&2; exit 2
}

visitor_cookie_from() {  # $1 = url
  curl -s -i "$1" | tr -d '\r' | sed -n "s/^[Ss]et-[Cc]ookie: ${COOKIE}=\([^;]*\).*/\1/p" | head -1
}

cache_status_of() { curl -s -o /dev/null -D - "$1" | tr -d '\r' | sed -n 's/^X-Cache-Status: //p' | head -1; }

# --- helpers ---------------------------------------------------------------

session_cookie_from() {  # $1 = base url — POST the session endpoint as the page script does
  curl -s -i -X POST "$1/wp-json/mbuzz/v1/session" \
    -H "Content-Type: application/json" -H "Origin: $1" \
    -d '{"url":"'"$1"'/","referrer":""}' \
    | tr -d '\r' | sed -n "s/^[Ss]et-[Cc]ookie: ${COOKIE}=\([^;]*\).*/\1/p" | head -1
}

# --- the tests -------------------------------------------------------------

echo
info "Baseline: a page response must NOT carry the visitor cookie"
direct_cookie="$(visitor_cookie_from "$WP_URL/?nocache=$RANDOM")"
if [ -z "$direct_cookie" ]; then
  ok "a page response mints nothing, so a cache cannot replay one visitor's id to everyone"
else
  bad "a page response carries Set-Cookie — a cache would hand every visitor the same id"
fi

info "Baseline: the uncached endpoint establishes the visitor"
endpoint_cookie="$(session_cookie_from "$WP_URL")"
if [ -n "$endpoint_cookie" ]; then
  ok "the session endpoint mints a visitor (server-set, HttpOnly, full lifetime)"
else
  bad "the session endpoint mints nothing — check the API key is set"
fi

echo
info "Mode 1: a cache HIT serves HTML without invoking PHP"
start_proxy default
curl -s -o /dev/null "$PROXY_URL/"                      # prime the cache
status="$(cache_status_of "$PROXY_URL/")"
[ "$status" = "HIT" ] && ok "the proxy is serving from cache (X-Cache-Status: HIT)" \
                      || bad "expected a cache HIT, got '${status:-none}' — the test is not exercising the bug"

cached_cookie="$(session_cookie_from "$PROXY_URL")"
[ -n "$cached_cookie" ] && ok "a visitor is established from a cached page (via the endpoint)" \
                        || bad "no visitor established behind a cache — every submission is dropped"

echo
info "Mode 1b: two visitors on the same cached page must stay distinct"
a="$(session_cookie_from "$PROXY_URL")"
b="$(session_cookie_from "$PROXY_URL")"
if [ -n "$a" ] && [ "$a" = "$b" ]; then
  bad "both visitors received the SAME id (${a:0:16}…) — a cached Set-Cookie merges strangers into one journey"
elif [ -z "$a" ] && [ -z "$b" ]; then
  bad "neither visitor was established — nothing from this page can be attributed"
else
  ok "visitors are not handed a shared id"
fi

echo
info "Mode 2: Cloudflare strips Set-Cookie under Cache Everything"
start_proxy strip
curl -s -o /dev/null "$PROXY_URL/"
stripped_cookie="$(session_cookie_from "$PROXY_URL")"
[ -n "$stripped_cookie" ] && ok "a visitor is established even when Set-Cookie is stripped" \
                          || bad "Set-Cookie stripped ⇒ no visitor, so nothing can be attributed"

echo
echo "─────────────────────────────────────────"
echo "  passed: $pass   failed: $fail"
[ "$fail" -eq 0 ] && echo "  the plugin survives a full-page cache" \
                  || echo "  attribution is lost behind a full-page cache"
echo "─────────────────────────────────────────"
[ "$fail" -eq 0 ]
