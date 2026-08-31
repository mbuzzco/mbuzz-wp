<?php
/**
 * Settings → Mbuzz admin screen. Form (API key + toggles) + diagnostics card.
 *
 * The API key is never echoed back to the page (spec §13) — the field renders
 * empty with a "saved" placeholder, and a blank save keeps the current key
 * (see Fields::sanitize). Settings locked by wp-config.php constants render
 * disabled with a hint.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

use Mbuzz\WP\Integrations\ContactForm7;
use Mbuzz\WP\Tracking\TrackingEngine;

final class Page
{
    /** Submenu slug under the Mbuzz top-level menu (registered by ConversionsPage). */
    public const SETTINGS_SLUG = 'mbuzz-settings';

    private const OPTION_GROUP = 'mbuzz_attribution';

    public static function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            Repository::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [Fields::class, 'sanitize'],
                'default'           => Repository::defaults(),
            ]
        );
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings  = Repository::current();
        $option    = Repository::OPTION_KEY;
        $hasKey    = $settings['api_key'] !== '';
        $keyLocked = Repository::isLocked('api_key');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Mbuzz Attribution', 'mbuzz-attribution') . '</h1>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="mbuzz_api_key">' . esc_html__('API key', 'mbuzz-attribution') . '</label></th><td>';
        if ($keyLocked) {
            printf('<input type="text" class="regular-text" value="%s" disabled>', esc_attr(self::maskKey((string) $settings['api_key'])));
            echo '<p class="description">' . esc_html__('Locked by wp-config.php (MBUZZ_API_KEY).', 'mbuzz-attribution') . '</p>';
        } else {
            printf(
                '<input type="password" id="mbuzz_api_key" name="%s[api_key]" value="" class="regular-text" autocomplete="off" placeholder="%s">',
                esc_attr($option),
                esc_attr($hasKey ? __('•••••••• saved — leave blank to keep', 'mbuzz-attribution') : 'sk_live_… or sk_test_…')
            );
            echo '<p class="description">' . esc_html__('Your mbuzz API key. Use sk_test_… to trial, sk_live_… for production. Leave blank to keep the current key.', 'mbuzz-attribution') . '</p>';
        }
        echo '</td></tr>';

        self::checkboxRow($option, 'enabled', __('Enable tracking', 'mbuzz-attribution'), (bool) $settings['enabled'], Repository::isLocked('enabled'), __('Master switch. Turn off to stop all tracking.', 'mbuzz-attribution'));
        self::checkboxRow($option, 'track_admins', __('Track logged-in admins', 'mbuzz-attribution'), (bool) $settings['track_admins'], false, __('Off by default so internal QA does not pollute attribution.', 'mbuzz-attribution'));
        self::checkboxRow($option, 'debug', __('Debug logging', 'mbuzz-attribution'), (bool) $settings['debug'], Repository::isLocked('debug'), __('Logs API calls to wp-content/debug.log (requires WP_DEBUG_LOG).', 'mbuzz-attribution'));

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        self::renderDiagnostics($settings, $hasKey, $keyLocked);
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function renderDiagnostics(array $settings, bool $hasKey, bool $keyLocked): void
    {
        $lastCall = get_transient(Repository::TRANSIENT_LAST_CALL);

        echo '<hr><h2>' . esc_html__('Diagnostics', 'mbuzz-attribution') . '</h2>';
        echo '<p><strong>' . esc_html__('Plugin version:', 'mbuzz-attribution') . '</strong> ' . esc_html(MBUZZ_ATTRIBUTION_VERSION) . '</p>';

        echo '<p><strong>' . esc_html__('API key:', 'mbuzz-attribution') . '</strong> ';
        if ($hasKey) {
            echo esc_html(self::maskKey((string) $settings['api_key'])) . ' ' . esc_html($keyLocked ? '(wp-config.php)' : '(saved)');
        } else {
            echo '<span style="color:#b32d2e">' . esc_html__('not set — tracking is inactive', 'mbuzz-attribution') . '</span>';
        }
        echo '</p>';

        self::renderLastSubmission();

        echo '<p><strong>' . esc_html__('Last successful API call:', 'mbuzz-attribution') . '</strong> ';
        if (is_array($lastCall) && ! empty($lastCall['at'])) {
            echo esc_html(gmdate('Y-m-d H:i:s', (int) $lastCall['at'])) . ' UTC — '
                . esc_html((string) ($lastCall['method'] ?? '')) . ' '
                . esc_html((string) ($lastCall['url'] ?? '')) . ' ('
                . esc_html((string) ($lastCall['status'] ?? '')) . ')';
        } else {
            echo esc_html__('none yet — browse the site (logged out) to generate one', 'mbuzz-attribution');
        }
        echo '</p>';
    }

    private static function checkboxRow(string $option, string $key, string $label, bool $checked, bool $locked, string $description): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        printf(
            '<label><input type="checkbox" name="%s[%s]" value="1"%s%s> %s</label>',
            esc_attr($option),
            esc_attr($key),
            checked($checked, true, false),
            $locked ? ' disabled' : '',
            esc_html__('Enabled', 'mbuzz-attribution')
        );
        if ($locked) {
            echo ' <span class="description">' . esc_html__('Locked by wp-config.php', 'mbuzz-attribution') . '</span>';
        }
        echo '<p class="description">' . esc_html($description) . '</p>';
        echo '</td></tr>';
    }

    private static function maskKey(string $key): string
    {
        return $key === '' ? '' : substr($key, 0, 11) . str_repeat('•', 8);
    }
    /**
     * The last form submission this site saw and what became of it. Without
     * this, a form that fires nothing is indistinguishable from one whose call
     * was rejected — and diagnosing the difference needs shell access.
     */
    private static function renderLastSubmission(): void
    {
        $last = get_transient(TrackingEngine::TRANSIENT_LAST_SUBMISSION);

        echo '<p><strong>' . esc_html__('Last form submission:', 'mbuzz-attribution') . '</strong> ';

        if (! is_array($last) || empty($last['at'])) {
            echo esc_html__('none seen yet', 'mbuzz-attribution') . '</p>';
            return;
        }

        echo esc_html(gmdate('Y-m-d H:i:s', (int) $last['at'])) . ' UTC';

        if (! empty($last['title'])) {
            echo ' — ' . esc_html((string) $last['title']);
        }

        echo ' — ' . esc_html(self::outcomeLabel((string) $last['outcome']));

        if (! empty($last['type'])) {
            echo ' (' . esc_html((string) $last['type']) . ')';
        }

        echo '</p>';
    }

    private static function outcomeLabel(string $outcome): string
    {
        $labels = [
            TrackingEngine::OUTCOME_SENT              => __('sent to mbuzz', 'mbuzz-attribution'),
            TrackingEngine::OUTCOME_NOT_CONFIGURED    => __('not sent — this form has no mbuzz mapping, or tracking is off for it', 'mbuzz-attribution'),
            TrackingEngine::OUTCOME_NO_API_KEY        => __('not sent — no API key', 'mbuzz-attribution'),
            TrackingEngine::OUTCOME_SKIPPED_BY_FILTER => __('not sent — skipped by a mbuzz_skip_tracking filter', 'mbuzz-attribution'),
            ContactForm7::OUTCOME_NO_CONSENT          => __('not sent — consent withheld', 'mbuzz-attribution'),
        ];

        if (isset($labels[$outcome])) {
            return $labels[$outcome];
        }

        if (str_starts_with($outcome, ContactForm7::OUTCOME_CF7_STATUS . ':')) {
            /* translators: %s: Contact Form 7 submission status */
            return sprintf(
                __('not sent — Contact Form 7 reported "%s", so the submission did not complete', 'mbuzz-attribution'),
                substr($outcome, strlen(ContactForm7::OUTCOME_CF7_STATUS) + 1)
            );
        }

        return $outcome;
    }

}
