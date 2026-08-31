<?php
/**
 * First-party REST endpoint that bootstraps a visitor + session for page views
 * the server never rendered.
 *
 * A full-page cache — Cloudflare "Cache Everything", WP Rocket, Varnish,
 * LiteSpeed, a host's edge cache — returns HTML without running PHP. Nothing
 * mints `_mbuzz_vid`, so no session exists, and every later event or conversion
 * is dropped by the SDK for having no visitor to attribute it to (silently: no
 * HTTP call, nothing logged). Since page caching is the default posture of most
 * WordPress hosting, that is an unhandled deployment mode rather than a
 * customer misconfiguration.
 *
 * REST routes are never full-page cached, so this one always executes. It mints
 * the cookie with a real Set-Cookie and records the session, using the page's
 * own URL and referrer rather than this request's.
 *
 * The visitor id stays HttpOnly and server-side throughout — the browser sends
 * only where it is, never the identity.
 *
 * Security: unauthenticated by design (visitors are anonymous), gated on
 * same-origin, consent, and the master switch — the same gates as
 * LeadController. Always 204 on a non-error, so nothing is reflected back.
 *
 * @package Mbuzz\WP\Rest
 */

declare(strict_types=1);

namespace Mbuzz\WP\Rest;

use Mbuzz\CookieManager;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Plugin;
use Mbuzz\WP\Bootstrap;
use Mbuzz\WP\Privacy\Consent;
use Mbuzz\WP\Settings\Repository as SettingsRepository;
use Mbuzz\WP\Tracking\TrackingEngine;
use Mbuzz\WP\Visitor\CookieBootstrap;

final class SessionController
{
    public const NAMESPACE = 'mbuzz/v1';
    public const ROUTE     = '/session';

    /** Filter: allow tightening/loosening the same-origin gate. */
    public const FILTER_ALLOW = 'mbuzz_session_allow_request';

    public const PARAM_URL      = 'url';
    public const PARAM_REFERRER = 'referrer';

    /** @var (callable():bool)|null test seam for consent */
    private static $consentProvider = null;

    /** @var (callable():bool)|null test seam for sdk-ready */
    private static $readyProvider = null;

    /** @var CookieManager|null test seam for cookie writes */
    private static ?CookieManager $cookies = null;

    /** @var (callable():void)|null test seam for the SDK re-init */
    private static $reinitialiser = null;

    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route(self::NAMESPACE, self::ROUTE, [
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => [self::class, 'handle'],
            ]);
        });
    }

    /**
     * @param object $request duck-typed WP_REST_Request
     * @return object         WP_REST_Response-like
     */
    public static function handle($request)
    {
        if (! self::sameOrigin($request)) {
            return self::response(403);
        }
        if (! self::ready() || ! self::consent()) {
            return self::response(204); // opt-in / consent → no-op, never reflect why
        }

        $minted = self::mintVisitor();

        self::recordSession(self::pageContext($request), $minted);

        return self::response(204);
    }

    /**
     * Establish the visitor, reporting a mint failure the way the page path does.
     *
     * @return bool whether a cookie was newly minted on this request
     */
    private static function mintVisitor(): bool
    {
        $had = isset($_COOKIE[CookieManager::VISITOR_COOKIE]);

        CookieBootstrap::ensureVisitorCookie(
            self::$cookies,
            static function (string $reason): void {
                TrackingEngine::note($reason);
            },
            CookieBootstrap::CONTEXT_ENDPOINT
        );

        return ! $had && isset($_COOKIE[CookieManager::VISITOR_COOKIE]);
    }

    /**
     * Record the session for the PAGE, not for this REST request.
     *
     * `Client::initFromRequest()` reads the URL from `$_SERVER` and only creates
     * a session for a real navigation (`Sec-Fetch-Mode: navigate`), which a
     * fetch/beacon is not. Both are properties of the request we are standing in
     * for, so we present the page's own values while the SDK does the rest.
     *
     * The SDK caches request context the first time it is read, and the plugin
     * boots it on `plugins_loaded` — before this endpoint mints. A cookie minted
     * afterwards would be invisible to that cached context, so when we mint we
     * re-init the client to pick it up.
     *
     * @param array{url: string, referrer: string} $page
     */
    private static function recordSession(array $page, bool $minted): void
    {
        $restored = [
            'REQUEST_URI'         => $_SERVER['REQUEST_URI'] ?? null,
            'HTTP_REFERER'        => $_SERVER['HTTP_REFERER'] ?? null,
            'HTTP_SEC_FETCH_MODE' => $_SERVER['HTTP_SEC_FETCH_MODE'] ?? null,
            'HTTP_SEC_FETCH_DEST' => $_SERVER['HTTP_SEC_FETCH_DEST'] ?? null,
        ];

        $path = wp_parse_url($page[self::PARAM_URL], PHP_URL_PATH);

        $_SERVER['REQUEST_URI']         = is_string($path) && $path !== '' ? $path : '/';
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';

        if ($page[self::PARAM_REFERRER] !== '') {
            $_SERVER['HTTP_REFERER'] = $page[self::PARAM_REFERRER];
        } else {
            unset($_SERVER['HTTP_REFERER']);
        }

        try {
            if ($minted) {
                self::reinitialiseClient();
            }
            Mbuzz::initFromRequest();
        } finally {
            foreach ($restored as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                    continue;
                }
                $_SERVER[$key] = $value;
            }
        }
    }

    /**
     * Rebuild the SDK client so it reads the cookie we just minted. Mirrors the
     * multisite switch_blog path in Plugin: reset() alone leaves
     * ensureInitialized() throwing, so the re-init is mandatory.
     */
    private static function reinitialiseClient(): void
    {
        if (self::$reinitialiser !== null) {
            (self::$reinitialiser)();
            return;
        }

        Mbuzz::reset();
        Bootstrap::boot(SettingsRepository::current());
    }

    /**
     * @param object $request
     * @return array{url: string, referrer: string}
     */
    private static function pageContext($request): array
    {
        $raw = $request->get_json_params();
        $raw = is_array($raw) ? wp_unslash($raw) : [];

        return [
            self::PARAM_URL      => esc_url_raw((string) ($raw[self::PARAM_URL] ?? '')),
            self::PARAM_REFERRER => esc_url_raw((string) ($raw[self::PARAM_REFERRER] ?? '')),
        ];
    }

    /**
     * @param object $request
     */
    private static function sameOrigin($request): bool
    {
        $origin  = (string) ($request->get_header('origin') ?? '');
        $allowed = true;

        if ($origin !== '') {
            $originHost = wp_parse_url($origin, PHP_URL_HOST);
            $siteHost   = wp_parse_url((string) home_url(), PHP_URL_HOST);
            $allowed    = $originHost !== null && $originHost === $siteHost;
        }

        return (bool) apply_filters(self::FILTER_ALLOW, $allowed, $request);
    }

    private static function consent(): bool
    {
        if (self::$consentProvider !== null) {
            return (bool) (self::$consentProvider)();
        }

        return Consent::granted();
    }

    private static function ready(): bool
    {
        if (self::$readyProvider !== null) {
            return (bool) (self::$readyProvider)();
        }

        return Plugin::instance()->sdkReady();
    }

    /**
     * @return object
     */
    private static function response(int $status)
    {
        if (class_exists('WP_REST_Response')) {
            return new \WP_REST_Response(null, $status);
        }

        return new class ($status) {
            public function __construct(private int $status)
            {
            }
            public function get_status(): int
            {
                return $this->status;
            }
        };
    }

    public static function setConsentProviderForTests(?callable $provider): void
    {
        self::$consentProvider = $provider;
    }

    public static function setReadyProviderForTests(?callable $provider): void
    {
        self::$readyProvider = $provider;
    }

    public static function setCookieManagerForTests(?CookieManager $cookies): void
    {
        self::$cookies = $cookies;
    }

    public static function setReinitialiserForTests(?callable $reinitialiser): void
    {
        self::$reinitialiser = $reinitialiser;
    }
}
