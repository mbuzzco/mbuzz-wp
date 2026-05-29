<?php
/**
 * Server-side visitor cookie bootstrap.
 *
 * The bundled PHP SDK deliberately never mints `_mbuzz_vid` — it only reads
 * an existing cookie ("no fallback - prevents orphan visitors", see
 * Context::initialize). In a JS-pixel deployment the pixel mints the cookie;
 * in a server-side-only deployment nothing would, so `initFromRequest()` would
 * never create a session and conversions would have no journey to attribute to.
 *
 * This class fills that gap at the plugin layer: it mints a format-valid
 * visitor id on the first front-end page load and persists it as `_mbuzz_vid`,
 * so the SDK's existing `POST /sessions` path runs and touchpoints get
 * recorded — full multi-touch attribution with no client-side pixel.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP\Visitor;

use Mbuzz\CookieManager;

final class CookieBootstrap
{
    /**
     * Mint and persist a visitor cookie when none exists yet.
     *
     * Injecting the CookieManager keeps the call testable (the SDK's
     * CookieManager accepts a set-cookie callback for exactly this) and
     * guarantees byte-for-byte parity with the cookie attributes the SDK
     * uses when it reads the value back.
     *
     * The freshly-minted id is written into $_COOKIE as well so that the
     * SDK — which reads $_COOKIE — sees the visitor within this same request
     * and creates the session immediately, rather than a request later.
     * We only populate $_COOKIE if the real cookie actually persisted, so a
     * headers-already-sent failure doesn't leave us claiming a visitor whose
     * cookie never reached the browser (which would churn a new id per page).
     */
    public static function ensureVisitorCookie(?CookieManager $cookies = null): void
    {
        if (isset($_COOKIE[CookieManager::VISITOR_COOKIE])) {
            return;
        }

        $cookies ??= new CookieManager();
        $visitorId = self::generateVisitorId();

        if ($cookies->setVisitorId($visitorId)) {
            $_COOKIE[CookieManager::VISITOR_COOKIE] = $visitorId;
        }
    }

    /**
     * 64 hex chars — mirrors the backend's anonymous visitor ids
     * (SecureRandom.hex(32)) and satisfies the backend's visitor-id format
     * (`[a-zA-Z0-9._:\-]+`), so POST /sessions accepts and creates it.
     */
    private static function generateVisitorId(): string
    {
        return bin2hex(random_bytes(32));
    }
}
