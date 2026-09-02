<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * The session bootstrap must survive a JS optimiser.
 *
 * Measured on a live site behind WP Rocket: the enqueued session script was
 * rewritten to `type="text/rocketlazyloadscript"` and never executed until the
 * first user interaction — 0 endpoint calls on load, 1 after a click. A visitor
 * whose first act IS the form submit therefore established no visitor at all,
 * and every event was rejected for having no one to attribute it to.
 *
 * "Delay JavaScript execution" is default-on in WP Rocket, and the siblings
 * (LiteSpeed, SG Optimizer, Autoptimize) all ship an equivalent. An enqueued
 * file is delayable by all of them; an inline script printed in the head is
 * the only form none of them can postpone.
 */
class SessionBootstrapTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $filters = [];

    /** @var array<int, string> */
    private array $headOutput = [];

    /** @var array<int, string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->filters    = [];
        $this->headOutput = [];
        $this->enqueued   = [];

        Functions\when('esc_url_raw')->alias(static fn ($v) => (string) $v);
        Functions\when('esc_attr')->alias(static fn ($v) => (string) $v);
        Functions\when('get_option')->justReturn([]);
        Functions\when('register_activation_hook')->justReturn(true);
        Functions\when('register_uninstall_hook')->justReturn(true);
        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('esc_js')->alias(static fn ($v) => (string) $v);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('rest_url')->alias(static fn ($p = '') => 'https://example.test/wp-json/' . ltrim((string) $p, '/'));
        Functions\when('wp_enqueue_script')->alias(function ($handle) {
            $this->enqueued[] = (string) $handle;
        });
        Functions\when('wp_localize_script')->justReturn(true);

        Functions\when('add_filter')->alias(function ($hook, $cb) {
            $this->filters[(string) $hook][] = $cb;
            return true;
        });
        Functions\when('add_action')->alias(function ($hook, $cb) {
            $this->filters[(string) $hook][] = $cb;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    /**
     * The bootstrap is printed inline, not enqueued as a file. A file is what
     * every optimiser knows how to defer.
     */
    public function testPrintsTheSessionBootstrapInline(): void
    {
        $html = $this->renderHead();

        $this->assertStringContainsString('<script', $html);
        // JSON-encoded into the script body, so slashes arrive escaped.
        $this->assertStringContainsString('wp-json', $html);
        $this->assertStringContainsString('mbuzz', $html);
        $this->assertStringContainsString('v1', $html);
        $this->assertStringContainsString('session', $html);
        $this->assertStringNotContainsString('assets/js/mbuzz-session.js', $html);
    }

    /**
     * The inline tag must carry the attributes optimisers honour to leave a
     * script alone. Rocket and LiteSpeed both check `data-cfasync="false"` and
     * their own no-delay/no-optimize markers.
     */
    public function testInlineTagCarriesOptimiserOptOutAttributes(): void
    {
        $html = $this->renderHead();

        $this->assertStringContainsString('data-cfasync="false"', $html);
        $this->assertStringContainsString('data-no-optimize="1"', $html);
        $this->assertStringContainsString('data-no-defer="1"', $html);
        $this->assertStringContainsString('data-no-minify="1"', $html);
    }

    /**
     * Attributes alone are advisory. The plugin must also register itself with
     * each optimiser's exclusion filters rather than trusting the host's config.
     */
    public function testRegistersWithEveryOptimiserExclusionFilter(): void
    {
        $this->freshRegister();

        foreach ([
            'rocket_delay_js_exclusions',
            'rocket_exclude_defer_js',
            'rocket_minify_excluded_external_js',
            'litespeed_optm_js_defer_exc',
            'sgo_javascript_combine_excluded_inline_content',
            'autoptimize_filter_js_exclude',
        ] as $hook) {
            $this->assertArrayHasKey($hook, $this->filters, "should register with {$hook}");
        }
    }

    /**
     * The exclusion callbacks must actually add our marker, and must preserve
     * whatever the site (or another plugin) already excluded.
     */
    public function testExclusionFiltersPreserveExistingEntries(): void
    {
        $this->freshRegister();

        $arrayResult = $this->applyFilter('rocket_delay_js_exclusions', ['existing/thing.js']);
        $this->assertContains('existing/thing.js', $arrayResult, 'must not clobber other exclusions');
        $this->assertGreaterThan(1, count($arrayResult), 'must add our own marker');

        $stringResult = $this->applyFilter('autoptimize_filter_js_exclude', 'existing.js');
        $this->assertStringContainsString('existing.js', $stringResult);
        $this->assertStringContainsString('mbuzz', $stringResult);
    }

    /**
     * No API key means nothing is tracked, so the bootstrap must not print —
     * same guard the enqueued helper already honours.
     */
    public function testPrintsNothingWithoutAnApiKey(): void
    {
        $this->assertSame('', $this->renderHead(sdkReady: false));
    }

    /**
     * Plugin is a singleton whose register() is idempotent-guarded, so by the
     * second test in a run it silently no-ops and wires nothing. Reset the
     * guard so each test observes a real registration pass.
     */
    private function freshRegister(): void
    {
        $plugin = Plugin::instance();

        $guard = new \ReflectionProperty($plugin, 'registered');
        $guard->setAccessible(true);
        $guard->setValue($plugin, false);

        $plugin->register();
    }

    private function renderHead(bool $sdkReady = true): string
    {
        $plugin = Plugin::instance();

        $ready = new \ReflectionProperty($plugin, 'sdkReady');
        $ready->setAccessible(true);
        $ready->setValue($plugin, $sdkReady);

        ob_start();
        $plugin->printSessionBootstrap();

        return (string) ob_get_clean();
    }

    /**
     * @param  array<int, string>|string $value
     * @return array<int, string>|string
     */
    private function applyFilter(string $hook, $value)
    {
        foreach ($this->filters[$hook] ?? [] as $cb) {
            $value = $cb($value);
        }

        return $value;
    }
}
