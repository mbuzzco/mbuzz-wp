<?php
/**
 * Procedural helpers for theme/snippet code.
 *
 * These are the documented, scope-stable surface for non-developer call sites.
 * After php-scoper runs, the SDK class lives at \Mbuzz\Vendor\Mbuzz\Mbuzz
 * (or whatever the build configures); themes calling \Mbuzz\Mbuzz::event()
 * directly would break. These functions stay on the global symbol table and
 * forward to whichever scoped class the plugin actually bundles.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

if (! function_exists('mbuzz_event')) {
    /**
     * Track an event.
     *
     * @param string               $type
     * @param array<string, mixed> $properties
     * @return array<string, mixed>|false
     */
    function mbuzz_event(string $type, array $properties = []): array|false
    {
        if (! \Mbuzz\WP\Plugin::instance()->sdkReady() || ! \Mbuzz\WP\Privacy\Consent::granted()) {
            return false;
        }
        return \Mbuzz\Mbuzz::event($type, $properties);
    }
}

if (! function_exists('mbuzz_conversion')) {
    /**
     * Track a conversion.
     *
     * @param string              $type
     * @param array<string,mixed> $options
     * @return array<string, mixed>|false
     */
    function mbuzz_conversion(string $type, array $options = []): array|false
    {
        if (! \Mbuzz\WP\Plugin::instance()->sdkReady() || ! \Mbuzz\WP\Privacy\Consent::granted()) {
            return false;
        }
        return \Mbuzz\Mbuzz::conversion($type, $options);
    }
}

if (! function_exists('mbuzz_identify')) {
    /**
     * Identify a user.
     *
     * @param string|int          $userId
     * @param array<string,mixed> $traits
     */
    function mbuzz_identify(string|int $userId, array $traits = []): bool
    {
        if (! \Mbuzz\WP\Plugin::instance()->sdkReady() || ! \Mbuzz\WP\Privacy\Consent::granted()) {
            return false;
        }
        return \Mbuzz\Mbuzz::identify($userId, $traits);
    }
}
