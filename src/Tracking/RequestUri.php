<?php
/**
 * The REQUEST_URI a web server would present for a page URL: the path, plus
 * `?query` when there is one. Never the fragment — a browser does not send it,
 * but `location.href` includes it.
 *
 * The cached-page session path (Rest\SessionController) stands in for the
 * page's own request, so it rebuilds REQUEST_URI from the URL the page script
 * reports. It used to keep the path alone, which threw away the query string
 * and with it every fbclid, gclid and utm_* — every ad click on a cached site
 * arrived as organic, social or direct (2026-09-22). Spec:
 * lib/specs/query-string-capture-spec.md.
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class RequestUri
{
    public const ROOT = '/';
    private const QUERY_SEPARATOR = '?';

    public static function fromPageUrl(string $pageUrl): string
    {
        $parts = parse_url($pageUrl);
        if (! is_array($parts)) {
            return self::ROOT;
        }

        return self::path($parts) . self::query($parts);
    }

    /** @param array<string, mixed> $parts */
    private static function path(array $parts): string
    {
        $path = $parts['path'] ?? '';

        return is_string($path) && $path !== '' ? $path : self::ROOT;
    }

    /** @param array<string, mixed> $parts */
    private static function query(array $parts): string
    {
        $query = $parts['query'] ?? '';

        return is_string($query) && $query !== '' ? self::QUERY_SEPARATOR . $query : '';
    }
}
