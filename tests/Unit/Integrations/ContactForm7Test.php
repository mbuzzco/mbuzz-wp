<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Integrations\ContactForm7;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contact Form 7 lead conversion. WPCF7_ContactForm mocked via Mockery;
 * posted data injected through the test seam; SDK driven end-to-end through
 * the transport-capture seam.
 *
 * A successful lead with an email makes TWO API calls — /identify (so the
 * lead stitches to an Identity) then /conversions — so assertions locate
 * captures by URL rather than by index.
 */
class ContactForm7Test extends TestCase
{
    /** @var array<int, array{method:string, url:string, payload:?array, body:array}> */
    private array $captured = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->captured = [];

        // In production the cookie is present from the visitor's first page
        // load (minted by CookieBootstrap). The SDK rejects calls with neither
        // visitor_id nor user_id, so no-email tests need it too.
        $_COOKIE['_mbuzz_vid'] = str_repeat('a', 64);

        Functions\when('add_action')->justReturn(true);
        Functions\when('apply_filters')->alias(static fn ($_name, $value) => $value);

        Mbuzz::init(['api_key' => 'sk_test_cf7']);
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload) {
            $body = ['conversion' => ['id' => 'conv_1']];
            $this->captured[] = [
                'method'  => $method,
                'url'     => $url,
                'payload' => $payload !== null ? json_decode($payload, true) : null,
                'body'    => $body,
            ];
            return ['status' => 200, 'body' => $body];
        });
    }

    protected function tearDown(): void
    {
        ContactForm7::setPostedDataProviderForTests(null);
        Mbuzz::reset();
        Mockery::close();
        Monkey\tearDown();
        unset($_COOKIE['_mbuzz_vid']);
    }

    private function form(int $id = 6, string $title = 'Contact'): object
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

    private function payloadFor(string $suffix): ?array
    {
        foreach ($this->captured as $c) {
            if (str_ends_with((string) $c['url'], $suffix)) {
                return $c['payload'];
            }
        }
        return null;
    }

    private function conversion(): array
    {
        return $this->payloadFor('/conversions')['conversion'];
    }

    private function identify(): ?array
    {
        return $this->payloadFor('/identify');
    }

    public function testMailSentFiresLeadConversionWithDetectedEmail(): void
    {
        $this->withPosted([
            'your-name'  => 'Jane Parent',
            'your-email' => 'jane@example.com',
            'your-phone' => '0400000000',
        ]);

        ContactForm7::onSubmit($this->form(6, 'Contact'), ['status' => 'mail_sent']);

        $this->assertSame('lead', $this->conversion()['conversion_type']);
        $this->assertSame('jane@example.com', $this->conversion()['user_id']);
        $this->assertSame(6, $this->conversion()['properties']['form_id']);
        $this->assertSame('Contact', $this->conversion()['properties']['form_title']);
    }

    public function testIdentifyFiresWithEmailAndCanonicalTraits(): void
    {
        $this->withPosted([
            'your-name'  => 'Jane Parent',
            'your-email' => 'jane@example.com',
            'your-phone' => '0400000000',
        ]);

        ContactForm7::onSubmit($this->form(), ['status' => 'mail_sent']);

        $identify = $this->identify();
        $this->assertNotNull($identify, 'a lead with an email must identify');
        $this->assertSame('jane@example.com', $identify['user_id']);
        $this->assertSame('jane@example.com', $identify['traits']['email']);
        $this->assertSame('0400000000', $identify['traits']['phone']);
        // Single full-name field → non-canonical 'name' trait.
        $this->assertSame('Jane Parent', $identify['traits']['name']);
        $this->assertArrayNotHasKey('first_name', $identify['traits']);
    }

    public function testRsvpFormMapsFirstAndLastNameTraits(): void
    {
        // Open Day RSVP forms split the name and use custom field names.
        $this->withPosted([
            'FirstName'   => 'Sam',
            'LastName'    => 'Carer',
            'CustomerEmail'       => 'sam@example.com',
            'MobilePhone' => '0411111111',
            'attendees'         => '2',
        ]);

        ContactForm7::onSubmit($this->form(9, 'Eastside Open Day RSVP'), ['status' => 'mail_sent']);

        $this->assertSame('sam@example.com', $this->conversion()['user_id']);

        $identify = $this->identify();
        $this->assertSame('Sam', $identify['traits']['first_name']);
        $this->assertSame('Carer', $identify['traits']['last_name']);
        $this->assertSame('0411111111', $identify['traits']['phone']);
        $this->assertArrayNotHasKey('name', $identify['traits']);
    }

    public function testAllSubmittedFieldsPassThroughToProperties(): void
    {
        $this->withPosted([
            'FirstName'   => 'Sam',
            'LastName'    => 'Carer',
            'CustomerEmail'       => 'sam@example.com',
            'MobilePhone' => '0411111111',
            'attendees'         => '2',
            '_wpcf7'            => '9',
            '_wpcf7_unit_tag'   => 'wpcf7-f2265-o1',
        ]);

        ContactForm7::onSubmit($this->form(9, 'Eastside Open Day RSVP'), ['status' => 'mail_sent']);

        $props = $this->conversion()['properties'];
        $this->assertSame('Sam', $props['FirstName']);
        $this->assertSame('2', $props['attendees']);
        $this->assertSame('sam@example.com', $props['CustomerEmail']);
        // Internal CF7 fields must not leak into properties.
        $this->assertArrayNotHasKey('_wpcf7', $props);
        $this->assertArrayNotHasKey('_wpcf7_unit_tag', $props);
        // Form meta still present and uncorrupted.
        $this->assertSame(9, $props['form_id']);
    }

    public function testDemoModeAlsoConverts(): void
    {
        $this->withPosted(['your-email' => 'demo@example.com']);

        ContactForm7::onSubmit($this->form(), ['status' => 'demo_mode']);

        $this->assertSame('lead', $this->conversion()['conversion_type']);
    }

    public function testNonSuccessStatusDoesNotConvertOrIdentify(): void
    {
        $this->withPosted(['your-email' => 'jane@example.com']);

        ContactForm7::onSubmit($this->form(), ['status' => 'validation_failed']);
        ContactForm7::onSubmit($this->form(), ['status' => 'spam']);
        ContactForm7::onSubmit($this->form(), []); // no status key

        $this->assertCount(0, $this->captured);
    }

    public function testPhoneOnlyFormSkipsIdentifyAndOmitsUserId(): void
    {
        // No email field → nothing to identify by; conversion still fires,
        // attributed via the visitor cookie alone.
        $this->withPosted([
            '_wpcf7'     => '9',
            'your-phone' => '0422222222',
        ]);

        ContactForm7::onSubmit($this->form(), ['status' => 'mail_sent']);

        $this->assertNull($this->identify(), 'no email → no identify call');
        $this->assertNotNull($this->payloadFor('/conversions'));
        $this->assertArrayNotHasKey('user_id', $this->conversion());
    }

    public function testUserIdFieldFilterOverridesDetection(): void
    {
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            return $name === 'mbuzz_cf7_user_id_field' ? 'GuardianEmail' : $value;
        });
        $this->withPosted([
            'your-email'    => 'noisy@example.com',
            'GuardianEmail' => 'guardian@example.com',
        ]);

        ContactForm7::onSubmit($this->form(), ['status' => 'mail_sent']);

        $this->assertSame('guardian@example.com', $this->conversion()['user_id']);
        $this->assertSame('guardian@example.com', $this->identify()['user_id']);
    }

    public function testIdentifyTraitsFilterCanOverride(): void
    {
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            if ($name === 'mbuzz_cf7_identify_traits') {
                return ['email' => $value['email'], 'plan' => 'enquiry'];
            }
            return $value;
        });
        $this->withPosted(['your-email' => 'jane@example.com', 'your-phone' => '04000']);

        ContactForm7::onSubmit($this->form(), ['status' => 'mail_sent']);

        $traits = $this->identify()['traits'];
        $this->assertSame('enquiry', $traits['plan']);
        $this->assertArrayNotHasKey('phone', $traits);
    }

    public function testConversionTypeFilterCanRename(): void
    {
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            return $name === 'mbuzz_cf7_conversion_type' ? 'enquiry' : $value;
        });
        $this->withPosted(['your-email' => 'jane@example.com']);

        ContactForm7::onSubmit($this->form(), ['status' => 'mail_sent']);

        $this->assertSame('enquiry', $this->conversion()['conversion_type']);
    }

    public function testSkipFilterShortCircuits(): void
    {
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            return $name === 'mbuzz_cf7_skip_submission' ? true : $value;
        });
        $this->withPosted(['your-email' => 'jane@example.com']);

        ContactForm7::onSubmit($this->form(), ['status' => 'mail_sent']);

        $this->assertCount(0, $this->captured);
    }

    public function testHandlesMultiValueEmailField(): void
    {
        $this->withPosted(['your-email' => ['picked@example.com']]);

        ContactForm7::onSubmit($this->form(), ['status' => 'mail_sent']);

        $this->assertSame('picked@example.com', $this->conversion()['user_id']);
    }
}
