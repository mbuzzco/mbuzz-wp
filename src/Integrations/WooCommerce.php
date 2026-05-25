<?php
/**
 * WooCommerce integration — the spec's marquee feature (§6).
 *
 * Hooks: thankyou + processing + completed (all deduped via order meta),
 * order_refunded, and a placeholder for subscription renewals (deferred —
 * paid extension, lower alpha priority).
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP\Integrations;

use Mbuzz\Mbuzz;
use Mbuzz\WP\Settings\Repository as SettingsRepository;

final class WooCommerce
{
    private const META_KEY = '_mbuzz_conversion_id';

    /**
     * Per-request cache of is-first-paid-order lookups. Keyed by user id.
     * A single request can hit the check multiple times (thankyou + processing
     * deduping); cache prevents the wc_get_orders() query running twice.
     *
     * @var array<int, bool>
     */
    private static array $firstPaidCache = [];

    public static function register(): void
    {
        if (! self::isAvailable()) {
            return;
        }

        add_action('woocommerce_thankyou', [self::class, 'onThankYou'], 10, 1);
        add_action('woocommerce_order_status_processing', [self::class, 'onStatusProcessing'], 10, 2);
        add_action('woocommerce_order_status_completed', [self::class, 'onStatusCompleted'], 10, 2);
        add_action('woocommerce_order_refunded', [self::class, 'onRefunded'], 10, 2);

        // TODO(v1.x): woocommerce_subscription_renewal_payment_complete
        // — requires WooCommerce Subscriptions (paid). Conversion type 'payment'
        // with inherit_acquisition: true. See spec §6.
    }

    private static function isAvailable(): bool
    {
        return function_exists('wc_get_order');
    }

    public static function onThankYou(int $orderId): void
    {
        $order = self::resolveOrder($orderId);
        if ($order !== null) {
            self::trackPurchase($order);
        }
    }

    /**
     * Fires for orders that paid via a gateway stopping at "processing"
     * (the Stripe + digital-goods case). The first of thankyou/processing/
     * completed to fire wins; the rest dedupe out via order meta.
     *
     * @param mixed $order  WC may pass the order as the second arg; resolve from id if not.
     */
    public static function onStatusProcessing(int $orderId, $order = null): void
    {
        $order = self::ensureOrder($order, $orderId);
        if ($order !== null) {
            self::trackPurchase($order);
        }
    }

    /**
     * Fallback for orders that skip the thank-you page and never passed
     * through processing — admin-marked completions, renewals.
     *
     * @param mixed $order
     */
    public static function onStatusCompleted(int $orderId, $order = null): void
    {
        $order = self::ensureOrder($order, $orderId);
        if ($order !== null) {
            self::trackPurchase($order);
        }
    }

    public static function onRefunded(int $orderId, int $refundId): void
    {
        $order  = self::resolveOrder($orderId);
        $refund = self::resolveOrder($refundId);
        if ($order === null || $refund === null) {
            return;
        }

        $amount = abs((float) $refund->get_amount());
        if ($amount <= 0) {
            return;
        }

        Mbuzz::conversion('refund', [
            'user_id'             => self::stringUserId($order),
            'revenue'             => -$amount,
            'currency'            => (string) $order->get_currency(),
            'inherit_acquisition' => true,
            'identifier'          => self::identifierFor($order),
            'properties'          => [
                'original_order_id' => $order->get_id(),
                'refund_id'         => $refundId,
                'refund_reason'     => (string) $refund->get_reason(),
            ],
        ]);
    }

    private static function trackPurchase(object $order): void
    {
        if (self::alreadyTracked($order)) {
            return;
        }
        if (apply_filters('mbuzz_woocommerce_skip_order', false, $order)) {
            return;
        }

        $type    = (string) apply_filters('mbuzz_woocommerce_conversion_type', 'purchase', $order);
        $isFirst = self::isFirstPaidOrder($order);

        $result = Mbuzz::conversion($type, [
            'user_id'             => self::stringUserId($order),
            'revenue'             => (float) $order->get_total(),
            'currency'            => (string) $order->get_currency(),
            'is_acquisition'      => $isFirst,
            'inherit_acquisition' => ! $isFirst,
            'identifier'          => self::identifierFor($order),
            'properties'          => self::orderProperties($order),
        ]);

        if (is_array($result) && ! empty($result['conversion_id'])) {
            // HPOS-safe: $order->update_meta_data + save, NOT update_post_meta.
            $order->update_meta_data(self::META_KEY, (string) $result['conversion_id']);
            $order->save();
        }
    }

    private static function alreadyTracked(object $order): bool
    {
        $existing = $order->get_meta(self::META_KEY);
        return is_string($existing) && $existing !== '';
    }

    private static function isFirstPaidOrder(object $order): bool
    {
        $userId = (int) $order->get_user_id();
        if ($userId === 0) {
            // Guest checkout — backend's email-based stitching handles
            // acquisition attribution if/when the user later registers.
            return true;
        }

        if (isset(self::$firstPaidCache[$userId])) {
            return self::$firstPaidCache[$userId];
        }

        $prior = wc_get_orders([
            'customer_id' => $userId,
            'status'      => ['completed', 'processing'],
            'exclude'     => [$order->get_id()],
            'return'      => 'ids',
            'limit'       => 1,
        ]);

        return self::$firstPaidCache[$userId] = (is_array($prior) && $prior === []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function orderProperties(object $order): array
    {
        $settings = SettingsRepository::current();

        $props = [
            'order_id'       => $order->get_id(),
            'order_number'   => (string) $order->get_order_number(),
            'payment_method' => (string) $order->get_payment_method(),
            'coupons'        => (array) $order->get_coupon_codes(),
            'item_count'     => (int) $order->get_item_count(),
        ];

        if ($settings['wc_include_order_details']) {
            $props['line_items'] = array_values(array_map(
                static function ($item): array {
                    $product = $item->get_product();
                    return [
                        'product_id' => (int) $item->get_product_id(),
                        'sku'        => $product !== null ? (string) $product->get_sku() : '',
                        'quantity'   => (int) $item->get_quantity(),
                        'subtotal'   => (float) $item->get_subtotal(),
                    ];
                },
                $order->get_items()
            ));
        }

        return $props;
    }

    /**
     * @return array<string, string>
     */
    private static function identifierFor(object $order): array
    {
        $email = (string) $order->get_billing_email();
        return $email !== '' ? ['email' => $email] : [];
    }

    private static function stringUserId(object $order): ?string
    {
        $id = (int) $order->get_user_id();
        return $id > 0 ? (string) $id : null;
    }

    private static function resolveOrder(int $orderId): ?object
    {
        $order = wc_get_order($orderId);
        return is_object($order) ? $order : null;
    }

    /**
     * @param mixed $order
     */
    private static function ensureOrder($order, int $orderId): ?object
    {
        return is_object($order) ? $order : self::resolveOrder($orderId);
    }

    /**
     * For tests — drops the per-request first-paid cache.
     */
    public static function resetCacheForTests(): void
    {
        self::$firstPaidCache = [];
    }
}
