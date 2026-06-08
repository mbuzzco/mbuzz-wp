<?php
/**
 * Sanitized embedded-form capture payload. The plugin is schema-agnostic:
 * trait/property keys are owner-named, values owner-supplied. Array
 * sanitization throughout (variable-key, repeatable) — never the single-value
 * pattern. Pure value object; the controller wp_unslash()es before handing raw
 * input here.
 *
 * @package Mbuzz\WP\Rest
 */

declare(strict_types=1);

namespace Mbuzz\WP\Rest;

final class LeadRequest
{
    public const DEFAULT_TYPE = 'lead';

    /** Cap on trait/property keys to bound payload size. */
    public const MAX_KEYS = 50;

    /**
     * @param array<string, mixed>  $traits     owner-named identity traits (scalars)
     * @param array<string, mixed>  $properties owner-named event properties (scalars or arrays)
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $userId,
        public readonly array $traits,
        public readonly array $properties,
    ) {
    }

    /**
     * @param array<string, mixed> $raw  unslashed request body
     */
    public static function fromArray(array $raw): self
    {
        $type = sanitize_key((string) ($raw['type'] ?? ''));
        if ($type === '') {
            $type = self::DEFAULT_TYPE;
        }

        $userId = sanitize_text_field((string) ($raw['user_id'] ?? ''));
        $userId = $userId !== '' ? $userId : null;

        $traits     = self::sanitizeMap($raw['traits'] ?? null, allowArrays: false);
        $properties = self::sanitizeMap($raw['properties'] ?? null, allowArrays: true);

        return new self($type, $userId, $traits, $properties);
    }

    public function hasIdentity(): bool
    {
        return $this->userId !== null && $this->userId !== '';
    }

    /**
     * Sanitize a variable-key map: keys via sanitize_key, scalar values via
     * sanitize_text_field, empty values dropped, count capped. Properties may
     * keep array values (e.g. checkbox groups); traits are scalar only (the
     * identity layer uses the first scalar anyway).
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private static function sanitizeMap($raw, bool $allowArrays): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (count($out) >= self::MAX_KEYS) {
                break;
            }
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }

            if (is_array($value)) {
                if (! $allowArrays) {
                    $value = self::firstScalar($value);
                    if ($value === null) {
                        continue;
                    }
                    $out[$key] = sanitize_text_field((string) $value);
                    continue;
                }
                $clean = self::sanitizeArrayValues($value);
                if ($clean !== []) {
                    $out[$key] = $clean;
                }
                continue;
            }

            $clean = sanitize_text_field((string) $value);
            if ($clean !== '') {
                $out[$key] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int, string>
     */
    private static function sanitizeArrayValues(array $value): array
    {
        $clean = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $s = sanitize_text_field((string) $item);
                if ($s !== '') {
                    $clean[] = $s;
                }
            }
        }

        return $clean;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function firstScalar(array $value): ?string
    {
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $s = trim((string) $item);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return null;
    }
}
