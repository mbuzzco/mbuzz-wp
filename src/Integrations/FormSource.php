<?php
/**
 * A normalized view of one form submission, independent of which form plugin
 * produced it. Adapters (CF7 now; Gravity / WPForms / Fluent later) implement
 * this so the TrackingEngine never knows the plugin.
 *
 * `postedData()` is keyed by the same field identifier the map keys on — the
 * field name for CF7/WPForms/Fluent, the field id for Gravity.
 *
 * @package Mbuzz\WP\Integrations
 */

declare(strict_types=1);

namespace Mbuzz\WP\Integrations;

interface FormSource
{
    /** One of Mbuzz\WP\Tracking\Source::* */
    public function source(): string;

    /** @return int|string the form's id within its plugin */
    public function formId();

    public function formTitle(): string;

    /** @return array<string, mixed> submission values keyed by field identifier */
    public function postedData(): array;

    /** @return array<string, mixed> {id, title, url} of the submitting page, or [] */
    public function page(): array;
}
