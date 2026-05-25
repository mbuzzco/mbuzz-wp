<?php
/**
 * SDK boot pipeline. Pulled out of Plugin so the same code path can be
 * invoked on switch_blog without re-entering the activation hook flow.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP;

use Mbuzz\Mbuzz;
use Mbuzz\WP\Settings\Repository as SettingsRepository;

final class Bootstrap
{
    /**
     * @param array{api_key:string, enabled:bool, debug:bool, skip_paths:array<string>} $settings
     */
    public static function boot(array $settings): void
    {
        if ($settings['api_key'] === '') {
            return;
        }

        Mbuzz::init([
            'api_key'    => $settings['api_key'],
            'enabled'    => $settings['enabled'],
            'debug'      => $settings['debug'],
            'skip_paths' => array_merge(
                ['/wp-admin/', '/wp-login.php', '/wp-cron.php'],
                $settings['skip_paths']
            ),
        ]);

        self::wireObservers($settings['debug']);
    }

    /**
     * Wire the SDK's onSuccess/onError hooks for the diagnostics card timestamp
     * and (when debug=on) the WP_DEBUG_LOG channel.
     */
    private static function wireObservers(bool $debug): void
    {
        Mbuzz::onSuccess(function (string $method, string $url, int $status): void {
            set_transient(
                SettingsRepository::TRANSIENT_LAST_CALL,
                ['method' => $method, 'url' => $url, 'status' => $status, 'at' => time()],
                DAY_IN_SECONDS
            );
        });

        if ($debug) {
            Mbuzz::onError(function (string $method, string $url, int $status, ?array $body, ?\Throwable $exception): void {
                $detail = $exception !== null
                    ? $exception->getMessage()
                    : (is_array($body) ? wp_json_encode($body) : '(no body)');
                error_log("[Mbuzz] {$method} {$url} -> {$status}: {$detail}");
            });
        }
    }
}
