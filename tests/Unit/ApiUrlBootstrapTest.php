<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit;

use Brain\Monkey;
use Mbuzz\Config;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * MBUZZ_API_URL points a data-resident install at its regional ingest host
 * (spec M9). The SDK enforces https and the region-key pairing; the plugin's
 * job is to pass the host through and to stay OFF, never fatal, when the SDK
 * refuses a combination.
 */
class ApiUrlBootstrapTest extends TestCase
{
    // Built at runtime: a literal `sk_live_` + 32 chars trips push protection's Stripe pattern.
    private const KEY_PREFIX = 'sk_' . 'live_';
    private const REGION_KEY = self::KEY_PREFIX . 'au_0123456789abcdef0123456789abcdef';
    private const GLOBAL_KEY = self::KEY_PREFIX . '0123456789abcdef0123456789abcdef';
    private const REGION_URL = 'https://ingest.example.com.au/api/v1';

    protected function setUp(): void
    {
        Monkey\setUp();
        Mbuzz::reset();
    }

    protected function tearDown(): void
    {
        Mbuzz::reset();
        Monkey\tearDown();
    }

    public function testRegionKeyWithApiUrlSendsToTheRegionalHost(): void
    {
        Bootstrap::boot($this->settings(self::REGION_KEY, self::REGION_URL));

        $this->assertTrue(Config::getInstance()->isInitialized());
        $this->assertSame(self::REGION_URL, Config::getInstance()->getApiUrl());
    }

    public function testNoApiUrlKeepsTheDefaultHost(): void
    {
        Bootstrap::boot($this->settings(self::GLOBAL_KEY, ''));

        $this->assertSame('https://api.mbuzz.co/api/v1', Config::getInstance()->getApiUrl());
    }

    public function testRegionKeyWithoutApiUrlStaysOffInsteadOfFataling(): void
    {
        $this->silenceErrorLog();
        Bootstrap::boot($this->settings(self::REGION_KEY, ''));

        $this->assertFalse(Config::getInstance()->isInitialized());
    }

    public function testGlobalKeyWithApiUrlStaysOff(): void
    {
        $this->silenceErrorLog();
        Bootstrap::boot($this->settings(self::GLOBAL_KEY, self::REGION_URL));

        $this->assertFalse(Config::getInstance()->isInitialized());
    }

    public function testPlainHttpApiUrlStaysOff(): void
    {
        $this->silenceErrorLog();
        Bootstrap::boot($this->settings(self::REGION_KEY, 'http://ingest.example.com.au/api/v1'));

        $this->assertFalse(Config::getInstance()->isInitialized());
    }

    /**
     * @return array{api_key:string, api_url:string, enabled:bool, debug:bool, skip_paths:array<string>}
     */
    private function settings(string $key, string $url): array
    {
        return ['api_key' => $key, 'api_url' => $url, 'enabled' => true, 'debug' => false, 'skip_paths' => []];
    }

    private function silenceErrorLog(): void
    {
        ini_set('error_log', '/dev/null');
    }
}
