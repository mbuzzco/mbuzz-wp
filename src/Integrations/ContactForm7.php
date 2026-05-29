<?php
/**
 * Contact Form 7 integration (spec §7).
 *
 * Fires a `lead` conversion on a successful CF7 submission. Hooks
 * `wpcf7_submit` (not `wpcf7_mail_sent`) so forms configured for webhook /
 * CRM-only delivery — which skip the mail step entirely — still convert.
 * Gated on the submission status so validation failures, spam, and aborted
 * submissions don't count.
 *
 * Field names vary per form (`your-email`, `CustomerEmail`, …) and some forms
 * (multi-step) don't render their fields in static HTML at all, so everything
 * is read from the posted submission data at runtime rather than assumed:
 *
 *   - The email becomes `user_id` AND the external id of an `identify()` call,
 *     so the lead stitches to an Identity (without identify, a cookie-resolved
 *     conversion lands Anonymous — the backend only auto-creates an identity
 *     from `user_id` on the cookieless path).
 *   - Name / phone are auto-mapped to the backend's canonical identity traits
 *     (`first_name`, `last_name`, `phone`) for match-key hashing.
 *   - Every submitted field is passed through into the conversion properties.
 *
 * Filters let integrators override any of this per form.
 *
 * @package Mbuzz\WP\Integrations
 */

declare(strict_types=1);

namespace Mbuzz\WP\Integrations;

use Mbuzz\Mbuzz;

final class ContactForm7
{
    /** Submission statuses that represent a real, completed lead. */
    private const SUCCESS_STATUSES = ['mail_sent', 'demo_mode'];

    /**
     * Test seam: when set, supplies posted form data instead of reading
     * WPCF7_Submission (which doesn't exist in unit tests).
     *
     * @var callable|null
     */
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
     * @param object               $contactForm Duck-typed — needs id() and title().
     * @param array<string, mixed> $result      CF7 result; we read $result['status'].
     */
    public static function onSubmit(object $contactForm, array $result): void
    {
        if (! in_array($result['status'] ?? '', self::SUCCESS_STATUSES, true)) {
            return;
        }
        if (apply_filters('mbuzz_cf7_skip_submission', false, $contactForm)) {
            return;
        }

        $posted = self::postedData();
        $email  = self::detectEmail($posted, $contactForm);

        // Stitch the lead to an Identity before the conversion, so the
        // conversion resolves a non-anonymous identity. Without this, a
        // cookie-resolved conversion ignores user_id for identity creation.
        if ($email !== null) {
            Mbuzz::identify($email, self::identifyTraits($posted, $contactForm, $email));
        }

        $options = ['properties' => self::properties($contactForm, $posted)];
        if ($email !== null) {
            $options['user_id'] = $email;
        }

        $type = (string) apply_filters('mbuzz_cf7_conversion_type', 'lead', $contactForm);
        Mbuzz::conversion($type, $options);
    }

    /**
     * Conversion properties: form identity plus every submitted field value,
     * so the lead's data is visible on the conversion. Internal CF7 fields are
     * stripped; form_id/form_title win on any name collision.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    private static function properties(object $contactForm, array $posted): array
    {
        $props = array_merge(
            self::fieldValues($posted),
            [
                'form_id'    => (int) $contactForm->id(),
                'form_title' => (string) $contactForm->title(),
            ]
        );

        /** @var array<string, mixed> $filtered */
        $filtered = apply_filters('mbuzz_cf7_conversion_properties', $props, $contactForm, $posted);

        return is_array($filtered) ? $filtered : $props;
    }

    /**
     * Canonical identity traits the backend hashes into match keys
     * (email/phone/first_name/last_name). Best-effort field-name mapping;
     * the `mbuzz_cf7_identify_traits` filter overrides for ambiguous forms.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    private static function identifyTraits(array $posted, object $contactForm, string $email): array
    {
        $traits = ['email' => $email];

        $phone = self::firstFieldMatching($posted, '/phone|mobile|tel/i');
        if ($phone !== null) {
            $traits['phone'] = $phone;
        }

        $first = self::firstFieldMatching($posted, '/first.?name/i');
        $last  = self::firstFieldMatching($posted, '/last.?name/i');
        if ($first !== null) {
            $traits['first_name'] = $first;
        }
        if ($last !== null) {
            $traits['last_name'] = $last;
        }

        // Single full-name field (e.g. CF7's default your-name) when the form
        // doesn't split first/last. Kept as a non-canonical trait.
        if ($first === null && $last === null) {
            $name = self::firstFieldMatching($posted, '/(^|[-_])name$/i');
            if ($name !== null) {
                $traits['name'] = $name;
            }
        }

        /** @var array<string, mixed> $filtered */
        $filtered = apply_filters('mbuzz_cf7_identify_traits', $traits, $contactForm, $posted);

        return is_array($filtered) ? $filtered : $traits;
    }

    /**
     * Resolve the email to use as `user_id` / identify external id.
     *
     *   1. Explicit field name via `mbuzz_cf7_user_id_field` filter, if present.
     *   2. Otherwise the first posted value that is a valid email address.
     *
     * @param array<string, mixed> $posted
     */
    private static function detectEmail(array $posted, object $contactForm): ?string
    {
        $named = apply_filters('mbuzz_cf7_user_id_field', null, $contactForm);
        if (is_string($named) && $named !== '' && isset($posted[$named])) {
            return self::firstEmailIn($posted[$named]);
        }

        foreach ($posted as $key => $value) {
            if (self::isInternalField($key)) {
                continue;
            }
            $email = self::firstEmailIn($value);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    /**
     * The submitted field values, internal CF7 fields stripped.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    private static function fieldValues(array $posted): array
    {
        $fields = [];
        foreach ($posted as $key => $value) {
            if (self::isInternalField($key)) {
                continue;
            }
            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * First non-empty scalar value of the first non-internal field whose name
     * matches $pattern.
     *
     * @param array<string, mixed> $posted
     */
    private static function firstFieldMatching(array $posted, string $pattern): ?string
    {
        foreach ($posted as $key => $value) {
            if (self::isInternalField($key) || ! is_string($key) || preg_match($pattern, $key) !== 1) {
                continue;
            }
            $scalar = self::firstScalar($value);
            if ($scalar !== null && $scalar !== '') {
                return $scalar;
            }
        }

        return null;
    }

    /**
     * A CF7 field value is a string or an array of strings (multi-value fields).
     * Return the first entry that validates as an email, or null.
     *
     * @param mixed $value
     */
    private static function firstEmailIn($value): ?string
    {
        foreach ((array) $value as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function firstScalar($value): ?string
    {
        foreach ((array) $value as $candidate) {
            if (is_scalar($candidate)) {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * @param mixed $key
     */
    private static function isInternalField($key): bool
    {
        return is_string($key) && str_starts_with($key, '_wpcf7');
    }

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
