<?php
/**
 * Click events: record a named event when a visitor clicks something on the
 * site, for whatever the site owner chooses. The plugin is a conduit, so it
 * ships with no rules. The owner lists them on the settings page, one per line
 * as `event_name = css selector`, or adds them with the FILTER_RULES filter.
 *
 * This class only holds the rules. The listener is assets/js/mbuzz-click-events.js,
 * which reports through the same first-party lead endpoint as captureLead.
 * It carries the matched selector and the page path, and nothing else.
 *
 * @package Mbuzz\WP\Integrations
 */

declare(strict_types=1);

namespace Mbuzz\WP\Integrations;

use Mbuzz\WP\Settings\Repository;

final class ClickEvents
{
    /** Filter: add to, or replace, the saved rules. Each rule is ['event' => string, 'selector' => string]. */
    public const FILTER_RULES = 'mbuzz_click_event_rules';

    /**
     * @return array{rules: array<int, array{event: string, selector: string}>}
     */
    public static function config(): array
    {
        return ['rules' => self::rules()];
    }

    public static function enabled(): bool
    {
        return self::rules() !== [];
    }

    /**
     * @return array<int, array{event: string, selector: string}>
     */
    private static function rules(): array
    {
        $saved = Repository::current()['click_events'] ?? [];
        $rules = apply_filters(self::FILTER_RULES, is_array($saved) ? $saved : []);

        return array_values(array_filter(
            is_array($rules) ? $rules : [],
            static fn ($rule): bool => is_array($rule)
                && is_string($rule['event'] ?? null) && $rule['event'] !== ''
                && is_string($rule['selector'] ?? null) && $rule['selector'] !== ''
        ));
    }
}
