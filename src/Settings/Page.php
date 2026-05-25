<?php
/**
 * Settings → Mbuzz admin screen. Form, sanitization, diagnostics card.
 *
 * STUB — wires the menu and renders an "under construction" notice. Field
 * rendering, sanitization callbacks, and the diagnostics card are tracked
 * in lib/specs/wordpress-plugin.md §4 and will land in subsequent commits.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

final class Page
{
    private const MENU_SLUG = 'mbuzz';
    private const OPTION_GROUP = 'mbuzz_attribution';

    public static function registerMenu(): void
    {
        add_options_page(
            __('Mbuzz Attribution', 'mbuzz-attribution'),
            __('Mbuzz', 'mbuzz-attribution'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

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

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Mbuzz Attribution', 'mbuzz-attribution') . '</h1>';
        echo '<p>' . esc_html__('Settings UI is under construction. For now, configure via wp-config.php constants: MBUZZ_API_KEY, MBUZZ_ENABLED, MBUZZ_DEBUG.', 'mbuzz-attribution') . '</p>';
        echo '</div>';
    }
}
