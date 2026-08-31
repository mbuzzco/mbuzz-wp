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
use Mbuzz\WP\Identity\Hooks as IdentityHooks;
use Mbuzz\WP\Integrations\ContactForm7;
use Mbuzz\WP\Integrations\WooCommerce;
use Mbuzz\WP\Privacy\Consent;
use Mbuzz\WP\Privacy\PersonalData;
use Mbuzz\WP\Rest\LeadController;
use Mbuzz\WP\Rest\SessionController;
use Mbuzz\WP\Settings\Cf7EditorPanel;
use Mbuzz\WP\Settings\ConversionsPage;
use Mbuzz\WP\Settings\Page as SettingsPage;
use Mbuzz\WP\Settings\Repository as SettingsRepository;
use Mbuzz\WP\Tracking\TrackingEngine;
use Mbuzz\WP\Visitor\CookieBootstrap;

final class Plugin
{
    private static ?self $instance = null;

    private bool $registered = false;

    // Why a front-end request was not tracked; surfaced in Settings → Diagnostics.
    public const SKIP_NO_API_KEY   = 'page_no_api_key';
    public const SKIP_NOT_FRONTEND = 'page_not_frontend';
    public const SKIP_REST         = 'page_rest_request';
    public const SKIP_XMLRPC       = 'page_xmlrpc_request';
    public const SKIP_ADMIN_USER   = 'page_logged_in_admin';
    public const SKIP_NO_CONSENT   = 'page_no_consent';
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

        // Admin: Mbuzz menu (Conversions overview + Settings) + sanitization.
        add_action('admin_init', [SettingsPage::class, 'registerSettings']);
        add_action('admin_menu', [ConversionsPage::class, 'registerMenu']);

        // Identity + integrations register their own hooks on plugins_loaded
        // — after the SDK is booted so they can safely call Mbuzz::*.
        add_action('plugins_loaded', [IdentityHooks::class, 'register'], 10);
        add_action('plugins_loaded', [WooCommerce::class, 'register'], 10);
        add_action('plugins_loaded', [ContactForm7::class, 'register'], 10);

        // CF7 per-form config panel (admin editor).
        add_action('plugins_loaded', [Cf7EditorPanel::class, 'register'], 10);

        // Privacy: data exporter + eraser (we ship PII to a third party).
        add_action('plugins_loaded', [PersonalData::class, 'register'], 10);

        // Embedded / external form capture: first-party REST endpoint + JS helper.
        LeadController::register();
        SessionController::register();
        add_action('wp_enqueue_scripts', [$this, 'enqueueCaptureHelper']);
    }

    /**
     * Enqueue the front-end capture helper (window.mbuzz.captureLead). Skipped
     * for excluded admins so the helper isn't loaded where tracking is off.
     */
    public function enqueueCaptureHelper(): void
    {
        if (! $this->sdkReady() || $this->shouldSkipForAdmin()) {
            return;
        }

        wp_enqueue_script(
            'mbuzz-capture',
            MBUZZ_ATTRIBUTION_URL . 'assets/js/mbuzz-capture.js',
            [],
            MBUZZ_ATTRIBUTION_VERSION,
            true
        );
        wp_localize_script('mbuzz-capture', 'mbuzzCapture', [
            'endpoint' => esc_url_raw(rest_url(LeadController::NAMESPACE . LeadController::ROUTE)),
        ]);

        // Session bootstrap. A cached page never ran PHP, so this is the only
        // thing that can establish the visitor — and it does so server-side.
        wp_enqueue_script(
            'mbuzz-session',
            MBUZZ_ATTRIBUTION_URL . 'assets/js/mbuzz-session.js',
            [],
            MBUZZ_ATTRIBUTION_VERSION,
            true
        );
        wp_localize_script('mbuzz-session', 'mbuzzSession', [
            'endpoint' => esc_url_raw(rest_url(SessionController::NAMESPACE . SessionController::ROUTE)),
        ]);
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
        $skip = $this->reasonToSkipRequest();
        if ($skip !== null) {
            TrackingEngine::notePageView($skip);
            return;
        }

        // Server-side-only deployments have no JS pixel to mint the visitor
        // cookie, and the SDK refuses to mint it itself. Do it here so
        // initFromRequest() has a visitor to create a session for.
        // A page response may be cached and replayed to everyone, so it never
        // mints. The uncached session endpoint does that; here we only use a
        // cookie the browser already holds.
        CookieBootstrap::ensureVisitorCookie(null, null, CookieBootstrap::CONTEXT_PAGE);

        Mbuzz::initFromRequest();
    }

    /**
     * Why this request will not be tracked, or null to proceed. Named reasons
     * rather than bare early returns: a page that mints no visitor cookie
     * silently drops every form submission on it, and 'nothing happened' is
     * the hardest possible thing to diagnose from outside the server.
     *
     * @return string|null
     */
    private function reasonToSkipRequest(): ?string
    {
        $reasons = [
            self::SKIP_NO_API_KEY  => fn (): bool => ! $this->sdkReady,
            self::SKIP_NOT_FRONTEND => static fn (): bool => is_admin() || wp_doing_ajax() || wp_doing_cron() || is_robots(),
            self::SKIP_REST        => static fn (): bool => defined('REST_REQUEST') && REST_REQUEST,
            self::SKIP_XMLRPC      => static fn (): bool => defined('XMLRPC_REQUEST') && XMLRPC_REQUEST,
            self::SKIP_ADMIN_USER  => fn (): bool => $this->shouldSkipForAdmin(),
            self::SKIP_NO_CONSENT  => static fn (): bool => ! Consent::granted(),
        ];

        foreach ($reasons as $reason => $applies) {
            if ($applies()) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Honor the "Track logged-in admins" setting (spec §4). Off by default so
     * internal QA navigation doesn't pollute attribution data.
     */
    private function shouldSkipForAdmin(): bool
    {
        if (SettingsRepository::current()['track_admins']) {
            return false;
        }

        return is_user_logged_in() && current_user_can('manage_options');
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
