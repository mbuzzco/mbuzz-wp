<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Settings\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The regional ingest host comes from the settings field, or from the
 * MBUZZ_API_URL constant, which wins and locks the field. The SDK still
 * enforces https and the region-key pairing whichever way it arrives.
 */
class ApiUrlSettingTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function testStoredSettingSuppliesTheApiUrl(): void
    {
        Functions\when('get_option')->justReturn(['api_url' => 'https://ingest.example.com.au/api/v1']);

        $this->assertSame('https://ingest.example.com.au/api/v1', Repository::current()['api_url']);
        $this->assertFalse(Repository::isLocked('api_url'));
    }

    public function testApiUrlDefaultsToBlank(): void
    {
        Functions\when('get_option')->justReturn([]);

        $this->assertSame('', Repository::current()['api_url']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConstantOverridesTheStoredSettingAndLocksIt(): void
    {
        define('MBUZZ_API_URL', 'https://ingest.example.com.au/api/v1');
        Functions\when('get_option')->justReturn(['api_url' => 'https://other.example.com/api/v1']);

        $this->assertSame('https://ingest.example.com.au/api/v1', Repository::current()['api_url']);
        $this->assertTrue(Repository::isLocked('api_url'));
    }
}
