<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Integrations\ContactForm7;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * CF7 runtime adapter. Gates on submission status, builds a Cf7FormSource
 * (posted data + page from the container post), and delegates to the engine,
 * which is map-driven and opt-in. SDK driven via the transport-capture seam.
 */
class ContactForm7Test extends TestCase
{
    /** @var array<int, array{url:string, payload:?array}> */
    private array $captured = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->captured = [];
        $_COOKIE['_mbuzz_vid'] = str_repeat('a', 64);

        Functions\when('add_action')->justReturn(true);
        Functions\when('apply_filters')->alias(static fn ($_name, $value) => $value);
        Functions\when('get_post_meta')->justReturn(''); // no map by default
        Functions\when('get_the_title')->justReturn('Downtown Office');
        Functions\when('get_permalink')->justReturn('https://example.com/locations/downtown/');

        Mbuzz::init(['api_key' => 'sk_test_cf7']);
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload) {
            $this->captured[] = ['url' => $url, 'payload' => $payload !== null ? json_decode($payload, true) : null];
            return ['status' => 200, 'body' => ['conversion' => ['id' => 'conv_1']]];
        });
    }

    protected function tearDown(): void
    {
        ContactForm7::setPostedDataProviderForTests(null);
        ContactForm7::setPageProviderForTests(null);
        Mbuzz::reset();
        Mockery::close();
        Monkey\tearDown();
        unset($_COOKIE['_mbuzz_vid']);
    }

    private function form(int $id = 7, string $title = 'Enquiry'): object
    {
        $form = Mockery::mock();
        $form->shouldReceive('id')->andReturn($id);
        $form->shouldReceive('title')->andReturn($title);
        return $form;
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function withPosted(array $posted): void
    {
        ContactForm7::setPostedDataProviderForTests(static fn () => $posted);
    }

    private function withMap(): void
    {
        Functions\when('get_post_meta')->justReturn([
            FieldMap::K_ENABLED         => true,
            FieldMap::K_TRACK_AS        => TrackAs::CONVERSION,
            FieldMap::K_TYPE            => 'enquiry',
            FieldMap::K_CAPTURE_PAGE_AS => 'location',
            FieldMap::K_FIELDS          => [
                'CustomerRef' => [FieldMap::K_ROLE => Roles::USER_ID],
                'CustomerEmail' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'email'],
            ],
        ]);
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

    public function testNonSuccessStatusFiresNothing(): void
    {
        $this->withMap();
        $this->withPosted(['CustomerRef' => 'CUST-9', 'CustomerEmail' => 'jo@example.com']);

        ContactForm7::onSubmit($this->form(), [ContactForm7::RESULT_STATUS => 'validation_failed']);
        ContactForm7::onSubmit($this->form(), []); // no status

        $this->assertCount(0, $this->captured);
    }

    public function testUnmappedFormFiresNothing(): void
    {
        // get_post_meta returns '' (no map) → opt-in → nothing, even on success.
        $this->withPosted(['CustomerEmail' => 'jo@example.com']);

        ContactForm7::onSubmit($this->form(), [ContactForm7::RESULT_STATUS => ContactForm7::STATUS_MAIL_SENT]);

        $this->assertCount(0, $this->captured);
    }

    public function testConfiguredFormConvertsWithPageCapture(): void
    {
        $this->withMap();
        $this->withPosted(['CustomerRef' => 'CUST-9', 'CustomerEmail' => 'jo@example.com']);
        // The page comes from the CF7 submission meta, not the posted data.
        ContactForm7::setPageProviderForTests(static fn () => [FieldMap::PAGE_TITLE => 'Downtown Office']);

        ContactForm7::onSubmit($this->form(), [ContactForm7::RESULT_STATUS => ContactForm7::STATUS_MAIL_SENT]);

        $conversion = $this->payloadFor('/conversions')['conversion'];
        $this->assertSame('enquiry', $conversion['conversion_type']);
        $this->assertSame('CUST-9', $conversion['user_id']);
        $this->assertSame('Downtown Office', $conversion['properties']['location']);
        $this->assertSame('jo@example.com', $this->payloadFor('/identify')['traits']['email']);
    }

    public function testDemoModeAlsoSucceeds(): void
    {
        $this->withMap();
        $this->withPosted(['CustomerRef' => 'CUST-9']);

        ContactForm7::onSubmit($this->form(), [ContactForm7::RESULT_STATUS => ContactForm7::STATUS_DEMO_MODE]);

        $this->assertNotNull($this->payloadFor('/conversions'));
    }
}
