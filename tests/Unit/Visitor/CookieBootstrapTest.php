<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Visitor;

use Mbuzz\CookieManager;
use Mbuzz\WP\Visitor\CookieBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Server-side visitor cookie minting. Drives the SDK's CookieManager through
 * its injectable set-cookie callback so no real header I/O happens in tests.
 */
class CookieBootstrapTest extends TestCase
{
    /** @var array<int, array{name:string, value:string, options:array}> */
    private array $set = [];

    protected function setUp(): void
    {
        $this->set = [];
        unset($_COOKIE[CookieManager::VISITOR_COOKIE]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[CookieManager::VISITOR_COOKIE]);
    }

    private function capturingCookies(bool $persists = true): CookieManager
    {
        return new CookieManager([], function (string $name, string $value, array $options) use ($persists): bool {
            $this->set[] = ['name' => $name, 'value' => $value, 'options' => $options];
            return $persists;
        });
    }

    public function testMintsCookieWhenAbsent(): void
    {
        CookieBootstrap::ensureVisitorCookie($this->capturingCookies());

        $this->assertCount(1, $this->set);
        $this->assertSame('_mbuzz_vid', $this->set[0]['name']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $this->set[0]['value']);
        // Persisted into $_COOKIE so the SDK sees the visitor this same request.
        $this->assertSame($this->set[0]['value'], $_COOKIE['_mbuzz_vid'] ?? null);
        // Attribute parity with the SDK's own cookie writes.
        $this->assertTrue($this->set[0]['options']['httponly']);
        $this->assertSame('Lax', $this->set[0]['options']['samesite']);
    }

    public function testNoOpWhenCookieAlreadyPresent(): void
    {
        $_COOKIE[CookieManager::VISITOR_COOKIE] = 'existing-vid';

        CookieBootstrap::ensureVisitorCookie($this->capturingCookies());

        $this->assertCount(0, $this->set, 'must not re-mint over an existing visitor');
        $this->assertSame('existing-vid', $_COOKIE['_mbuzz_vid']);
    }

    public function testDoesNotPopulateSuperglobalWhenCookieFailsToPersist(): void
    {
        // headers-already-sent → setcookie returns false. We must not then
        // claim a visitor whose cookie never reached the browser.
        CookieBootstrap::ensureVisitorCookie($this->capturingCookies(persists: false));

        $this->assertCount(1, $this->set);
        $this->assertArrayNotHasKey('_mbuzz_vid', $_COOKIE);
    }
}
