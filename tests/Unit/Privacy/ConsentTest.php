<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Privacy\Consent;
use PHPUnit\Framework\TestCase;

/**
 * The consent gate. With no Consent API present it must default to allowed
 * (the plugin doesn't invent a gate); with one present it must honor the
 * category, defaulting to the most protective "marketing" bucket.
 */
class ConsentTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        // The final-decision filter is a passthrough unless a test overrides it.
        Functions\when('apply_filters')->alias(static fn ($_name, $value) => $value);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    /**
     * Brain Monkey defines wp_has_consent process-wide the moment any other
     * test stubs it, so the "absent" branch needs a clean process to be real.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAllowedWhenNoConsentApiPresent(): void
    {
        // wp_has_consent is intentionally NOT defined → not gated by us.
        $this->assertFalse(function_exists('wp_has_consent'));
        $this->assertTrue(Consent::granted());
    }

    public function testGatesOnTheMarketingCategoryByDefault(): void
    {
        Functions\expect('wp_has_consent')->once()->with(Consent::DEFAULT_CATEGORY)->andReturn(true);
        $this->assertTrue(Consent::granted());
    }

    public function testDeniedWhenConsentWithheld(): void
    {
        Functions\when('wp_has_consent')->justReturn(false);
        $this->assertFalse(Consent::granted());
    }

    public function testCategoryIsFilterable(): void
    {
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            return $name === Consent::FILTER_CATEGORY ? 'statistics' : $value;
        });
        Functions\expect('wp_has_consent')->once()->with('statistics')->andReturn(true);

        $this->assertTrue(Consent::granted());
    }

    public function testFinalDecisionIsFilterable(): void
    {
        Functions\when('wp_has_consent')->justReturn(true);
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            return $name === Consent::FILTER_GRANTED ? false : $value;
        });

        $this->assertFalse(Consent::granted(), 'mbuzz_has_consent can force-deny');
    }
}
