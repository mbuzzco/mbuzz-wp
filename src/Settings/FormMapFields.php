<?php
/**
 * Sanitizes the form-editor panel's submitted map into a FieldMap. The map is a
 * variable-key, repeatable key→value structure, so this iterates and sanitizes
 * each part (never the single-value pattern): the role against an allowlist, the
 * mbuzz key with sanitize_key, the form field name with sanitize_text_field
 * (preserving case/hyphens so it still matches the posted data).
 *
 * Caller must wp_unslash() the raw input first (WordPress magic-quotes $_POST).
 *
 * @package Mbuzz\WP\Settings
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;

final class FormMapFields
{
    /**
     * @param array<string, mixed> $raw  unslashed panel input (mbuzz_map)
     */
    public static function sanitize(array $raw): FieldMap
    {
        $enabled = ! empty($raw[FieldMap::K_ENABLED]);

        $trackAs = (string) ($raw[FieldMap::K_TRACK_AS] ?? TrackAs::OFF);
        if (! TrackAs::isValid($trackAs)) {
            $trackAs = TrackAs::OFF;
        }

        $type = sanitize_text_field((string) ($raw[FieldMap::K_TYPE] ?? ''));
        if ($type === '') {
            $type = FieldMap::DEFAULT_TYPE;
        }

        $captureRaw = sanitize_key((string) ($raw[FieldMap::K_CAPTURE_PAGE_AS] ?? ''));
        $capture    = $captureRaw !== '' ? $captureRaw : null;

        $fields    = [];
        $rawFields = is_array($raw[FieldMap::K_FIELDS] ?? null) ? $raw[FieldMap::K_FIELDS] : [];

        foreach ($rawFields as $fieldName => $config) {
            if (! is_array($config)) {
                continue;
            }
            // Field name must survive verbatim (case/hyphens) to match posted data.
            $name = sanitize_text_field((string) $fieldName);
            if ($name === '') {
                continue;
            }

            $role = (string) ($config[FieldMap::K_ROLE] ?? Roles::IGNORE);
            if (! Roles::isValid($role)) {
                $role = Roles::IGNORE;
            }

            $entry = [FieldMap::K_ROLE => $role];

            if (in_array($role, Roles::KEYED, true)) {
                $key = sanitize_key((string) ($config[FieldMap::K_KEY] ?? ''));
                if ($key === '') {
                    continue; // a trait/property with no mbuzz key is meaningless
                }
                $entry[FieldMap::K_KEY] = $key;
            }

            // event_type takes no mbuzz key, so its box carries the value map instead.
            if ($role === Roles::EVENT_TYPE) {
                $values = self::parseValueMap((string) ($config[FieldMap::K_KEY] ?? ''));
                if ($values !== []) {
                    $entry[FieldMap::K_VALUES] = $values;
                }
            }

            $fields[$name] = $entry;
        }

        $fields = self::withExtraField($fields, $raw[FieldMap::K_EXTRA] ?? null);

        return new FieldMap($enabled, $trackAs, $type, $capture, $fields);
    }
    /**
     * Parse the panel's value-map box: one `posted value = event name` per line.
     *
     * The seam between a third-party field's vocabulary and ours. Malformed lines
     * are dropped rather than guessed at — a half-understood mapping silently
     * renaming events is the failure this exists to prevent.
     *
     * @return array<string, string>
     */
    private static function parseValueMap(string $raw): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$from, $to] = array_map('trim', explode('=', $line, 2));
            if ($from === '' || $to === '') {
                continue;
            }

            $values[sanitize_text_field($from)] = sanitize_text_field($to);
        }

        return $values;
    }

    /**
     * Merge the panel's "add a field that isn't listed" row. Lets an admin map
     * an input another plugin injects into the form as raw HTML — submitted with
     * the form, but not a CF7 tag, so it never appears in the scanned list.
     *
     * @param array<string, array<string, string>> $fields
     * @param mixed                                $extra
     * @return array<string, array<string, string>>
     */
    private static function withExtraField(array $fields, $extra): array
    {
        if (! is_array($extra)) {
            return $fields;
        }

        $name = sanitize_text_field((string) ($extra[FieldMap::K_EXTRA_FIELD] ?? ''));
        $role = (string) ($extra[FieldMap::K_ROLE] ?? Roles::IGNORE);

        if ($name === '' || ! Roles::isValid($role) || $role === Roles::IGNORE) {
            return $fields;
        }

        $entry = [FieldMap::K_ROLE => $role];

        if (in_array($role, Roles::KEYED, true)) {
            $key = sanitize_key((string) ($extra[FieldMap::K_KEY] ?? ''));
            if ($key === '') {
                return $fields;
            }
            $entry[FieldMap::K_KEY] = $key;
        }

        $fields[$name] = $entry;

        return $fields;
    }

}
