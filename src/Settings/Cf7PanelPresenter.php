<?php
/**
 * Builds the view-model for the CF7 editor panel from a FieldMap + the form's
 * field names. Pure: no WordPress, no escaping, no i18n — the template owns
 * presentation. Unit-tested directly.
 *
 * @package Mbuzz\WP\Settings
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

use Mbuzz\WP\Support\Links;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;

final class Cf7PanelPresenter
{
    public function __construct(private string $postKey)
    {
    }

    /**
     * @param array<int, string> $fieldNames
     * @return array<string, mixed>
     */
    public function present(FieldMap $map, array $fieldNames): array
    {
        return [
            'enabled'              => $map->enabled,
            'enabled_name'         => $this->name([FieldMap::K_ENABLED]),
            'track_as'             => $map->trackAs,
            'track_as_name'        => $this->name([FieldMap::K_TRACK_AS]),
            'track_as_options'     => TrackAs::ALL,
            'type'                 => $map->type,
            'type_name'            => $this->name([FieldMap::K_TYPE]),
            'capture_page_as'      => (string) $map->capturePageAs,
            'capture_page_as_name' => $this->name([FieldMap::K_CAPTURE_PAGE_AS]),
            'role_options'         => Roles::ALL,
            'keyed_roles'          => Roles::KEYED,
            'extra_field_name'     => $this->name([FieldMap::K_EXTRA, FieldMap::K_EXTRA_FIELD]),
            'extra_role_name'      => $this->name([FieldMap::K_EXTRA, FieldMap::K_ROLE]),
            'extra_key_name'       => $this->name([FieldMap::K_EXTRA, FieldMap::K_KEY]),
            'rows'                 => $this->rows($map, $fieldNames),
            'docs_url'             => Links::DOCS,
        ];
    }

    /**
     * @param array<int, string> $fieldNames
     * @return array<int, array<string, string>>
     */
    private function rows(FieldMap $map, array $fieldNames): array
    {
        $rows = [];
        foreach (array_values(self::withMappedFields($map, $fieldNames)) as $index => $field) {
            $config = $map->fields[$field] ?? [FieldMap::K_ROLE => Roles::IGNORE];
            $role   = (string) ($config[FieldMap::K_ROLE] ?? Roles::IGNORE);
            $rows[] = [
                'field'     => $field,
                'role'      => $role,
                'role_name' => $this->name([FieldMap::K_FIELDS, $field, FieldMap::K_ROLE]),
                'role_id'   => 'mbuzz-role-' . $index,
                'key'       => (string) ($config[FieldMap::K_KEY] ?? ''),
                'key_name'  => $this->name([FieldMap::K_FIELDS, $field, FieldMap::K_KEY]),
                'key_id'    => 'mbuzz-key-' . $index,
                // Only trait/property carry a mbuzz name; user_id/revenue/currency/ignore
                // don't — the template hides the name input for those.
                'key_used'  => in_array($role, Roles::KEYED, true),
            ];
        }

        return $rows;
    }

    /**
     * The scanned CF7 tags, plus any field the map already covers that the
     * scanner cannot see. Some form fields are injected into the template as
     * raw HTML by other plugins (e.g. a booking integration's hidden inputs);
     * they are absent from scan_form_tags() but present in the posted data, so
     * a saved mapping for one must stay visible and editable rather than
     * silently vanishing from the table.
     *
     * @param array<int, string> $fieldNames
     * @return array<int, string>
     */
    private static function withMappedFields(FieldMap $map, array $fieldNames): array
    {
        $extra = array_diff(array_keys($map->fields), $fieldNames);

        return array_merge(array_values($fieldNames), array_values($extra));
    }

    /**
     * Build a POST name like mbuzz_map[fields][CustomerEmail][role].
     *
     * @param array<int, string> $segments
     */
    private function name(array $segments): string
    {
        $name = $this->postKey;
        foreach ($segments as $segment) {
            $name .= '[' . $segment . ']';
        }

        return $name;
    }
}
