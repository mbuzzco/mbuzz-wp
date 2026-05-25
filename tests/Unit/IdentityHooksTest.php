<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Identity\Hooks;
use PHPUnit\Framework\TestCase;

/**
 * Identity hook coverage. Strategy: install a fake SDK transport, fire the
 * hook handler directly, inspect captured POST payloads. End-to-end through
 * the SDK; no mocking of Mbuzz itself.
 */
class IdentityHooksTest extends TestCase
{
    /** @var array<int, array{method:string, url:string, payload:?array}> */
    private array $captured = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        // Stub the WP functions Hooks reaches for.
        Functions\when('add_action')->justReturn(true);
        Functions\when('update_user_meta')->justReturn(true);

        Mbuzz::init(['api_key' => 'sk_test_identity']);
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload) {
            $this->captured[] = [
                'method'  => $method,
                'url'     => $url,
                'payload' => $payload !== null ? json_decode($payload, true) : null,
            ];
            // Identify uses Api::post (returns bool). Conversion uses
            // postWithResponse (returns parsed body) — give it the shape
            // ConversionRequest expects.
            return ['status' => 200, 'body' => ['conversion' => ['id' => 'conv_test']]];
        });

        $this->captured = [];
    }

    protected function tearDown(): void
    {
        Mbuzz::reset();
        Monkey\tearDown();
    }

    public function testOnLoginSendsIdentifyWithTraits(): void
    {
        $user = (object) [
            'ID'           => 42,
            'user_email'   => 'jane@example.com',
            'display_name' => 'Jane Doe',
            'roles'        => ['subscriber'],
        ];

        Hooks::onLogin('jane', $user);

        $this->assertCount(1, $this->captured);
        $this->assertStringEndsWith('/identify', $this->captured[0]['url']);
        $body = $this->captured[0]['payload'];
        $this->assertSame('42', $body['user_id']);
        $this->assertSame('jane@example.com', $body['traits']['email']);
        $this->assertSame('Jane Doe', $body['traits']['name']);
        $this->assertSame('subscriber', $body['traits']['role']);
    }

    public function testOnLoginIgnoresInvalidUserObject(): void
    {
        Hooks::onLogin('ghost', (object) []);

        $this->assertCount(0, $this->captured, 'no API call should fire for a user with no ID');
    }

    public function testOnLoginHandlesUserWithNoRole(): void
    {
        $user = (object) [
            'ID'           => 7,
            'user_email'   => 'no@role.com',
            'display_name' => 'No Role',
            'roles'        => [],
        ];

        Hooks::onLogin('no', $user);

        $this->assertCount(1, $this->captured);
        $this->assertNull($this->captured[0]['payload']['traits']['role']);
    }

    public function testOnRegisterFiresIdentifyThenSignupConversion(): void
    {
        $user = (object) [
            'ID'           => 99,
            'user_email'   => 'new@user.com',
            'display_name' => 'New User',
            'roles'        => ['customer'],
        ];
        Functions\when('get_userdata')->justReturn($user);

        Hooks::onRegister(99);

        $this->assertCount(2, $this->captured);

        // First call: identify.
        $this->assertStringEndsWith('/identify', $this->captured[0]['url']);
        $this->assertSame('99', $this->captured[0]['payload']['user_id']);

        // Second call: signup conversion with is_acquisition. The email
        // already flowed via the identify call above; backend's Identity
        // row carries it. No need to re-send under the (deprecated)
        // `identifier` field.
        $this->assertStringEndsWith('/conversions', $this->captured[1]['url']);
        $conversion = $this->captured[1]['payload']['conversion'];
        $this->assertSame('signup', $conversion['conversion_type']);
        $this->assertSame('99', $conversion['user_id']);
        $this->assertTrue($conversion['is_acquisition']);
        $this->assertArrayNotHasKey('identifier', $conversion);
    }

    public function testOnRegisterSkipsWhenUserdataReturnsFalse(): void
    {
        Functions\when('get_userdata')->justReturn(false);

        Hooks::onRegister(1234);

        $this->assertCount(0, $this->captured);
    }

    public function testOnProfileUpdateReIdentifiesWhenEmailChanged(): void
    {
        $oldUser = (object) ['user_email' => 'old@example.com', 'display_name' => 'Same Name'];
        $newUser = (object) [
            'ID'           => 5,
            'user_email'   => 'new@example.com',
            'display_name' => 'Same Name',
            'roles'        => ['editor'],
        ];
        Functions\when('get_userdata')->justReturn($newUser);

        Hooks::onProfileUpdate(5, $oldUser);

        $this->assertCount(1, $this->captured);
        $this->assertSame('new@example.com', $this->captured[0]['payload']['traits']['email']);
    }

    public function testOnProfileUpdateSkipsWhenEmailAndNameUnchanged(): void
    {
        $sameUser = (object) [
            'ID'           => 5,
            'user_email'   => 'stable@example.com',
            'display_name' => 'Stable Name',
            'roles'        => ['editor'],
        ];
        $oldData = (object) ['user_email' => 'stable@example.com', 'display_name' => 'Stable Name'];
        Functions\when('get_userdata')->justReturn($sameUser);

        Hooks::onProfileUpdate(5, $oldData);

        $this->assertCount(0, $this->captured, 'no-op when nothing identity-relevant changed');
    }

    public function testOnProfileUpdateReIdentifiesWhenDisplayNameChanged(): void
    {
        $newUser = (object) [
            'ID'           => 5,
            'user_email'   => 'same@example.com',
            'display_name' => 'Renamed',
            'roles'        => ['editor'],
        ];
        $oldData = (object) ['user_email' => 'same@example.com', 'display_name' => 'Original'];
        Functions\when('get_userdata')->justReturn($newUser);

        Hooks::onProfileUpdate(5, $oldData);

        $this->assertCount(1, $this->captured);
        $this->assertSame('Renamed', $this->captured[0]['payload']['traits']['name']);
    }
}
