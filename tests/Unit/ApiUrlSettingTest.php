<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Settings\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The ingest host comes from the MBUZZ_API_URL constant only. A stored option
 * must never set it, so nothing in wp-admin can redirect where data is sent.
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

    public function testStoredOptionCannotSetTheApiUrl(): void
    {
        Functions\when('get_option')->justReturn(['api_url' => 'https://attacker.example.com/api/v1']);

        $this->assertSame('', Repository::current()['api_url']);
        $this->assertFalse(Repository::isLocked('api_url'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConstantSetsAndLocksTheApiUrl(): void
    {
        define('MBUZZ_API_URL', 'https://ingest.example.com.au/api/v1');
        Functions\when('get_option')->justReturn([]);

        $this->assertSame('https://ingest.example.com.au/api/v1', Repository::current()['api_url']);
        $this->assertTrue(Repository::isLocked('api_url'));
    }
}
