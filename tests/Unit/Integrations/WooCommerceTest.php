<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\Mbuzz;
use Mbuzz\WP\Integrations\WooCommerce;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * WooCommerce integration coverage. WC_Order mocked via Mockery; SDK driven
 * end-to-end through a transport-capture seam.
 */
class WooCommerceTest extends TestCase
{
    /** @var array<int, array{method:string, url:string, payload:?array, body:array}> */
    private array $captured = [];

    /** @var int incrementing fake conversion id for assertion */
    private int $convCounter = 0;

    /** @var array<int, object>  Order-id → mock map, used by wc_get_order stub. */
    private array $orderMap = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        $this->captured     = [];
        $this->convCounter  = 0;
        $this->orderMap     = [];
        WooCommerce::resetCacheForTests();

        // Visitor cookie — in production it's there from the visitor's first
        // hit; here the SDK refuses to send conversions with neither
        // visitor_id nor user_id, so guest-checkout tests need a cookie.
        $_COOKIE['_mbuzz_vid'] = str_repeat('a', 64);

        // WP function stubs.
        Functions\when('add_action')->justReturn(true);
        Functions\when('apply_filters')->alias(static function ($_name, $value) {
            return $value;
        });
        // Settings::current() reads get_option.
        Functions\when('get_option')->justReturn([]);

        // wc_get_order resolves from the per-test orderMap.
        Functions\when('wc_get_order')->alias(function ($id) {
            return $this->orderMap[(int) $id] ?? false;
        });
        // wc_get_orders for first-paid check — default to "no prior orders".
        Functions\when('wc_get_orders')->justReturn([]);

        Mbuzz::init(['api_key' => 'sk_test_wc']);
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload) {
            $body = ['conversion' => ['id' => 'conv_' . (++$this->convCounter)]];
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
        Mbuzz::reset();
        Mockery::close();
        Monkey\tearDown();
        unset($_COOKIE['_mbuzz_vid']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeOrder(int $id, array $overrides = []): object
    {
        $defaults = [
            'get_id'             => $id,
            'get_user_id'        => 0,
            'get_total'          => 49.99,
            'get_currency'       => 'USD',
            'get_billing_email'  => 'cust@example.com',
            'get_order_number'   => (string) $id,
            'get_payment_method' => 'stripe',
            'get_coupon_codes'   => [],
            'get_item_count'     => 1,
            'get_items'          => [],
            'get_meta'           => '',
        ];
        $stubs = array_merge($defaults, $overrides);

        $order = Mockery::mock();
        foreach ($stubs as $method => $value) {
            $order->shouldReceive($method)->andReturn($value);
        }
        // Mutating methods — accept and ignore by default; specific tests can override.
        $order->shouldReceive('update_meta_data')->andReturnTrue();
        $order->shouldReceive('save')->andReturnTrue();

        $this->orderMap[$id] = $order;
        return $order;
    }

    public function testThankYouSendsPurchaseConversion(): void
    {
        $this->makeOrder(123, [
            'get_user_id' => 7,
            'get_total'   => 99.50,
        ]);

        WooCommerce::onThankYou(123);

        $this->assertCount(1, $this->captured);
        $this->assertStringEndsWith('/conversions', $this->captured[0]['url']);
        $conversion = $this->captured[0]['payload']['conversion'];
        $this->assertSame('purchase', $conversion['conversion_type']);
        $this->assertSame('7', $conversion['user_id']);
        $this->assertSame(99.50, $conversion['revenue']);
        $this->assertTrue($conversion['is_acquisition']);
        $this->assertFalse($conversion['inherit_acquisition']);
        $this->assertArrayNotHasKey('identifier', $conversion);
    }

    public function testThankYouSetsOrderMetaWithReturnedConversionId(): void
    {
        $order = Mockery::mock();
        $order->shouldReceive('get_id')->andReturn(456);
        $order->shouldReceive('get_user_id')->andReturn(0);
        $order->shouldReceive('get_total')->andReturn(20.0);
        $order->shouldReceive('get_currency')->andReturn('USD');
        $order->shouldReceive('get_billing_email')->andReturn('g@example.com');
        $order->shouldReceive('get_order_number')->andReturn('456');
        $order->shouldReceive('get_payment_method')->andReturn('stripe');
        $order->shouldReceive('get_coupon_codes')->andReturn([]);
        $order->shouldReceive('get_item_count')->andReturn(1);
        $order->shouldReceive('get_items')->andReturn([]);
        $order->shouldReceive('get_meta')->andReturn('');
        // The behavior we're verifying:
        $order->shouldReceive('update_meta_data')->once()->with('_mbuzz_conversion_id', 'conv_1');
        $order->shouldReceive('save')->once();

        $this->orderMap[456] = $order;

        WooCommerce::onThankYou(456);

        // Mockery's expectations verify in tearDown.
        $this->assertCount(1, $this->captured);
    }

    public function testProcessingAndCompletedDedupeAgainstThankYou(): void
    {
        // After thankyou fires, the order returns its conversion id from get_meta.
        $callCount  = 0;
        $afterFirst = false;
        $order = Mockery::mock();
        $order->shouldReceive('get_id')->andReturn(789);
        $order->shouldReceive('get_user_id')->andReturn(0);
        $order->shouldReceive('get_total')->andReturn(10.0);
        $order->shouldReceive('get_currency')->andReturn('USD');
        $order->shouldReceive('get_billing_email')->andReturn('d@example.com');
        $order->shouldReceive('get_order_number')->andReturn('789');
        $order->shouldReceive('get_payment_method')->andReturn('stripe');
        $order->shouldReceive('get_coupon_codes')->andReturn([]);
        $order->shouldReceive('get_item_count')->andReturn(1);
        $order->shouldReceive('get_items')->andReturn([]);
        // get_meta returns '' initially, then 'conv_1' after the first track call.
        $order->shouldReceive('get_meta')->andReturnUsing(function () use (&$afterFirst) {
            return $afterFirst ? 'conv_1' : '';
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function () use (&$afterFirst) {
            $afterFirst = true;
        });
        $order->shouldReceive('save')->andReturnTrue();
        $this->orderMap[789] = $order;

        WooCommerce::onThankYou(789);
        WooCommerce::onStatusProcessing(789, $order);
        WooCommerce::onStatusCompleted(789, $order);

        $this->assertCount(1, $this->captured, 'only the first hook should fire a conversion');
    }

    public function testProcessingResolvesOrderFromIdWhenSecondArgMissing(): void
    {
        // Some WP fires this hook with only the id. Our handler should fall
        // back to wc_get_order() rather than fatal.
        $this->makeOrder(900);

        WooCommerce::onStatusProcessing(900);

        $this->assertCount(1, $this->captured);
    }

    public function testFirstPaidOrderFlagFlipsWhenPriorOrdersExist(): void
    {
        Functions\when('wc_get_orders')->justReturn([42]); // prior paid order

        $this->makeOrder(100, ['get_user_id' => 5]);
        WooCommerce::onThankYou(100);

        $conversion = $this->captured[0]['payload']['conversion'];
        $this->assertFalse($conversion['is_acquisition']);
        $this->assertTrue($conversion['inherit_acquisition']);
    }

    public function testGuestCheckoutSendsEmailAsUserId(): void
    {
        // Guest checkout: WP user_id=0, billing email present.
        // We now send the email as user_id so the backend can find-or-create
        // an Identity scoped to it — works even with no visitor cookie.
        $this->makeOrder(200, [
            'get_user_id'       => 0,
            'get_billing_email' => 'guest@example.com',
        ]);

        WooCommerce::onThankYou(200);

        $conversion = $this->captured[0]['payload']['conversion'];
        $this->assertSame('guest@example.com', $conversion['user_id']);
        $this->assertTrue($conversion['is_acquisition']);
        $this->assertArrayNotHasKey('identifier', $conversion);
    }

    public function testGuestCheckoutWithNoEmailOmitsUserIdEntirely(): void
    {
        // Defensive: no WP user, no billing email → nothing to use as user_id.
        // Plugin sends null; SDK proceeds with visitor cookie alone (anonymous
        // attribution). Tested with cookie present, since the cookie carries
        // the only identifier the backend will see for this checkout.
        $this->makeOrder(210, [
            'get_user_id'       => 0,
            'get_billing_email' => '',
        ]);

        WooCommerce::onThankYou(210);

        $this->assertCount(1, $this->captured);
        $conversion = $this->captured[0]['payload']['conversion'];
        $this->assertArrayNotHasKey('user_id', $conversion);
    }

    public function testSkipFilterShortCircuitsTracking(): void
    {
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            return $name === 'mbuzz_woocommerce_skip_order' ? true : $value;
        });
        $this->makeOrder(300, ['get_user_id' => 1]);

        WooCommerce::onThankYou(300);

        $this->assertCount(0, $this->captured);
    }

    public function testConversionTypeFilterCanRename(): void
    {
        Functions\when('apply_filters')->alias(static function ($name, $value) {
            return $name === 'mbuzz_woocommerce_conversion_type' ? 'sale' : $value;
        });
        $this->makeOrder(310, ['get_user_id' => 1]);

        WooCommerce::onThankYou(310);

        $this->assertSame('sale', $this->captured[0]['payload']['conversion']['conversion_type']);
    }

    public function testRefundSendsNegativeRevenueConversion(): void
    {
        $this->makeOrder(400, ['get_user_id' => 12, 'get_total' => 200.0]);
        $refund = Mockery::mock();
        $refund->shouldReceive('get_amount')->andReturn(50.0);
        $refund->shouldReceive('get_reason')->andReturn('item damaged');
        $this->orderMap[401] = $refund;

        WooCommerce::onRefunded(400, 401);

        $this->assertCount(1, $this->captured);
        $conversion = $this->captured[0]['payload']['conversion'];
        $this->assertSame('refund', $conversion['conversion_type']);
        // JSON round-trip collapses -50.0 → -50 (int). Loose equality is what
        // we actually care about for revenue magnitudes.
        $this->assertEquals(-50.0, $conversion['revenue']);
        $this->assertTrue($conversion['inherit_acquisition']);
        $this->assertSame(400, $conversion['properties']['original_order_id']);
        $this->assertSame(401, $conversion['properties']['refund_id']);
        $this->assertSame('item damaged', $conversion['properties']['refund_reason']);
    }

    public function testRefundSkipsZeroAmount(): void
    {
        $this->makeOrder(410, ['get_user_id' => 1]);
        $refund = Mockery::mock();
        $refund->shouldReceive('get_amount')->andReturn(0.0);
        $refund->shouldReceive('get_reason')->andReturn('');
        $this->orderMap[411] = $refund;

        WooCommerce::onRefunded(410, 411);

        $this->assertCount(0, $this->captured);
    }

    public function testOrderPropertiesIncludeLineItemsWhenSettingOn(): void
    {
        $product = Mockery::mock();
        $product->shouldReceive('get_sku')->andReturn('SKU-1');

        $item = Mockery::mock();
        $item->shouldReceive('get_product_id')->andReturn(7);
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_quantity')->andReturn(2);
        $item->shouldReceive('get_subtotal')->andReturn(20.0);

        // Settings default has wc_include_order_details=true.
        $this->makeOrder(500, ['get_user_id' => 3, 'get_items' => [$item]]);

        WooCommerce::onThankYou(500);

        $props = $this->captured[0]['payload']['conversion']['properties'];
        $this->assertArrayHasKey('line_items', $props);
        $this->assertCount(1, $props['line_items']);
        $this->assertSame(7, $props['line_items'][0]['product_id']);
        $this->assertSame('SKU-1', $props['line_items'][0]['sku']);
        $this->assertSame(2, $props['line_items'][0]['quantity']);
    }

    public function testOrderPropertiesOmitLineItemsWhenSettingOff(): void
    {
        Functions\when('get_option')->justReturn(['wc_include_order_details' => false]);

        $this->makeOrder(600, ['get_user_id' => 3]);
        WooCommerce::onThankYou(600);

        $props = $this->captured[0]['payload']['conversion']['properties'];
        $this->assertArrayNotHasKey('line_items', $props);
        // Core fields still present.
        $this->assertSame(600, $props['order_id']);
    }
}
