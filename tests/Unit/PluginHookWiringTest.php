<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Hook-wiring guard. Plugin::register() wires many WordPress callbacks; if any
 * is mis-declared (e.g. an instance method registered as a static [self::class,
 * 'method'] callback), WordPress fatals only when the hook actually fires —
 * invisible to unit tests that stub add_action. This captures every registered
 * callback and asserts it is genuinely callable, catching that class of bug at
 * unit level (it shipped a front-end 500 once: enqueueCaptureHelper wired as
 * static when it's an instance method).
 */
class PluginHookWiringTest extends TestCase
{
    /** @var array<int, mixed> */
    private array $callbacks = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->callbacks = [];

        $capture = function (...$args) {
            // Hook callback is the 2nd argument for add_action/add_filter and
            // register_activation_hook/register_uninstall_hook.
            if (isset($args[1])) {
                $this->callbacks[] = $args[1];
            }
            return true;
        };

        Functions\when('add_action')->alias($capture);
        Functions\when('add_filter')->alias($capture);
        Functions\when('register_activation_hook')->alias($capture);
        Functions\when('register_uninstall_hook')->alias($capture);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function testEveryRegisteredHookCallbackIsCallable(): void
    {
        // Fresh registration run (register() is idempotent-guarded, so use a
        // dedicated instance reflection-free path: the singleton's register()).
        Plugin::instance()->register();

        $this->assertNotEmpty($this->callbacks, 'register() should wire hooks');

        foreach ($this->callbacks as $cb) {
            $label = is_array($cb)
                ? (is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0]) . '::' . (string) $cb[1]
                : (is_string($cb) ? $cb : get_debug_type($cb));

            $this->assertTrue(
                is_callable($cb),
                "Hook callback is not callable: {$label}"
            );

            // For [class/object, method] callbacks, assert static-vs-instance
            // is declared correctly — the exact mismatch that caused the 500.
            if (is_array($cb) && is_string($cb[0]) && method_exists($cb[0], $cb[1])) {
                $rm = new \ReflectionMethod($cb[0], $cb[1]);
                $this->assertTrue(
                    $rm->isStatic(),
                    "Callback {$label} is registered as static [class, method] but the method is not static"
                );
            }
        }
    }
}
