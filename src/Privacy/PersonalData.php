<?php
/**
 * WordPress Privacy API integration: a personal-data exporter + eraser.
 *
 * The plugin is a conduit — it transmits PII to mbuzz (a third party) but keeps
 * almost nothing locally. The only person-keyed local record is the
 * `_mbuzz_last_identified_at` user meta. So:
 *
 *  - the exporter returns that local record plus an honest disclosure that the
 *    person's data is processed by mbuzz (api.mbuzz.co);
 *  - the eraser deletes the local meta and reports that the third-party copy at
 *    mbuzz is retained (the SDK exposes no deletion endpoint) and must be
 *    erased by a request to mbuzz directly.
 *
 * Form submitters with no WordPress account leave no local record, so there is
 * nothing for those emails to export or erase here.
 *
 * @package Mbuzz\WP\Privacy
 */

declare(strict_types=1);

namespace Mbuzz\WP\Privacy;

use Mbuzz\WP\Identity\Hooks;

final class PersonalData
{
    public const KEY      = 'mbuzz-attribution';
    public const GROUP_ID = 'mbuzz-attribution';
    public const ITEM_ID  = 'mbuzz-attribution-identity';

    private const FILTER_EXPORTERS = 'wp_privacy_personal_data_exporters';
    private const FILTER_ERASERS   = 'wp_privacy_personal_data_erasers';

    public static function register(): void
    {
        add_filter(self::FILTER_EXPORTERS, [self::class, 'registerExporter']);
        add_filter(self::FILTER_ERASERS, [self::class, 'registerEraser']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function registerExporter(array $exporters): array
    {
        $exporters[self::KEY] = [
            'exporter_friendly_name' => __('Mbuzz attribution', 'mbuzz-attribution'),
            'callback'               => [self::class, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function registerEraser(array $erasers): array
    {
        $erasers[self::KEY] = [
            'eraser_friendly_name' => __('Mbuzz attribution', 'mbuzz-attribution'),
            'callback'             => [self::class, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{data: array<int, mixed>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $data = [];
        $user = get_user_by('email', $email);

        if (self::isUser($user)) {
            $items = [
                ['name' => __('Processed by', 'mbuzz-attribution'), 'value' => 'mbuzz (api.mbuzz.co)'],
                ['name' => __('What is shared', 'mbuzz-attribution'), 'value' => __('Your identity (a user ID, plus any traits the site\'s forms map — for example email or name), page touchpoints, and any conversions or events you triggered.', 'mbuzz-attribution')],
            ];

            $identifiedAt = get_user_meta((int) $user->ID, Hooks::META_LAST_IDENTIFIED_AT, true);
            if ($identifiedAt !== '' && $identifiedAt !== false) {
                $items[] = [
                    'name'  => __('Last identified to mbuzz', 'mbuzz-attribution'),
                    'value' => gmdate('Y-m-d H:i:s', (int) $identifiedAt) . ' UTC',
                ];
            }

            $data[] = [
                'group_id'    => self::GROUP_ID,
                'group_label' => __('Mbuzz attribution', 'mbuzz-attribution'),
                'item_id'     => self::ITEM_ID,
                'data'        => $items,
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        $removed = false;
        $user    = get_user_by('email', $email);

        if (self::isUser($user)
            && get_user_meta((int) $user->ID, Hooks::META_LAST_IDENTIFIED_AT, true) !== ''
        ) {
            delete_user_meta((int) $user->ID, Hooks::META_LAST_IDENTIFIED_AT);
            $removed = true;
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => true, // the third-party copy at mbuzz cannot be auto-erased
            'messages'       => [
                __('Personal data transmitted to mbuzz (api.mbuzz.co) is processed by a third party and is not stored on this site, so it cannot be erased automatically. To erase it, submit a request to mbuzz directly.', 'mbuzz-attribution'),
            ],
            'done'           => true,
        ];
    }

    /**
     * @param mixed $user
     */
    private static function isUser($user): bool
    {
        return is_object($user) && isset($user->ID) && (int) $user->ID > 0;
    }
}
