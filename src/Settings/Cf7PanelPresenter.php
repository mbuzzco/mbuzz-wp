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
        foreach (array_values($fieldNames) as $index => $field) {
            $config = $map->fields[$field] ?? [FieldMap::K_ROLE => Roles::IGNORE];
            $rows[] = [
                'field'     => $field,
                'role'      => (string) ($config[FieldMap::K_ROLE] ?? Roles::IGNORE),
                'role_name' => $this->name([FieldMap::K_FIELDS, $field, FieldMap::K_ROLE]),
                'role_id'   => 'mbuzz-role-' . $index,
                'key'       => (string) ($config[FieldMap::K_KEY] ?? ''),
                'key_name'  => $this->name([FieldMap::K_FIELDS, $field, FieldMap::K_KEY]),
                'key_id'    => 'mbuzz-key-' . $index,
            ];
        }

        return $rows;
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
