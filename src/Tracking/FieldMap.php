<?php
/**
 * One form's tracking map, and the logic that turns a submission into a
 * ResolvedHit. The plugin is schema-agnostic: trait and property keys are
 * whatever the admin named; `user_id` is the only join key and is taken ONLY
 * from a field explicitly roled `user_id` (never substituted from an email).
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class FieldMap
{
    public const DEFAULT_TYPE = 'lead';

    // Serialization keys (stored in the form's post meta).
    public const K_ENABLED         = 'enabled';
    public const K_TRACK_AS        = 'track_as';
    public const K_TYPE            = 'type';
    public const K_CAPTURE_PAGE_AS = 'capture_page_as';
    public const K_FIELDS          = 'fields';
    public const K_ROLE            = 'role';
    public const K_KEY             = 'key';

    // Page-descriptor keys supplied by the FormSource adapter.
    public const PAGE_ID    = 'id';
    public const PAGE_TITLE = 'title';
    public const PAGE_URL   = 'url';

    // Audit properties added to the hit when a page is known.
    public const PROP_PAGE_URL = 'mbuzz_page_url';
    public const PROP_PAGE_ID  = 'mbuzz_page_id';

    /**
     * @param array<string, array{role?: string, key?: string}> $fields  field name → role + mbuzz key
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $trackAs,
        public readonly string $type,
        public readonly ?string $capturePageAs,
        public readonly array $fields,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $trackAs = (string) ($data[self::K_TRACK_AS] ?? TrackAs::OFF);
        if (! TrackAs::isValid($trackAs)) {
            $trackAs = TrackAs::OFF;
        }

        $type = trim((string) ($data[self::K_TYPE] ?? ''));
        if ($type === '') {
            $type = self::DEFAULT_TYPE;
        }

        $capture = $data[self::K_CAPTURE_PAGE_AS] ?? null;
        $capture = (is_string($capture) && $capture !== '') ? $capture : null;

        $fields = is_array($data[self::K_FIELDS] ?? null) ? $data[self::K_FIELDS] : [];

        return new self((bool) ($data[self::K_ENABLED] ?? false), $trackAs, $type, $capture, $fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::K_ENABLED         => $this->enabled,
            self::K_TRACK_AS        => $this->trackAs,
            self::K_TYPE            => $this->type,
            self::K_CAPTURE_PAGE_AS => $this->capturePageAs,
            self::K_FIELDS          => $this->fields,
        ];
    }

    public function isTrackable(): bool
    {
        return $this->enabled && $this->trackAs !== TrackAs::OFF;
    }

    /**
     * @param array<string, mixed> $posted submission data, keyed by field name
     * @param array<string, mixed> $page   {id, title, url} of the submitting page
     */
    public function resolve(array $posted, array $page = []): ResolvedHit
    {
        $userId     = null;
        $traits     = [];
        $properties = [];
        $revenue    = null;
        $currency   = null;

        foreach ($this->fields as $fieldName => $config) {
            if (! is_array($config) || ! array_key_exists($fieldName, $posted)) {
                continue;
            }

            $value = $posted[$fieldName];
            $role  = (string) ($config[self::K_ROLE] ?? Roles::IGNORE);
            $key   = isset($config[self::K_KEY]) ? (string) $config[self::K_KEY] : '';

            switch ($role) {
                case Roles::USER_ID:
                    $userId ??= self::nonEmptyScalar($value);
                    break;
                case Roles::TRAIT:
                    $scalar = self::nonEmptyScalar($value);
                    if ($key !== '' && $scalar !== null) {
                        $traits[$key] = $scalar;
                    }
                    break;
                case Roles::PROPERTY:
                    if ($key !== '') {
                        $properties[$key] = $value; // arrays preserved
                    }
                    break;
                case Roles::REVENUE:
                    $parsed = self::parseFloat(self::nonEmptyScalar($value));
                    if ($parsed !== null) {
                        $revenue = $parsed;
                    }
                    break;
                case Roles::CURRENCY:
                    $currency = self::nonEmptyScalar($value) ?? $currency;
                    break;
                // Roles::IGNORE and anything unrecognized: skipped.
            }
        }

        if ($this->capturePageAs !== null && ! empty($page[self::PAGE_TITLE])) {
            $properties[$this->capturePageAs] = (string) $page[self::PAGE_TITLE];
        }
        if (! empty($page[self::PAGE_URL])) {
            $properties[self::PROP_PAGE_URL] = (string) $page[self::PAGE_URL];
        }
        if (! empty($page[self::PAGE_ID])) {
            $properties[self::PROP_PAGE_ID] = (int) $page[self::PAGE_ID];
        }

        return new ResolvedHit($this->trackAs, $this->type, $userId, $traits, $properties, $revenue, $currency);
    }

    /**
     * First scalar entry of a string|array field value, trimmed; null if none/empty.
     *
     * @param mixed $value
     */
    private static function nonEmptyScalar($value): ?string
    {
        foreach ((array) $value as $candidate) {
            if (is_scalar($candidate)) {
                $trimmed = trim((string) $candidate);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return null;
    }

    private static function parseFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', $value);

        return ($clean !== '' && is_numeric($clean)) ? (float) $clean : null;
    }
}
