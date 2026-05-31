<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Tracking;

use Mbuzz\WP\Tracking\AutoDetector;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;
use PHPUnit\Framework\TestCase;

/**
 * Auto-detection produces a suggested, fully-editable map — never an imposed
 * schema, and never a PII user_id.
 */
class AutoDetectorTest extends TestCase
{
    private function roleOf(FieldMap $map, string $field): ?string
    {
        return $map->fields[$field][FieldMap::K_ROLE] ?? null;
    }

    private function keyOf(FieldMap $map, string $field): ?string
    {
        return $map->fields[$field][FieldMap::K_KEY] ?? null;
    }

    public function testSuggestsTraitsForEmailNamePhone(): void
    {
        $map = AutoDetector::suggest(
            ['FirstName', 'LastName', 'CustomerEmail', 'MobilePhone'],
            'Downtown Event RSVP'
        );

        $this->assertSame(Roles::TRAIT, $this->roleOf($map, 'CustomerEmail'));
        $this->assertSame('email', $this->keyOf($map, 'CustomerEmail'));
        $this->assertSame('first_name', $this->keyOf($map, 'FirstName'));
        $this->assertSame('last_name', $this->keyOf($map, 'LastName'));
        $this->assertSame('phone', $this->keyOf($map, 'MobilePhone'));
        $this->assertSame(TrackAs::CONVERSION, $map->trackAs);
        $this->assertSame('downtown_event_rsvp', $map->type); // slug of the form title
    }

    public function testNeverAssignsUserId(): void
    {
        $map = AutoDetector::suggest(['CustomerEmail', 'MobilePhone', 'CustomerRef']);

        foreach ($map->fields as $config) {
            $this->assertNotSame(Roles::USER_ID, $config[FieldMap::K_ROLE]);
        }
    }

    public function testIgnoresTrackingFields(): void
    {
        $map = AutoDetector::suggest(['gclid', 'fbclid', 'utm_source', 'utm_campaign', 'source']);

        foreach (['gclid', 'fbclid', 'utm_source', 'utm_campaign', 'source'] as $f) {
            $this->assertSame(Roles::IGNORE, $this->roleOf($map, $f), "$f should be ignored");
        }
    }

    public function testSensitiveFieldsDefaultToIgnore(): void
    {
        $map = AutoDetector::suggest(['Child1FirstName', 'Child1DOB', 'Estimated1DOB', 'TeamSize']);

        // Conservative: anything child/DOB-related is opt-in (ignored by default).
        $this->assertSame(Roles::IGNORE, $this->roleOf($map, 'Child1FirstName'));
        $this->assertSame(Roles::IGNORE, $this->roleOf($map, 'Child1DOB'));
        $this->assertSame(Roles::IGNORE, $this->roleOf($map, 'Estimated1DOB'));
    }

    public function testOtherFieldsBecomePropertiesWithSnakeKey(): void
    {
        $map = AutoDetector::suggest(['Postcode', 'your-message']);

        $this->assertSame(Roles::PROPERTY, $this->roleOf($map, 'Postcode'));
        $this->assertSame('postcode', $this->keyOf($map, 'Postcode'));
        $this->assertSame('your_message', $this->keyOf($map, 'your-message'));
    }

    public function testTypeDefaultsToLeadWithoutTitle(): void
    {
        $map = AutoDetector::suggest(['your-email']);

        $this->assertSame(FieldMap::DEFAULT_TYPE, $map->type);
    }
}
