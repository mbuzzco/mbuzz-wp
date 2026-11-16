<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mbuzz\WP\Integrations\ClickEvents;
use PHPUnit\Framework\TestCase;

class ClickEventsTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function testNothingIsConfiguredByDefault(): void
    {
        Functions\when('get_option')->justReturn([]);

        $this->assertSame([], ClickEvents::config()['rules']);
        $this->assertFalse(ClickEvents::enabled());
    }

    public function testRulesComeFromTheSavedSetting(): void
    {
        Functions\when('get_option')->justReturn([
            'click_events' => [['event' => 'signup_click', 'selector' => '.signup']],
        ]);

        $this->assertSame([['event' => 'signup_click', 'selector' => '.signup']], ClickEvents::config()['rules']);
        $this->assertTrue(ClickEvents::enabled());
    }

    public function testIncompleteRulesAreDropped(): void
    {
        Functions\when('get_option')->justReturn([
            'click_events' => [['event' => '', 'selector' => '.a'], ['event' => 'b', 'selector' => ''], ['event' => 'ok', 'selector' => '.c']],
        ]);

        $this->assertSame([['event' => 'ok', 'selector' => '.c']], ClickEvents::config()['rules']);
    }

    public function testRulesCanBeAddedByFilter(): void
    {
        Functions\when('get_option')->justReturn([]);
        Filters\expectApplied(ClickEvents::FILTER_RULES)->once()->andReturn([['event' => 'x', 'selector' => '.x']]);

        $this->assertSame([['event' => 'x', 'selector' => '.x']], ClickEvents::config()['rules']);
    }
}
