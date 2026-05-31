<?php
/**
 * Builds the view-model for the Conversions overview from a list of
 * FormSummary objects. Pure: no WordPress, no escaping, no i18n — the template
 * turns track_as/type into labels. Unit-tested directly.
 *
 * @package Mbuzz\WP\Settings
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

use Mbuzz\WP\Support\Links;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;

final class ConversionsOverviewPresenter
{
    /**
     * @param array<int, FormSummary> $forms
     * @return array<string, mixed>
     */
    public function present(array $forms, bool $hasApiKey): array
    {
        $rows = array_map([$this, 'row'], array_values($forms));

        return [
            'has_api_key'   => $hasApiKey,
            'docs_url'      => Links::DOCS,
            'has_forms'     => $rows !== [],
            'tracked_count' => count(array_filter($rows, static fn (array $row): bool => $row['tracked'])),
            'rows'          => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(FormSummary $form): array
    {
        $map = $form->map;

        return [
            'title'         => $form->title,
            'edit_url'      => $form->editUrl,
            'tracked'       => $map !== null && $map->isTrackable(),
            'track_as'      => $map?->trackAs ?? TrackAs::OFF,
            'type'          => $map?->type ?? '',
            'mapped_count'  => $this->countRoles($map, static fn (string $role): bool => $role !== Roles::IGNORE),
            'ignored_count' => $this->countRoles($map, static fn (string $role): bool => $role === Roles::IGNORE),
            'has_user_id'   => $this->countRoles($map, static fn (string $role): bool => $role === Roles::USER_ID) > 0,
        ];
    }

    private function countRoles(?FieldMap $map, callable $predicate): int
    {
        if ($map === null) {
            return 0;
        }

        $count = 0;
        foreach ($map->fields as $config) {
            $role = is_array($config) ? (string) ($config[FieldMap::K_ROLE] ?? Roles::IGNORE) : Roles::IGNORE;
            if ($predicate($role)) {
                $count++;
            }
        }

        return $count;
    }
}
