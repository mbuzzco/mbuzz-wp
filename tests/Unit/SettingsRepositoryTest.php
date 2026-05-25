<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Settings\Repository;
use PHPUnit\Framework\TestCase;

class SettingsRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function testCurrentReturnsDefaultsWhenOptionMissing(): void
    {
        Functions\when('get_option')->justReturn([]);

        $current = Repository::current();

        $this->assertSame('', $current['api_key']);
        $this->assertTrue($current['enabled']);
        $this->assertFalse($current['debug']);
        $this->assertSame('login', $current['identify_at']);
    }

    public function testCurrentMergesStoredOverDefaults(): void
    {
        Functions\when('get_option')->justReturn([
            'api_key' => 'sk_live_test',
            'debug'   => true,
        ]);

        $current = Repository::current();

        $this->assertSame('sk_live_test', $current['api_key']);
        $this->assertTrue($current['debug']);
        // Unset keys still come from defaults.
        $this->assertTrue($current['enabled']);
    }

    public function testCurrentHandlesNonArrayStoredValueGracefully(): void
    {
        // wp_options can return a string if something corrupted the row.
        Functions\when('get_option')->justReturn('corrupted');

        $current = Repository::current();

        $this->assertIsArray($current);
        $this->assertSame('', $current['api_key']);
    }

    public function testDefaultsShape(): void
    {
        $defaults = Repository::defaults();

        $this->assertArrayHasKey('api_key', $defaults);
        $this->assertArrayHasKey('enabled', $defaults);
        $this->assertArrayHasKey('skip_paths', $defaults);
        $this->assertIsArray($defaults['skip_paths']);
    }

    public function testIsLockedReturnsFalseForUnknownKey(): void
    {
        $this->assertFalse(Repository::isLocked('nonsense_key'));
    }
}
