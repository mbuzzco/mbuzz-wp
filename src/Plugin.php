<?php
/**
 * Singleton plugin entrypoint. Owns hook registration; delegates the actual
 * SDK boot to Bootstrap so the same boot logic can be re-run on switch_blog.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP;

use Mbuzz\Mbuzz;
use Mbuzz\WP\Settings\Page as SettingsPage;
use Mbuzz\WP\Settings\Repository as SettingsRepository;

final class Plugin
{
    private static ?self $instance = null;

    private bool $registered = false;
    private bool $sdkReady = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        register_activation_hook(MBUZZ_ATTRIBUTION_FILE, [$this, 'onActivate']);
        register_uninstall_hook(MBUZZ_ATTRIBUTION_FILE, [self::class, 'onUninstall']);

        // Boot SDK early so other plugins (e.g. WooCommerce hooks) can rely on it.
        add_action('plugins_loaded', [$this, 'bootSdk'], 5);

        // Front-end request init — last hook before output, real navigations only.
        add_action('template_redirect', [$this, 'initFromRequest'], 1);

        // Multisite: re-init SDK with the destination site's settings.
        add_action('switch_blog', [$this, 'onSwitchBlog'], 10, 2);

        // Admin: settings page + sanitization.
        add_action('admin_init', [SettingsPage::class, 'registerSettings']);
        add_action('admin_menu', [SettingsPage::class, 'registerMenu']);
    }

    public function sdkReady(): bool
    {
        return $this->sdkReady;
    }

    /**
     * Boot the SDK from current settings. Idempotent.
     */
    public function bootSdk(): void
    {
        $settings = SettingsRepository::current();
        Bootstrap::boot($settings);
        $this->sdkReady = $settings['api_key'] !== '';
    }

    public function initFromRequest(): void
    {
        if (! $this->sdkReady) {
            return;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_robots()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return;
        }

        Mbuzz::initFromRequest();
    }

    /**
     * On multisite switch_blog, reset and re-init the SDK with the
     * destination site's settings. reset() alone leaves Mbuzz::ensureInitialized()
     * throwing — the re-init is mandatory.
     */
    public function onSwitchBlog(int $newBlogId, int $prevBlogId): void
    {
        if ($newBlogId === $prevBlogId) {
            return;
        }
        Mbuzz::reset();
        Bootstrap::boot(SettingsRepository::current());
    }

    public function onActivate(): void
    {
        SettingsRepository::ensureDefaults();
    }

    public static function onUninstall(): void
    {
        delete_option(SettingsRepository::OPTION_KEY);
        delete_transient('mbuzz_attribution_last_call');
        delete_transient('mbuzz_attribution_php_notice');
    }
}
