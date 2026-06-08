<?php
/**
 * First-party REST endpoint for embedded / external form lead capture.
 *
 * Embedded third-party forms (a vendor widget rendered into the page DOM, a
 * form POSTing cross-domain) never trigger a WordPress server hook, so the CF7
 * adapter can't see them. A small owner-instrumented JS helper POSTs the lead
 * here on submit; this endpoint runs inside WordPress with the request's
 * cookies, resolves the visitor from the HttpOnly `_mbuzz_vid` cookie
 * SERVER-SIDE, and fires the same identify + event path the CF7 integration
 * uses. The browser never needs to read the visitor id.
 *
 * Security: unauthenticated by design (anonymous visitors), gated on
 * same-origin (a REST nonce isn't reliably available to a pasted snippet),
 * consent, and the master switch. Returns 204 (no reflection) on success;
 * 403 on cross-origin; 400 on a malformed body.
 *
 * @package Mbuzz\WP\Rest
 */

declare(strict_types=1);

namespace Mbuzz\WP\Rest;

use Mbuzz\Mbuzz;
use Mbuzz\WP\Plugin;
use Mbuzz\WP\Privacy\Consent;

final class LeadController
{
    public const NAMESPACE = 'mbuzz/v1';
    public const ROUTE     = '/lead';

    /** Filter: allow tightening/loosening the same-origin gate. */
    public const FILTER_ALLOW = 'mbuzz_lead_allow_request';

    /** @var (callable():bool)|null test seam for consent */
    private static $consentProvider = null;

    /** @var (callable():bool)|null test seam for sdk-ready */
    private static $readyProvider = null;

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
     * @param object $request duck-typed WP_REST_Request: get_json_params(), get_header()
     * @return object         WP_REST_Response-like: get_status()
     */
    public static function handle($request)
    {
        if (! self::sameOrigin($request)) {
            return self::response(403);
        }
        if (! self::ready() || ! self::consent()) {
            return self::response(204); // opt-in / consent → no-op, never reflect why
        }

        $raw  = $request->get_json_params();
        if (! is_array($raw)) {
            return self::response(400);
        }

        $lead = LeadRequest::fromArray(wp_unslash($raw));

        if ($lead->hasIdentity()) {
            // identify sets context user_id, so the subsequent event carries it →
            // backend create-on-demand resolves/creates the visitor (closes the race).
            Mbuzz::identify((string) $lead->userId, $lead->traits);
        }
        Mbuzz::event($lead->type, $lead->properties);

        return self::response(204);
    }

    /**
     * @param object $request
     */
    private static function sameOrigin($request): bool
    {
        $origin = (string) ($request->get_header('origin') ?? '');
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

    private static function response(int $status)
    {
        if (class_exists('WP_REST_Response')) {
            return new \WP_REST_Response(null, $status);
        }

        // Test fallback when WP isn't loaded.
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

    /** @param (callable():bool)|null $fn */
    public static function setConsentProviderForTests(?callable $fn): void
    {
        self::$consentProvider = $fn;
    }

    /** @param (callable():bool)|null $fn */
    public static function setReadyProviderForTests(?callable $fn): void
    {
        self::$readyProvider = $fn;
    }
}
