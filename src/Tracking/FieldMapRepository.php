<?php
/**
 * Resolves and persists a form's map per source. CF7 stores it in the form's
 * post meta (`_mbuzz_form_map`) so it travels with the form and is never
 * autoloaded. Later sources (Gravity feeds, Fluent form meta) slot in here.
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

final class FieldMapRepository
{
    public const META_KEY = '_mbuzz_form_map';

    /**
     * @param int|string $formId
     */
    public static function for(string $source, $formId): ?FieldMap
    {
        if ($source === Source::CF7) {
            $raw = get_post_meta((int) $formId, self::META_KEY, true);
            return is_array($raw) ? FieldMap::fromArray($raw) : null;
        }

        return null;
    }

    /**
     * @param int|string $formId
     */
    public static function save(string $source, $formId, FieldMap $map): void
    {
        if ($source === Source::CF7) {
            update_post_meta((int) $formId, self::META_KEY, $map->toArray());
        }
    }
}
