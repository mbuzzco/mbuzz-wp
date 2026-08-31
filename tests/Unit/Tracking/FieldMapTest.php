<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Tracking;

use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;
use PHPUnit\Framework\TestCase;

/**
 * The mapping engine. Pure PHP, no WordPress. Schema-agnostic: user_id is taken
 * only from a field explicitly roled user_id; trait/property keys are arbitrary.
 */
class FieldMapTest extends TestCase
{
    /**
     * @param array<string, array<string, string>> $fields
     */
    private function map(array $fields, ?string $capturePageAs = null, string $trackAs = TrackAs::CONVERSION): FieldMap
    {
        return FieldMap::fromArray([
            FieldMap::K_ENABLED         => true,
            FieldMap::K_TRACK_AS        => $trackAs,
            FieldMap::K_TYPE            => 'enquiry',
            FieldMap::K_CAPTURE_PAGE_AS => $capturePageAs,
            FieldMap::K_FIELDS          => $fields,
        ]);
    }

    public function testResolvesUserIdAndArbitraryTraits(): void
    {
        $hit = $this->map([
            'CustomerRef'       => [FieldMap::K_ROLE => Roles::USER_ID],
            'CustomerEmail'       => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'email'],
            'MobilePhone' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'contact_phone'],
        ])->resolve([
            'CustomerRef'       => 'CUST-7781',
            'CustomerEmail'       => 'jo@example.com',
            'MobilePhone' => '0400 000 000',
        ]);

        $this->assertSame(TrackAs::CONVERSION, $hit->trackAs);
        $this->assertSame('enquiry', $hit->type);
        $this->assertSame('CUST-7781', $hit->userId);
        $this->assertSame('jo@example.com', $hit->traits['email']);
        $this->assertSame('0400 000 000', $hit->traits['contact_phone']); // arbitrary key, verbatim
        $this->assertTrue($hit->hasIdentity());
    }

    public function testNeverSubstitutesEmailForUserId(): void
    {
        // An email trait is present but NO field is roled user_id → no user_id,
        // and with no join key there is nothing to identify.
        $hit = $this->map([
            'CustomerEmail' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'email'],
        ])->resolve(['CustomerEmail' => 'jo@example.com']);

        $this->assertNull($hit->userId);
        $this->assertSame('jo@example.com', $hit->traits['email']);
        $this->assertFalse($hit->hasIdentity());
    }

    public function testUserIdWithoutTraitsStillHasIdentity(): void
    {
        // A join key alone is enough to stitch — traits are optional enrichment.
        $hit = $this->map([
            'CustomerRef' => [FieldMap::K_ROLE => Roles::USER_ID],
        ])->resolve(['CustomerRef' => 'CUST-7781']);

        $this->assertSame('CUST-7781', $hit->userId);
        $this->assertSame([], $hit->traits);
        $this->assertTrue($hit->hasIdentity());
    }

    public function testPropertyKeepsArbitraryKeyAndArrays(): void
    {
        $hit = $this->map([
            'TeamSize' => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'team_size'],
            'days'             => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'days_requested'],
        ])->resolve([
            'TeamSize' => '2',
            'days'             => ['Mon', 'Wed'],
        ]);

        $this->assertSame('2', $hit->properties['team_size']);
        $this->assertSame(['Mon', 'Wed'], $hit->properties['days_requested']); // array preserved
    }

    public function testIgnoreRoleDropsField(): void
    {
        $hit = $this->map([
            'Child1FirstName' => [FieldMap::K_ROLE => Roles::IGNORE],
            'CustomerEmail'     => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'email'],
        ])->resolve([
            'Child1FirstName' => 'Sam',
            'CustomerEmail'     => 'jo@example.com',
        ]);

        $this->assertArrayNotHasKey('Child1FirstName', $hit->properties);
        $this->assertNotContains('Sam', $hit->traits);
        $this->assertNotContains('Sam', $hit->properties);
    }

    public function testMissingFieldSkipped(): void
    {
        $hit = $this->map([
            'Postcode' => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'postcode'],
        ])->resolve([]); // field absent from this submission

        $this->assertSame([], $hit->properties);
    }

    public function testRevenueAndCurrency(): void
    {
        $hit = $this->map([
            'fee' => [FieldMap::K_ROLE => Roles::REVENUE],
            'cur' => [FieldMap::K_ROLE => Roles::CURRENCY],
        ])->resolve([
            'fee' => '$2,500.50',
            'cur' => 'AUD',
        ]);

        $this->assertSame(2500.50, $hit->revenue);
        $this->assertSame('AUD', $hit->currency);
    }

    public function testCapturePageAsAddsTitleAndAudit(): void
    {
        $hit = $this->map([], 'location')->resolve([], [
            FieldMap::PAGE_ID    => 12,
            FieldMap::PAGE_TITLE => 'Downtown Office',
            FieldMap::PAGE_URL   => 'https://example.com/locations/downtown/',
        ]);

        $this->assertSame('Downtown Office', $hit->properties['location']);
        $this->assertSame(12, $hit->properties[FieldMap::PROP_PAGE_ID]);
        $this->assertStringContainsString('downtown', $hit->properties[FieldMap::PROP_PAGE_URL]);
    }

    public function testMultiValueUsesFirstScalarForIdentity(): void
    {
        $hit = $this->map([
            'ref'   => [FieldMap::K_ROLE => Roles::USER_ID],
            'email' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'email'],
        ])->resolve([
            'ref'   => ['CUST-1'],
            'email' => ['picked@example.com', 'second@example.com'],
        ]);

        $this->assertSame('CUST-1', $hit->userId);
        $this->assertSame('picked@example.com', $hit->traits['email']);
    }

    public function testNotTrackableWhenOffOrDisabled(): void
    {
        $this->assertFalse($this->map([], null, TrackAs::OFF)->isTrackable());
        $this->assertFalse(
            FieldMap::fromArray([FieldMap::K_ENABLED => false, FieldMap::K_TRACK_AS => TrackAs::CONVERSION])->isTrackable()
        );
        $this->assertTrue($this->map([])->isTrackable());
    }

    public function testFromArrayDefaults(): void
    {
        $map = FieldMap::fromArray([
            FieldMap::K_TRACK_AS => 'nonsense', // invalid → OFF
            FieldMap::K_TYPE     => '',          // empty → DEFAULT_TYPE
        ]);

        $this->assertSame(TrackAs::OFF, $map->trackAs);
        $this->assertSame(FieldMap::DEFAULT_TYPE, $map->type);
        $this->assertSame([], $map->fields);
    }
    // --- Event type from a field (Roles::EVENT_TYPE) ---

    public function testEventTypeFieldOverridesTheMapType(): void
    {
        $hit = $this->mapWith(
            ['lineleader_form_mode' => [FieldMap::K_ROLE => Roles::EVENT_TYPE]],
            'll_submit_enquiry'
        )->resolve(['lineleader_form_mode' => 'll_submit_tour']);

        $this->assertSame('ll_submit_tour', $hit->type);
    }

    public function testEventTypeFieldFallsBackWhenAbsent(): void
    {
        $hit = $this->mapWith(
            ['lineleader_form_mode' => [FieldMap::K_ROLE => Roles::EVENT_TYPE]],
            'll_submit_enquiry'
        )->resolve([]);

        $this->assertSame('ll_submit_enquiry', $hit->type);
    }

    public function testEventTypeFieldFallsBackWhenBlank(): void
    {
        $hit = $this->mapWith(
            ['lineleader_form_mode' => [FieldMap::K_ROLE => Roles::EVENT_TYPE]],
            'll_submit_enquiry'
        )->resolve(['lineleader_form_mode' => '   ']);

        $this->assertSame('ll_submit_enquiry', $hit->type);
    }

    public function testEventTypeFieldEmitsNoProperty(): void
    {
        $hit = $this->mapWith(
            ['lineleader_form_mode' => [FieldMap::K_ROLE => Roles::EVENT_TYPE]],
            'll_submit_enquiry'
        )->resolve(['lineleader_form_mode' => 'll_submit_tour']);

        $this->assertSame([], $hit->properties);
    }

    /**
     * @param array<string, array<string, string>> $fields
     */
    private function mapWith(array $fields, string $type): FieldMap
    {
        return FieldMap::fromArray([
            FieldMap::K_ENABLED  => true,
            FieldMap::K_TRACK_AS => TrackAs::EVENT,
            FieldMap::K_TYPE     => $type,
            FieldMap::K_FIELDS   => $fields,
        ]);
    }

}
