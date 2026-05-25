<?php
/**
 * Settings storage. Single option key, serialized array. wp-config.php
 * constants override the DB row for managed-host / multisite deploys.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

final class Repository
{
    public const OPTION_KEY = 'mbuzz_attribution_settings';
    public const TRANSIENT_LAST_CALL = 'mbuzz_attribution_last_call';

    /**
     * @return array{
     *   api_key: string,
     *   enabled: bool,
     *   debug: bool,
     *   track_admins: bool,
     *   skip_paths: array<string>,
     *   identify_at: string,
     *   wc_track_purchases: bool,
     *   wc_include_order_details: bool,
     *   wc_mark_first_as_acquisition: bool
     * }
     */
    public static function current(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $merged = array_merge(self::defaults(), $stored);

        // wp-config.php overrides — the source of truth on managed hosts.
        if (defined('MBUZZ_API_KEY')) {
            $merged['api_key'] = (string) MBUZZ_API_KEY;
        }
        if (defined('MBUZZ_ENABLED')) {
            $merged['enabled'] = (bool) MBUZZ_ENABLED;
        }
        if (defined('MBUZZ_DEBUG')) {
            $merged['debug'] = (bool) MBUZZ_DEBUG;
        }

        return $merged;
    }

    public static function ensureDefaults(): void
    {
        if (get_option(self::OPTION_KEY, null) === null) {
            add_option(self::OPTION_KEY, self::defaults());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'api_key'                       => '',
            'enabled'                       => true,
            'debug'                         => false,
            'track_admins'                  => false,
            'skip_paths'                    => [],
            'identify_at'                   => 'login', // 'login' | 'every_page'
            'wc_track_purchases'            => true,
            'wc_include_order_details'      => true,
            'wc_mark_first_as_acquisition'  => true,
        ];
    }

    /**
     * Whether the given setting is locked by a wp-config.php constant.
     */
    public static function isLocked(string $key): bool
    {
        return match ($key) {
            'api_key' => defined('MBUZZ_API_KEY'),
            'enabled' => defined('MBUZZ_ENABLED'),
            'debug'   => defined('MBUZZ_DEBUG'),
            default   => false,
        };
    }
}
