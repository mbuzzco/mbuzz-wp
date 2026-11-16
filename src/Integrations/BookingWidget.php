<?php
/**
 * Booking-widget trigger capture: the click that opens an online booking form.
 *
 * The widget is vendor-hosted, but the buttons that open it live in the site's
 * own page, so a click listener on the host page can record "booking opened"
 * with no change on the vendor side. This class only holds the vocabulary (the
 * event name and which elements count); the listener is assets/js/mbuzz-booking.js,
 * which reports through the same first-party lead endpoint as captureLead.
 *
 * Carries nothing but which trigger was used and the page path. Never reads
 * anything inside the widget.
 *
 * @package Mbuzz\WP\Integrations
 */

declare(strict_types=1);

namespace Mbuzz\WP\Integrations;

final class BookingWidget
{
    public const EVENT = 'booking_opened';

    /** Filter: replace the CSS selectors that count as a booking trigger. */
    public const FILTER_SELECTORS = 'mbuzz_booking_trigger_selectors';

    /** The three buttons the widget's host page uses to open it. */
    public const DEFAULT_SELECTORS = [
        '.ola-trigger-desktop',
        '.ola-trigger-mobile',
        '.ola-booking-button',
    ];

    /**
     * @return array{event: string, selectors: array<int, string>}
     */
    public static function config(): array
    {
        return [
            'event'     => self::EVENT,
            'selectors' => self::selectors(),
        ];
    }

    public static function enabled(): bool
    {
        return self::selectors() !== [];
    }

    /**
     * @return array<int, string>
     */
    private static function selectors(): array
    {
        $selectors = apply_filters(self::FILTER_SELECTORS, self::DEFAULT_SELECTORS);

        return array_values(array_filter(
            is_array($selectors) ? $selectors : [],
            static fn ($selector): bool => is_string($selector) && $selector !== ''
        ));
    }
}
