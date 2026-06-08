<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Rest\LeadController;
use PHPUnit\Framework\TestCase;

/**
 * First-party capture endpoint for embedded/external forms. Reads _mbuzz_vid
 * server-side, gates on consent + same-origin, then fires identify + event via
 * the SDK. Asserted through the transport-capture seam, like ContactForm7Test.
 */
class LeadControllerTest extends TestCase
{
    /** @var array<int, array{url:string, payload:?array}> */
    private array $captured = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->captured = [];
        $_COOKIE['_mbuzz_vid'] = str_repeat('a', 64);

        Functions\when('sanitize_key')->alias(static fn ($v) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)));
        Functions\when('sanitize_text_field')->alias(static fn ($v) => trim(preg_replace('/\s+/', ' ', (string) $v)));
        Functions\when('wp_unslash')->returnArg();
        Functions\when('apply_filters')->alias(static fn ($_n, $value) => $value);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('wp_parse_url')->alias(static fn ($u, $c = -1) => parse_url((string) $u, $c));

        Mbuzz::init(['api_key' => 'sk_test_lead']);
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload) {
            $this->captured[] = ['url' => $url, 'payload' => $payload !== null ? json_decode($payload, true) : null];
            return ['status' => 200, 'body' => ['events' => [['id' => 'evt_1']]]];
        });
    }

    protected function tearDown(): void
    {
        LeadController::setConsentProviderForTests(null);
        LeadController::setReadyProviderForTests(null);
        Mbuzz::reset();
        Monkey\tearDown();
        unset($_COOKIE['_mbuzz_vid']);
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

    private function consent(bool $granted): void
    {
        LeadController::setConsentProviderForTests(static fn () => $granted);
    }

    private function ready(bool $ready): void
    {
        LeadController::setReadyProviderForTests(static fn () => $ready);
    }

    /** @return array<int, array{url:string,payload:?array}> */
    private function urlsFor(string $needle): array
    {
        return array_values(array_filter($this->captured, static fn ($c) => str_contains($c['url'], $needle)));
    }

    public function testHappyPathFiresIdentifyThenEvent(): void
    {
        $this->consent(true);
        $this->ready(true);

        $res = LeadController::handle($this->request([
            'type'    => 'tour_booking',
            'user_id' => 'parent@example.com',
            'traits'  => ['phone' => '0400', 'first_name' => 'Jo'],
            'properties' => ['location' => 'Calala'],
        ]));

        $this->assertSame(204, $res->get_status());

        $identify = $this->urlsFor('/identify');
        $this->assertCount(1, $identify);
        $this->assertSame('parent@example.com', $identify[0]['payload']['user_id']);
        $this->assertSame('0400', $identify[0]['payload']['traits']['phone']);

        $events = $this->urlsFor('/events');
        $this->assertCount(1, $events);
        $event = $events[0]['payload']['events'][0];
        $this->assertSame('tour_booking', $event['event_type']);
        $this->assertSame('Calala', $event['properties']['location']);
        // The event carries user_id (from context) so backend create-on-demand works.
        $this->assertSame('parent@example.com', $event['user_id']);
    }

    public function testEventOnlyWhenNoUserId(): void
    {
        $this->consent(true);
        $this->ready(true);

        LeadController::handle($this->request([
            'type' => 'lead',
            'properties' => ['location' => 'Calala'],
        ]));

        $this->assertCount(0, $this->urlsFor('/identify'), 'no identify without a user_id');
        $this->assertCount(1, $this->urlsFor('/events'));
    }

    public function testConsentOffFiresNothing(): void
    {
        $this->consent(false);
        $this->ready(true);

        $res = LeadController::handle($this->request(['type' => 'lead', 'user_id' => 'a@b.com']));

        $this->assertSame(204, $res->get_status());
        $this->assertCount(0, $this->captured, 'consent gate → nothing sent');
    }

    public function testNotReadyFiresNothing(): void
    {
        $this->consent(true);
        $this->ready(false);

        LeadController::handle($this->request(['type' => 'lead', 'user_id' => 'a@b.com']));

        $this->assertCount(0, $this->captured);
    }

    public function testRejectsCrossOrigin(): void
    {
        $this->consent(true);
        $this->ready(true);

        $res = LeadController::handle($this->request(['type' => 'lead', 'user_id' => 'a@b.com'], 'https://evil.test'));

        $this->assertSame(403, $res->get_status());
        $this->assertCount(0, $this->captured, 'cross-origin → nothing sent');
    }
}
