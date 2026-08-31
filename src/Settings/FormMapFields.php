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

            $fields[$name] = $entry;
        }

        $fields = self::withExtraField($fields, $raw[FieldMap::K_EXTRA] ?? null);

        return new FieldMap($enabled, $trackAs, $type, $capture, $fields);
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
