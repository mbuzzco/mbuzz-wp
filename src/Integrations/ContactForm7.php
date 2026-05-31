<?php
/**
 * Contact Form 7 runtime adapter. Hooks `wpcf7_submit`, and on a successful
 * submission hands a Cf7FormSource to the TrackingEngine. All mapping logic
 * lives in the engine + the form's saved map — this class only reads CF7's
 * submission and resolves the submitting page. Tracking is opt-in: a form with
 * no saved map fires nothing.
 *
 * @package Mbuzz\WP\Integrations
 */

declare(strict_types=1);

namespace Mbuzz\WP\Integrations;

use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\TrackingEngine;

final class ContactForm7
{
    public const RESULT_STATUS      = 'status';
    public const STATUS_MAIL_SENT   = 'mail_sent';
    public const STATUS_DEMO_MODE   = 'demo_mode';
    public const FIELD_CONTAINER_POST = '_wpcf7_container_post';

    /** Statuses that represent a real, completed submission. */
    public const SUCCESS_STATUSES = [self::STATUS_MAIL_SENT, self::STATUS_DEMO_MODE];

    /** Test seam for posted data (WPCF7_Submission doesn't exist in unit tests). */
    private static $postedDataProvider = null;

    public static function register(): void
    {
        if (! self::isAvailable()) {
            return;
        }
        add_action('wpcf7_submit', [self::class, 'onSubmit'], 10, 2);
    }

    private static function isAvailable(): bool
    {
        return class_exists('WPCF7_ContactForm');
    }

    /**
     * wpcf7_submit: ($contact_form, $result)
     *
     * @param object               $contactForm duck-typed: id(), title()
     * @param array<string, mixed> $result
     */
    public static function onSubmit(object $contactForm, array $result): void
    {
        if (! in_array($result[self::RESULT_STATUS] ?? '', self::SUCCESS_STATUSES, true)) {
            return;
        }

        $posted = self::postedData();
        TrackingEngine::handle(new Cf7FormSource($contactForm, $posted, self::page($posted)));
    }

    /**
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    private static function page(array $posted): array
    {
        $id = (int) ($posted[self::FIELD_CONTAINER_POST] ?? 0);
        if ($id <= 0) {
            return [];
        }

        return [
            FieldMap::PAGE_ID    => $id,
            FieldMap::PAGE_TITLE => (string) get_the_title($id),
            FieldMap::PAGE_URL   => (string) get_permalink($id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function postedData(): array
    {
        if (self::$postedDataProvider !== null) {
            $data = (self::$postedDataProvider)();
            return is_array($data) ? $data : [];
        }

        if (! class_exists('WPCF7_Submission')) {
            return [];
        }

        $submission = \WPCF7_Submission::get_instance();
        $data = $submission !== null ? $submission->get_posted_data() : [];

        return is_array($data) ? $data : [];
    }

    /**
     * For tests — inject canned posted data in place of WPCF7_Submission.
     */
    public static function setPostedDataProviderForTests(?callable $provider): void
    {
        self::$postedDataProvider = $provider;
    }
}
