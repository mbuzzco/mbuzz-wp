<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\CookieManager;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Rest\SessionController;
use PHPUnit\Framework\TestCase;

/**
 * Session bootstrap for pages served from a full-page cache.
 *
 * A cached page is returned without running PHP, so nothing mints the visitor
 * cookie and every later hit is dropped for having no visitor. REST routes are
 * never full-page cached, so this endpoint runs, mints the cookie with a real
 * Set-Cookie, and records the session. Same gates as LeadController.
 */
class SessionControllerTest extends TestCase
{
    /** @var array<int, array{url:string, payload:?array}> */
    private array $captured = [];

    /** @var array<int, array{name:string,value:string}> */
    private array $cookiesSet = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->captured   = [];
        $this->cookiesSet = [];
        unset($_COOKIE[CookieManager::VISITOR_COOKIE]);

        Functions\when('sanitize_text_field')->alias(static fn ($v) => trim((string) $v));
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('apply_filters')->alias(static fn ($_n, $value) => $value);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('wp_parse_url')->alias(static fn ($u, $c = -1) => parse_url((string) $u, $c));

        $_SERVER['HTTP_USER_AGENT'] = 'test-agent';
        $_SERVER['REMOTE_ADDR']     = '203.0.113.10';

        $this->bootSdk();
    }

    protected function tearDown(): void
    {
        SessionController::setConsentProviderForTests(null);
        SessionController::setReadyProviderForTests(null);
        SessionController::setCookieManagerForTests(null);
        SessionController::setReinitialiserForTests(null);
        Mbuzz::reset();
        Monkey\tearDown();
        unset($_COOKIE[CookieManager::VISITOR_COOKIE]);
    }

    public function testMintsAVisitorCookieWhenThePageCouldNotSetOne(): void
    {
        $this->arrange();

        $res = SessionController::handle($this->request(['url' => 'https://example.com/centres/beresfield/']));

        $this->assertSame(204, $res->get_status());
        $this->assertNotSame([], $this->cookiesSet, 'No visitor cookie was minted.');
        $this->assertSame(CookieManager::VISITOR_COOKIE, $this->cookiesSet[0]['name']);
    }

    public function testCreatesTheSessionSoLaterHitsHaveAVisitor(): void
    {
        $this->arrange();

        SessionController::handle($this->request(['url' => 'https://example.com/centres/beresfield/']));

        $this->assertNotSame([], $this->sessionCalls(), 'No session was recorded.');
    }

    public function testReusesAnExistingVisitorRatherThanChurningANewId(): void
    {
        $existing = str_repeat('a', 64);
        $_COOKIE[CookieManager::VISITOR_COOKIE] = $existing;
        $this->arrange();

        SessionController::handle($this->request(['url' => 'https://example.com/']));

        $this->assertSame([], $this->cookiesSet, 'Minted a second id for a visitor that already had one.');
    }

    public function testWithheldConsentRecordsNothing(): void
    {
        $this->arrange(consent: false);

        $res = SessionController::handle($this->request(['url' => 'https://example.com/']));

        $this->assertSame(204, $res->get_status()); // never reflect why
        $this->assertSame([], $this->cookiesSet);
        $this->assertSame([], $this->captured);
    }

    public function testCrossOriginIsRejected(): void
    {
        $this->arrange();

        $res = SessionController::handle(
            $this->request(['url' => 'https://example.com/'], 'https://evil.example')
        );

        $this->assertSame(403, $res->get_status());
        $this->assertSame([], $this->captured);
    }

    public function testAnUnconfiguredSiteRecordsNothing(): void
    {
        $this->arrange(ready: false);

        $res = SessionController::handle($this->request(['url' => 'https://example.com/']));

        $this->assertSame(204, $res->get_status());
        $this->assertSame([], $this->captured);
    }

    // --- helpers ---

    /**
     * The SDK caches request context the first time it is read, so a test that
     * mints a cookie after booting would never see it — exactly the ordering
     * the endpoint gets right in production (mint, then read).
     */
    private function bootSdk(): void
    {
        Mbuzz::reset();
        Mbuzz::init(['api_key' => 'sk_test_session']);
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload) {
            $this->captured[] = ['url' => $url, 'payload' => $payload !== null ? json_decode($payload, true) : null];
            return ['status' => 202, 'body' => ['session' => ['id' => 'sess_1']]];
        });
    }

    private function arrange(bool $consent = true, bool $ready = true): void
    {
        SessionController::setConsentProviderForTests(static fn () => $consent);
        SessionController::setReadyProviderForTests(static fn () => $ready);
        $this->bootSdk();
        // Production re-inits the SDK so it sees the freshly minted cookie.
        SessionController::setReinitialiserForTests(fn () => $this->bootSdk());
        SessionController::setCookieManagerForTests(new CookieManager(
            [],
            function (string $name, string $value, array $options): bool {
                $this->cookiesSet[] = ['name' => $name, 'value' => $value];
                return true; // CookieBootstrap then populates $_COOKIE itself
            }
        ));
    }

    /** @return array<int, array{url:string,payload:?array}> */
    private function sessionCalls(): array
    {
        return array_values(array_filter(
            $this->captured,
            static fn ($c) => str_contains($c['url'], 'sessions')
        ));
    }

    /** @param array<string,mixed> $body */
    private function request(array $body, string $origin = 'https://example.com'): object
    {
        return new class ($body, $origin) {
            /** @param array<string,mixed> $body */
            public function __construct(private array $body, private string $origin)
            {
            }
            /** @return array<string,mixed> */
            public function get_json_params(): array
            {
                return $this->body;
            }
            public function get_header(string $name): ?string
            {
                return strtolower($name) === 'origin' ? $this->origin : null;
            }
        };
    }
}
