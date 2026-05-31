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

    /** Whether there is anyone to identify (a join key plus at least one trait). */
    public function hasIdentity(): bool
    {
        return $this->userId !== null && $this->userId !== '' && $this->traits !== [];
    }
}
