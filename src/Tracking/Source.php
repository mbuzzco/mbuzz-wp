<?php
/**
 * Form-plugin source keys. One per supported plugin; CF7 first. Named
 * constants so the source is never a bare string in the engine or storage.
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class Source
{
    public const CF7 = 'cf7';
    // Roadmap (one adapter each): WPFORMS, ELEMENTOR, GRAVITY, NINJA, FLUENT.

    /** @var array<int, string> */
    public const ALL = [self::CF7];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }
}
