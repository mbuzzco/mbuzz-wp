<?php
/**
 * Pre-fills a suggested FieldMap from a form's field names to make setup fast.
 * It is ONLY ever a suggestion the admin edits — never an imposed schema:
 *
 *   - It never assigns `user_id` (the admin designates the join key; the UI
 *     nudges toward a non-PII id).
 *   - Email/name/phone-looking fields are suggested as traits with conventional
 *     keys (recognized downstream for ad-matching) — fully editable.
 *   - Tracking fields are ignored (the session already captures them).
 *   - Sensitive fields (child/dob/…) default to ignore — opt-in only.
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class AutoDetector
{
    // Conventional trait keys for recognized fields. Non-binding hints: the
    // admin can rename or remove them; the plugin enforces nothing.
    private const SUGGEST_EMAIL      = 'email';
    private const SUGGEST_FIRST_NAME = 'first_name';
    private const SUGGEST_LAST_NAME  = 'last_name';
    private const SUGGEST_PHONE      = 'phone';

    private const RE_EMAIL      = '/e-?mail/i';
    private const RE_FIRST_NAME = '/first.?name/i';
    private const RE_LAST_NAME  = '/last.?name/i';
    private const RE_PHONE      = '/phone|mobile|tel/i';
    private const RE_TRACKING   = '/^(gclid|fbclid|utm_|source)/i';
    // Token-boundary match (on the snake_cased name) so "age" hits parent_age
    // but not "message"/"page", and child/dob/birth catch child fields.
    private const RE_SENSITIVE  = '/(^|_)(child\d*|children|dob|birth|age)(_|$)/i';

    /**
     * @param array<int, string> $fieldNames
     */
    public static function suggest(array $fieldNames, ?string $formTitle = null): FieldMap
    {
        $fields = [];
        $taken  = [];

        foreach ($fieldNames as $name) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            $fields[$name] = self::roleFor($name, $taken);
        }

        return new FieldMap(
            true,                       // a suggestion the admin accepts by saving
            TrackAs::CONVERSION,
            self::typeFrom($formTitle),
            null,                       // capture_page_as: admin opts in
            $fields,
        );
    }

    /**
     * @param array<string, bool> $taken  recognized trait keys already assigned
     * @return array{role: string, key?: string}
     */
    private static function roleFor(string $name, array &$taken): array
    {
        $snake = self::snake($name);

        if (preg_match(self::RE_TRACKING, $snake) || preg_match(self::RE_SENSITIVE, $snake)) {
            return [FieldMap::K_ROLE => Roles::IGNORE];
        }

        $recognized = [
            self::SUGGEST_EMAIL      => self::RE_EMAIL,
            self::SUGGEST_FIRST_NAME => self::RE_FIRST_NAME,
            self::SUGGEST_LAST_NAME  => self::RE_LAST_NAME,
            self::SUGGEST_PHONE      => self::RE_PHONE,
        ];
        foreach ($recognized as $key => $pattern) {
            if (! isset($taken[$key]) && preg_match($pattern, $snake)) {
                $taken[$key] = true;
                return [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => $key];
            }
        }

        return [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => $snake];
    }

    private static function typeFrom(?string $formTitle): string
    {
        if ($formTitle === null) {
            return FieldMap::DEFAULT_TYPE;
        }
        $slug = self::snake($formTitle);

        return $slug !== '' ? $slug : FieldMap::DEFAULT_TYPE;
    }

    private static function snake(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
        $value = preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $value);

        return strtolower(trim((string) $value, '_'));
    }
}
