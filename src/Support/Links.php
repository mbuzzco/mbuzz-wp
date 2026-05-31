<?php
/**
 * Outbound links surfaced in the admin UI. Centralized so they're never bare
 * strings in templates and easy to repoint.
 *
 * @package Mbuzz\WP\Support
 */

declare(strict_types=1);

namespace Mbuzz\WP\Support;

final class Links
{
    // Canonical hosted docs home. Until the mbuzz.co page ships, points at the
    // repo's getting-started; repoint to https://mbuzz.co/docs/wordpress then.
    public const DOCS = 'https://github.com/mbuzzco/mbuzz-wp#getting-started';
}
