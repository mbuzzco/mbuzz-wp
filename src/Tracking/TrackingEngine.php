<?php
/**
 * Turns a form submission into mbuzz calls, driven entirely by the form's saved
 * map. Opt-in: with no trackable map, nothing fires. Plugin-agnostic — it only
 * speaks FormSource and the map.
 *
 * @package Mbuzz\WP\Tracking
 */

declare(strict_types=1);

namespace Mbuzz\WP\Tracking;

use Mbuzz\Mbuzz;
use Mbuzz\WP\Integrations\FormSource;

final class TrackingEngine
{
    /** Filter: return true to skip tracking a given submission. */
    public const FILTER_SKIP = 'mbuzz_skip_tracking';

    /**
     * The last submission this site saw, and what happened to it. Every exit
     * from handle() records one, so an admin can see WHY a form did not track
     * without shell access to a debug log — the difference between "nothing
     * was sent" and "it was sent and rejected" is otherwise invisible.
     */
    public const TRANSIENT_LAST_SUBMISSION = 'mbuzz_attribution_last_submission';

    public const OUTCOME_NO_API_KEY        = 'no_api_key';
    public const OUTCOME_NOT_CONFIGURED    = 'not_configured';
    public const OUTCOME_SKIPPED_BY_FILTER = 'skipped_by_filter';
    public const OUTCOME_SENT              = 'sent';

    public static function handle(FormSource $form): void
    {
        if (Mbuzz::getClient() === null) {
            self::record($form, self::OUTCOME_NO_API_KEY);
            return; // SDK not initialized (no API key)
        }

        $map = FieldMapRepository::for($form->source(), $form->formId());
        if ($map === null || ! $map->isTrackable()) {
            self::record($form, self::OUTCOME_NOT_CONFIGURED);
            return; // opt-in: unconfigured/disabled forms fire nothing
        }

        if (apply_filters(self::FILTER_SKIP, false, $form)) {
            self::record($form, self::OUTCOME_SKIPPED_BY_FILTER);
            return;
        }

        $hit = $map->resolve($form->postedData(), $form->page());
        self::dispatch($hit);
        self::record($form, self::OUTCOME_SENT, $hit->type);
    }

    /**
     * Record an outcome reached before a FormSource exists (CF7 rejected the
     * submission, or consent was withheld).
     */
    public static function note(string $outcome): void
    {
        set_transient(
            self::TRANSIENT_LAST_SUBMISSION,
            ['outcome' => $outcome, 'at' => time()],
            DAY_IN_SECONDS
        );
    }

    private static function record(FormSource $form, string $outcome, ?string $type = null): void
    {
        set_transient(
            self::TRANSIENT_LAST_SUBMISSION,
            [
                'outcome' => $outcome,
                'source'  => $form->source(),
                'form_id' => $form->formId(),
                'title'   => $form->formTitle(),
                'type'    => $type,
                'at'      => time(),
            ],
            DAY_IN_SECONDS
        );
    }

    private static function dispatch(ResolvedHit $hit): void
    {
        if ($hit->hasIdentity()) {
            Mbuzz::identify($hit->userId, $hit->traits);
        }

        if ($hit->trackAs === TrackAs::EVENT) {
            Mbuzz::event($hit->type, $hit->properties);
            return;
        }

        $options = [ConversionOptions::PROPERTIES => $hit->properties];
        if ($hit->userId !== null) {
            $options[ConversionOptions::USER_ID] = $hit->userId;
        }
        if ($hit->revenue !== null) {
            $options[ConversionOptions::REVENUE] = $hit->revenue;
        }
        if ($hit->currency !== null) {
            $options[ConversionOptions::CURRENCY] = $hit->currency;
        }

        Mbuzz::conversion($hit->type, $options);
    }
}
