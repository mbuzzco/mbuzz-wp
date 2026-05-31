<?php
/**
 * How a configured form is tracked. Named constants — never bare strings.
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class TrackAs
{
    public const CONVERSION = 'conversion';
    public const EVENT      = 'event';
    public const OFF        = 'off';

    /** @var array<int, string> */
    public const ALL = [self::CONVERSION, self::EVENT, self::OFF];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
