<?php
/**
 * Contact Form 7 runtime adapter. Hooks `wpcf7_submit`, and on a successful
 * submission hands a Cf7FormSource to the TrackingEngine. All mapping logic
 * lives in the engine + the form's saved map — this class only reads CF7's
 * submission (posted data + the submitting page from the submission meta).
 * Tracking is opt-in: a form with no saved map fires nothing.
 *
 * @package Mbuzz\WP\Integrations
 */

declare(strict_types=1);

namespace Mbuzz\WP\Integrations;

use Mbuzz\WP\Privacy\Consent;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\TrackingEngine;

final class ContactForm7
{
    public const RESULT_STATUS      = 'status';
    public const STATUS_MAIL_SENT   = 'mail_sent';
    public const STATUS_MAIL_FAILED = 'mail_failed';
    public const STATUS_DEMO_MODE   = 'demo_mode';

    private const META_CONTAINER_POST = 'container_post_id';
    private const META_URL            = 'url';

    /** Recorded when CF7 itself rejected the submission before we saw it. */
    public const OUTCOME_CF7_STATUS = 'cf7_status';

    /** Recorded when the WP Consent API withheld consent. */
    public const OUTCOME_NO_CONSENT = 'no_consent';

    /**
     * Statuses that represent a real, completed submission.
     *
     * CF7 reaches exactly one of six statuses. Three mean the submission never
     * happened — `validation_failed`, `spam`, `aborted` — and three mean it did:
     * `mail_sent`, `demo_mode`, and `mail_failed`.
     *
     * `mail_failed` belongs here. It means the form validated, was accepted and
     * was processed, and only the *email delivery* failed (see CF7's
     * WPCF7_Submission::proceed()). That is a lead. Forms wired to a CRM often
     * disable or misconfigure mail deliberately, and gating on mail delivery
     * silently discards every submission they take.
     */
    public const SUCCESS_STATUSES = [
        self::STATUS_MAIL_SENT,
        self::STATUS_MAIL_FAILED,
        self::STATUS_DEMO_MODE,
    ];

    /** @var callable|null test seam: posted data */
    private static $postedDataProvider = null;

    /** @var callable|null test seam: page descriptor */
    private static $pageProvider = null;

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
        $status = (string) ($result[self::RESULT_STATUS] ?? '');

        if (! in_array($status, self::SUCCESS_STATUSES, true)) {
            TrackingEngine::note(self::OUTCOME_CF7_STATUS . ':' . ($status !== '' ? $status : 'none'));
            return;
        }
        if (! Consent::granted()) {
            TrackingEngine::note(self::OUTCOME_NO_CONSENT);
            return;
        }

        TrackingEngine::handle(new Cf7FormSource($contactForm, self::postedData(), self::page()));
    }

    /**
     * The submitting page, read from the CF7 submission meta (the container post
     * id and url are not part of get_posted_data()).
     *
     * @return array<string, mixed>
     */
    private static function page(): array
    {
        if (self::$pageProvider !== null) {
            $page = (self::$pageProvider)();
            return is_array($page) ? $page : [];
        }

        $submission = self::submission();
        if ($submission === null) {
            return [];
        }

        $page = [];
        $id   = (int) $submission->get_meta(self::META_CONTAINER_POST);
        $url  = (string) $submission->get_meta(self::META_URL);
        if ($id > 0) {
            $page[FieldMap::PAGE_ID]    = $id;
            $page[FieldMap::PAGE_TITLE] = (string) get_the_title($id);
        }
        if ($url !== '') {
            $page[FieldMap::PAGE_URL] = $url;
        }

        return $page;
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

        $submission = self::submission();
        $data = $submission !== null ? $submission->get_posted_data() : [];

        return is_array($data) ? $data : [];
    }

    private static function submission(): ?object
    {
        if (! class_exists('WPCF7_Submission')) {
            return null;
        }
        $submission = \WPCF7_Submission::get_instance();

        return is_object($submission) ? $submission : null;
    }

    public static function setPostedDataProviderForTests(?callable $provider): void
    {
        self::$postedDataProvider = $provider;
    }

    public static function setPageProviderForTests(?callable $provider): void
    {
        self::$pageProvider = $provider;
    }
}
