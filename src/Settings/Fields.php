<?php
/**
 * Settings sanitization. Called by register_setting's sanitize_callback.
 *
 * STUB — full sanitization rules per spec §4 will land alongside the field
 * rendering UI. For now, just merges with defaults and casts booleans so
 * a minimally-populated form submission doesn't corrupt the option row.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

final class Fields
{
    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize($input): array
    {
        if (! is_array($input)) {
            return Repository::defaults();
        }

        $clean = Repository::defaults();

        if (! Repository::isLocked('api_key') && isset($input['api_key'])) {
            $submitted = sanitize_text_field((string) $input['api_key']);
            if ($submitted !== '') {
                $clean['api_key'] = $submitted;
            } else {
                // Blank submit = "keep the current key". The field is rendered
                // empty (never echoes the saved key back), so an empty value
                // must not wipe a configured key.
                $existing = get_option(Repository::OPTION_KEY, []);
                $clean['api_key'] = (is_array($existing) && isset($existing['api_key']))
                    ? (string) $existing['api_key']
                    : '';
            }
        }
        if (! Repository::isLocked('enabled')) {
            $clean['enabled'] = ! empty($input['enabled']);
        }
        if (! Repository::isLocked('debug')) {
            $clean['debug'] = ! empty($input['debug']);
        }

        $clean['track_admins']                 = ! empty($input['track_admins']);
        $clean['wc_track_purchases']           = ! empty($input['wc_track_purchases']);
        $clean['wc_include_order_details']     = ! empty($input['wc_include_order_details']);
        $clean['wc_mark_first_as_acquisition'] = ! empty($input['wc_mark_first_as_acquisition']);

        if (isset($input['identify_at']) && in_array($input['identify_at'], ['login', 'every_page'], true)) {
            $clean['identify_at'] = $input['identify_at'];
        }

        if (isset($input['skip_paths']) && is_string($input['skip_paths'])) {
            $lines = array_filter(array_map('trim', explode("\n", $input['skip_paths'])));
            $clean['skip_paths'] = array_values(array_map('sanitize_text_field', $lines));
        }

        return $clean;
    }
}
