<?php
/**
 * Keys for the options array passed to Mbuzz::conversion(). Named constants so
 * the SDK payload shape is never a bare string at a call site.
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class ConversionOptions
{
    public const PROPERTIES = 'properties';
    public const USER_ID    = 'user_id';
    public const REVENUE    = 'revenue';
    public const CURRENCY   = 'currency';
}
