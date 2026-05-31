<?php
/**
 * The roles a form field can play in a map. The plugin is schema-agnostic:
 * `trait` and `property` keys are whatever the admin names them — mbuzz does
 * not dictate an identity schema. `user_id` is the customer's cross-system
 * join key (a non-PII unique id is preferred; never auto-defaulted to email).
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class Roles
{
    public const USER_ID  = 'user_id';
    public const TRAIT    = 'trait';
    public const PROPERTY = 'property';
    public const REVENUE  = 'revenue';
    public const CURRENCY = 'currency';
    public const IGNORE   = 'ignore';

    /** @var array<int, string> */
    public const ALL = [
        self::USER_ID,
        self::TRAIT,
        self::PROPERTY,
        self::REVENUE,
        self::CURRENCY,
        self::IGNORE,
    ];

    /** Roles that require a non-empty `key` (the mbuzz-side name). */
    public const KEYED = [self::TRAIT, self::PROPERTY];

    public static function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
