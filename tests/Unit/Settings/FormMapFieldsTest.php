<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Settings\FormMapFields;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;
use PHPUnit\Framework\TestCase;

/**
 * Sanitization of the per-form map panel input — the repeatable, variable-key
 * security surface.
 */
class FormMapFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('sanitize_text_field')->alias(static fn ($v) => trim((string) $v));
        // Mirror WP: lowercase, keep a-z0-9_- only.
        Functions\when('sanitize_key')->alias(static fn ($v) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function testUnknownRoleFallsBackToIgnore(): void
    {
        $map = FormMapFields::sanitize([
            FieldMap::K_TRACK_AS => TrackAs::CONVERSION,
            FieldMap::K_FIELDS   => [
                'Evil' => [FieldMap::K_ROLE => 'exfiltrate'],
            ],
        ]);

        $this->assertSame(Roles::IGNORE, $map->fields['Evil'][FieldMap::K_ROLE]);
        $this->assertArrayNotHasKey(FieldMap::K_KEY, $map->fields['Evil']);
    }

    public function testKeyedRoleWithoutKeyIsDropped(): void
    {
        $map = FormMapFields::sanitize([
            FieldMap::K_FIELDS => [
                'CustomerEmail' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => ''],
            ],
        ]);

        $this->assertArrayNotHasKey('CustomerEmail', $map->fields);
    }

    public function testPreservesFieldNameButSanitizesMbuzzKey(): void
    {
        $map = FormMapFields::sanitize([
            FieldMap::K_TRACK_AS => TrackAs::CONVERSION,
            FieldMap::K_FIELDS   => [
                'CustomerEmail' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'Email Address!'],
                'your-phone'  => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'phone'],
            ],
        ]);

        // field name (array key) preserved verbatim so it matches posted data
        $this->assertArrayHasKey('CustomerEmail', $map->fields);
        $this->assertArrayHasKey('your-phone', $map->fields);
        // mbuzz key sanitized
        $this->assertSame('emailaddress', $map->fields['CustomerEmail'][FieldMap::K_KEY]);
    }

    public function testUserIdRoleNeedsNoKey(): void
    {
        $map = FormMapFields::sanitize([
            FieldMap::K_FIELDS => [
                'CustomerRef' => [FieldMap::K_ROLE => Roles::USER_ID],
            ],
        ]);

        $this->assertSame(Roles::USER_ID, $map->fields['CustomerRef'][FieldMap::K_ROLE]);
        $this->assertArrayNotHasKey(FieldMap::K_KEY, $map->fields['CustomerRef']);
    }

    public function testInvalidTrackAsAndEmptyTypeDefault(): void
    {
        $map = FormMapFields::sanitize([
            FieldMap::K_TRACK_AS => 'nope',
            FieldMap::K_TYPE     => '   ',
        ]);

        $this->assertSame(TrackAs::OFF, $map->trackAs);
        $this->assertSame(FieldMap::DEFAULT_TYPE, $map->type);
    }

    public function testCapturePageAsSanitizedOrNull(): void
    {
        $withCapture = FormMapFields::sanitize([FieldMap::K_CAPTURE_PAGE_AS => 'Location Name']);
        $this->assertSame('locationname', $withCapture->capturePageAs);

        $without = FormMapFields::sanitize([FieldMap::K_CAPTURE_PAGE_AS => '']);
        $this->assertNull($without->capturePageAs);
    }

    public function testEnabledCoercion(): void
    {
        $this->assertTrue(FormMapFields::sanitize([FieldMap::K_ENABLED => '1'])->enabled);
        $this->assertFalse(FormMapFields::sanitize([])->enabled);
    }
}
