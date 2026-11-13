<?php
/**
 * SDK boot pipeline. Pulled out of Plugin so the same code path can be
 * invoked on switch_blog without re-entering the activation hook flow.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP;

use Mbuzz\Config;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Settings\Repository as SettingsRepository;

final class Bootstrap
{
    /**
     * @param array{api_key:string, api_url?:string, enabled:bool, debug:bool, skip_paths:array<string>} $settings
     */
    public static function boot(array $settings): void
    {
        if ($settings['api_key'] === '') {
            return;
        }

        $apiUrl = $settings['api_url'] ?? '';

        try {
            Mbuzz::init(array_filter([
                'api_key'    => $settings['api_key'],
                'api_url'    => $apiUrl,
                'enabled'    => $settings['enabled'],
                'debug'      => $settings['debug'],
                'skip_paths' => array_merge(
                    ['/wp-admin/', '/wp-login.php', '/wp-cron.php'],
                    $settings['skip_paths']
                ),
            ], static fn ($value, $key): bool => $key !== 'api_url' || $value !== '', ARRAY_FILTER_USE_BOTH));
        } catch (\InvalidArgumentException $e) {
            // A region key without MBUZZ_API_URL (or the reverse), or a non-https
            // host. The SDK refuses; tracking stays off and the front end renders.
            error_log('[Mbuzz] Tracking disabled: ' . $e->getMessage());
            return;
        }

        // Fail closed: a bundled SDK that predates api_url ignores it and would
        // send a region key to api.mbuzz.co. Refuse rather than misdirect.
        if ($apiUrl !== '' && rtrim($apiUrl, '/') !== Config::getInstance()->getApiUrl()) {
            error_log('[Mbuzz] MBUZZ_API_URL is set but the bundled SDK does not honour it; tracking disabled.');
            Config::reset();
            return;
        }

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
