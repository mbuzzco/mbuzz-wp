<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Tracking;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Integrations\FormSource;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\Source;
use Mbuzz\WP\Tracking\TrackAs;
use Mbuzz\WP\Tracking\TrackingEngine;
use PHPUnit\Framework\TestCase;

/**
 * The engine: map-driven, opt-in, plugin-agnostic. SDK driven through the
 * transport-capture seam; the form's map injected via get_post_meta.
 */
class TrackingEngineTest extends TestCase
{
    /** @var array<int, array{method:string, url:string, payload:?array}> */
    private array $captured = [];

    private ?string $recordedOutcome = null;

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->captured = [];
        $_COOKIE['_mbuzz_vid'] = str_repeat('a', 64);

        Functions\when('add_action')->justReturn(true);
        Functions\when('apply_filters')->alias(static fn ($_name, $value) => $value);
        Functions\when('get_post_meta')->justReturn(''); // default: no saved map

        $this->recordedOutcome = null;
        Functions\when('set_transient')->alias(function ($key, $value) {
            if ($key === TrackingEngine::TRANSIENT_LAST_SUBMISSION && is_array($value)) {
                $this->recordedOutcome = $value['outcome'] ?? null;
            }
            return true;
        });

        Mbuzz::init(['api_key' => 'sk_test_engine']);
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload) {
            $this->captured[] = [
                'method'  => $method,
                'url'     => $url,
                'payload' => $payload !== null ? json_decode($payload, true) : null,
            ];
            return ['status' => 200, 'body' => ['conversion' => ['id' => 'conv_1']]];
        });
    }

    protected function tearDown(): void
    {
        Mbuzz::reset();
        Monkey\tearDown();
        unset($_COOKIE['_mbuzz_vid']);
    }

    /**
     * @param array<string, mixed> $map
     */
    private function withMap(array $map): void
    {
        Functions\when('get_post_meta')->justReturn($map);
    }

    /**
     * @param array<string, array<string, string>> $fields
     * @return array<string, mixed>
     */
    private function conversionMap(array $fields, ?string $capturePageAs = null, string $trackAs = TrackAs::CONVERSION): array
    {
        return [
            FieldMap::K_ENABLED         => true,
            FieldMap::K_TRACK_AS        => $trackAs,
            FieldMap::K_TYPE            => 'enquiry',
            FieldMap::K_CAPTURE_PAGE_AS => $capturePageAs,
            FieldMap::K_FIELDS          => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $page
     */
    private function source(array $posted, array $page = []): FormSource
    {
        return new class ($posted, $page) implements FormSource {
            /** @param array<string, mixed> $p @param array<string, mixed> $pg */
            public function __construct(private array $p, private array $pg)
            {
            }
            public function source(): string
            {
                return Source::CF7;
            }
            public function formId()
            {
                return 7;
            }
            public function formTitle(): string
            {
                return 'Enquiry';
            }
            public function postedData(): array
            {
                return $this->p;
            }
            public function page(): array
            {
                return $this->pg;
            }
        };
    }

    private function payloadFor(string $suffix): ?array
    {
        foreach ($this->captured as $c) {
            if (str_ends_with((string) $c['url'], $suffix)) {
                return $c['payload'];
            }
        }
        return null;
    }

    public function testUnmappedFormFiresNothing(): void
    {
        // get_post_meta returns '' (default) → no map → opt-in: nothing.
        TrackingEngine::handle($this->source(['CustomerEmail' => 'jo@example.com']));

        $this->assertCount(0, $this->captured);
    }

    public function testDisabledMapFiresNothing(): void
    {
        $this->withMap($this->conversionMap([], null, TrackAs::OFF));

        TrackingEngine::handle($this->source(['CustomerEmail' => 'jo@example.com']));

        $this->assertCount(0, $this->captured);
    }

    public function testConversionFiresIdentifyThenConversion(): void
    {
        $this->withMap($this->conversionMap([
            'CustomerRef'      => [FieldMap::K_ROLE => Roles::USER_ID],
            'CustomerEmail'      => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'email'],
            'TeamSize' => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'team_size'],
        ], 'location'));

        TrackingEngine::handle($this->source([
            'CustomerRef'      => 'CUST-9',
            'CustomerEmail'      => 'jo@example.com',
            'TeamSize' => '2',
        ], [
            FieldMap::PAGE_TITLE => 'Downtown Office',
        ]));

        $identify = $this->payloadFor('/identify');
        $this->assertSame('CUST-9', $identify['user_id']);
        $this->assertSame('jo@example.com', $identify['traits']['email']);

        $conversion = $this->payloadFor('/conversions')['conversion'];
        $this->assertSame('enquiry', $conversion['conversion_type']);
        $this->assertSame('CUST-9', $conversion['user_id']);
        $this->assertSame('2', $conversion['properties']['team_size']);
        $this->assertSame('Downtown Office', $conversion['properties']['location']);
    }

    public function testEventPathFiresEventNotConversion(): void
    {
        $this->withMap($this->conversionMap([
            'CustomerRef' => [FieldMap::K_ROLE => Roles::USER_ID],
        ], null, TrackAs::EVENT));

        TrackingEngine::handle($this->source(['CustomerRef' => 'CUST-9']));

        $this->assertNotNull($this->payloadFor('/events'));
        $this->assertNull($this->payloadFor('/conversions'));
    }

    public function testUserIdAloneStillIdentifies(): void
    {
        // A join key with no traits must still identify — identifying links the
        // user_id to the visitor/session; traits are optional enrichment.
        $this->withMap($this->conversionMap([
            'CustomerRef' => [FieldMap::K_ROLE => Roles::USER_ID],
        ]));

        TrackingEngine::handle($this->source(['CustomerRef' => 'CUST-9']));

        $identify = $this->payloadFor('/identify');
        $this->assertSame('CUST-9', $identify['user_id'], 'user_id alone → identify');
        $this->assertSame([], $identify['traits'] ?? [], 'no traits sent, but identity stitched');
        $this->assertNotNull($this->payloadFor('/conversions'));
    }

    public function testNoUserIdSkipsIdentifyButConverts(): void
    {
        $this->withMap($this->conversionMap([
            'Postcode' => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'postcode'],
        ]));

        TrackingEngine::handle($this->source(['Postcode' => '2284']));

        $this->assertNull($this->payloadFor('/identify'), 'no user_id → no identify');
        $conversion = $this->payloadFor('/conversions')['conversion'];
        $this->assertArrayNotHasKey('user_id', $conversion);
        $this->assertSame('2284', $conversion['properties']['postcode']);
    }

    public function testSkipFilterShortCircuits(): void
    {
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            return $name === TrackingEngine::FILTER_SKIP ? true : $value;
        });
        $this->withMap($this->conversionMap([
            'CustomerRef' => [FieldMap::K_ROLE => Roles::USER_ID],
        ]));

        TrackingEngine::handle($this->source(['CustomerRef' => 'CUST-9']));

        $this->assertCount(0, $this->captured);
    }

    public function testNoSdkClientShortCircuits(): void
    {
        Mbuzz::reset(); // getClient() === null
        $this->withMap($this->conversionMap(['CustomerRef' => [FieldMap::K_ROLE => Roles::USER_ID]]));

        TrackingEngine::handle($this->source(['CustomerRef' => 'CUST-9']));

        $this->assertCount(0, $this->captured); // guarded, no RuntimeException
    }
    // --- Why a submission did not track (diagnostics) ---

    public function testRecordsThatTheFormWasNotConfigured(): void
    {
        TrackingEngine::handle($this->source([]));   // default: no saved map

        $this->assertSame(TrackingEngine::OUTCOME_NOT_CONFIGURED, $this->recordedOutcome);
    }

    public function testRecordsThatTheHitWasSent(): void
    {
        $this->withMap($this->conversionMap(['Email' => [FieldMap::K_ROLE => Roles::USER_ID]]));

        TrackingEngine::handle($this->source(['Email' => 'jo@example.com']));

        $this->assertSame(TrackingEngine::OUTCOME_SENT, $this->recordedOutcome);
    }

    public function testRecordsThatAFilterSkippedTheSubmission(): void
    {
        $this->withMap($this->conversionMap(['Email' => [FieldMap::K_ROLE => Roles::USER_ID]]));
        Functions\when('apply_filters')->alias(
            static fn ($name, $value) => $name === TrackingEngine::FILTER_SKIP ? true : $value
        );

        TrackingEngine::handle($this->source(['Email' => 'jo@example.com']));

        $this->assertSame(TrackingEngine::OUTCOME_SKIPPED_BY_FILTER, $this->recordedOutcome);
    }

}
