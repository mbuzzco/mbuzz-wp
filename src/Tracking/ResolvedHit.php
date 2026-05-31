<?php
/**
 * The outcome of applying a FieldMap to one submission: what to send to mbuzz.
 * Immutable.
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class ResolvedHit
{
    /**
     * @param string                $trackAs    one of TrackAs::CONVERSION | TrackAs::EVENT
     * @param array<string, mixed>  $traits     arbitrary identity traits (admin-named keys)
     * @param array<string, mixed>  $properties arbitrary conversion/event properties
     */
    public function __construct(
        public readonly string $trackAs,
        public readonly string $type,
        public readonly ?string $userId,
        public readonly array $traits,
        public readonly array $properties,
        public readonly ?float $revenue = null,
        public readonly ?string $currency = null,
    ) {
    }

    /**
     * Whether there is anyone to identify. Identifying links the unique user
     * attribute (user_id) to the current visitor/session — which the SDK always
     * carries — collapsing this and prior anonymous sessions onto the person.
     * Traits are optional enrichment on that link, never a precondition: a
     * join key alone is enough to stitch.
     */
    public function hasIdentity(): bool
    {
        return $this->userId !== null && $this->userId !== '';
    }
}
