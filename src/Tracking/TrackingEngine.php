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

    public static function handle(FormSource $form): void
    {
        if (Mbuzz::getClient() === null) {
            return; // SDK not initialized (no API key)
        }

        $map = FieldMapRepository::for($form->source(), $form->formId());
        if ($map === null || ! $map->isTrackable()) {
            return; // opt-in: unconfigured/disabled forms fire nothing
        }

        if (apply_filters(self::FILTER_SKIP, false, $form)) {
            return;
        }

        self::dispatch($map->resolve($form->postedData(), $form->page()));
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
