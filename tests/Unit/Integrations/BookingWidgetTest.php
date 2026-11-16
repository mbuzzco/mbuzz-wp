<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Mbuzz\WP\Integrations\BookingWidget;
use PHPUnit\Framework\TestCase;

class BookingWidgetTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function testEventNameIsTheSpecifiedOne(): void
    {
        $this->assertSame('booking_opened', BookingWidget::config()['event']);
    }

    public function testDefaultTriggersAreTheThreeInTheSpec(): void
    {
        $this->assertSame(
            ['.ola-trigger-desktop', '.ola-trigger-mobile', '.ola-booking-button'],
            BookingWidget::config()['selectors']
        );
    }

    public function testSelectorsCanBeChangedByFilter(): void
    {
        Filters\expectApplied(BookingWidget::FILTER_SELECTORS)
            ->once()
            ->andReturn(['.book-now', '', 42]);

        $this->assertSame(['.book-now'], BookingWidget::config()['selectors']);
    }

    public function testNoSelectorsMeansDisabled(): void
    {
        Filters\expectApplied(BookingWidget::FILTER_SELECTORS)->once()->andReturn([]);

        $this->assertFalse(BookingWidget::enabled());
    }
}
