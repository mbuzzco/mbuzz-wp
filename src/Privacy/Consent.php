<?php
/**
 * Consent gate. Every capture surface (sessions, forms, identity, commerce,
 * theme helpers) checks Consent::granted() before sending anything to mbuzz,
 * because the plugin ships PII to a third party.
 *
 * Posture: when the WP Consent API (a consent-banner plugin) is active, capture
 * is gated on the configured category — defaulting to "marketing", the most
 * protective bucket for attribution that can feed ad platforms. When no Consent
 * API is present the plugin does NOT invent its own gate (the site owner's
 * cookie/consent solution governs), so it defaults to allowed. Both the
 * category and the final decision are filterable.
 *
 * This is the one WordPress-aware seam the capture adapters share; the pure
 * Tracking\ domain never references it.
 *
 * @package Mbuzz\WP\Privacy
 */

declare(strict_types=1);

namespace Mbuzz\WP\Privacy;

final class Consent
{
    /** WP Consent API category gating attribution capture. */
    public const DEFAULT_CATEGORY = 'marketing';

    /** Filter: override the gating category (string). */
    public const FILTER_CATEGORY = 'mbuzz_consent_category';

    /** Filter: override the final granted decision (bool). */
    public const FILTER_GRANTED = 'mbuzz_has_consent';

    /** The WP Consent API entrypoint; absent unless a consent plugin is active. */
    private const WP_HAS_CONSENT = 'wp_has_consent';

    public static function granted(): bool
    {
        if (function_exists(self::WP_HAS_CONSENT)) {
            $category = (string) apply_filters(self::FILTER_CATEGORY, self::DEFAULT_CATEGORY);
            $granted  = wp_has_consent($category) === true;
        } else {
            $granted = true; // no Consent API → not gated by us
        }

        return (bool) apply_filters(self::FILTER_GRANTED, $granted);
    }
}
