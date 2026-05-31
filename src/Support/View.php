<?php
/**
 * Minimal template loader. Renders a plain-PHP template from `templates/` with
 * a view-model in scope. Templates do escaped output only — no engine bundled
 * (WP.org-friendly). Markup never lives in a class.
 *
 * @package Mbuzz\WP\Support
 */

declare(strict_types=1);

namespace Mbuzz\WP\Support;

final class View
{
    /**
     * @param array<string, mixed> $data view-model
     */
    public static function render(string $template, array $data = []): void
    {
        $file = self::path($template);
        if (! is_file($file)) {
            return;
        }

        (static function (string $mbuzzTemplateFile, array $mbuzzViewModel): void {
            extract($mbuzzViewModel, EXTR_SKIP);
            require $mbuzzTemplateFile;
        })($file, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function capture(string $template, array $data = []): string
    {
        ob_start();
        self::render($template, $data);

        return (string) ob_get_clean();
    }

    private static function path(string $template): string
    {
        return MBUZZ_ATTRIBUTION_DIR . 'templates/' . $template . '.php';
    }
}
