<?php
/**
 * One form as the Conversions overview sees it: its display title, the URL to
 * its editor, and its saved map (null when the admin has never configured it).
 * The controller builds these from whatever form plugin is present; the
 * presenter consumes them without touching WordPress.
 *
 * @package Mbuzz\WP\Settings
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

use Mbuzz\WP\Tracking\FieldMap;

final class FormSummary
{
    public function __construct(
        public readonly string $title,
        public readonly string $editUrl,
        public readonly ?FieldMap $map,
    ) {
    }
}
