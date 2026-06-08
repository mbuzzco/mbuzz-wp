<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Rest\LeadRequest;
use PHPUnit\Framework\TestCase;

/**
 * Sanitizes the embedded-form capture payload. Schema-agnostic: trait/property
 * keys are owner-named; values are owner-supplied. Mirrors the array-sanitization
 * discipline of Settings\FormMapFields (no single-value pattern).
 */
class LeadRequestTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        // Lightweight stand-ins for the WP sanitizers used by the value object.
        Functions\when('sanitize_key')->alias(static fn ($v) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)));
        Functions\when('sanitize_text_field')->alias(static fn ($v) => trim(preg_replace('/\s+/', ' ', (string) $v)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    /** @param array<string,mixed> $raw */
    private function req(array $raw): LeadRequest
    {
        return LeadRequest::fromArray($raw);
    }

    public function testTypeIsSluggedAndDefaultsToLead(): void
    {
        // sanitize_key lowercases and strips non [a-z0-9_-]; owners pass a slug.
        $this->assertSame('tour_booking', $this->req(['type' => 'Tour_Booking'])->type);
        $this->assertSame('open_day_rsvp', $this->req(['type' => 'open_day_rsvp'])->type);
        $this->assertSame('lead', $this->req([])->type, 'missing type defaults to lead');
        $this->assertSame('lead', $this->req(['type' => '   '])->type, 'blank type defaults to lead');
    }

    public function testUserIdTrimmedOrNull(): void
    {
        $this->assertSame('parent@example.com', $this->req(['user_id' => '  parent@example.com '])->userId);
        $this->assertNull($this->req([])->userId);
        $this->assertNull($this->req(['user_id' => '   '])->userId);
    }

    public function testTraitsKeyedAndSanitized(): void
    {
        $r = $this->req(['traits' => ['phone' => ' 0400 ', 'first_name' => 'Jo', 'Bad Key!' => 'x']]);
        $this->assertSame('0400', $r->traits['phone']);
        $this->assertSame('Jo', $r->traits['first_name']);
        $this->assertArrayHasKey('badkey', $r->traits, 'keys are sanitize_key-ed');
    }

    public function testTraitsDropEmptyValues(): void
    {
        $r = $this->req(['traits' => ['phone' => '', 'email' => '  ']]);
        $this->assertSame([], $r->traits, 'empty trait values are dropped (no PII-less noise)');
    }

    public function testPropertiesKeyedAndArraysPreserved(): void
    {
        $r = $this->req(['properties' => ['location' => 'Calala', 'days' => ['Mon', 'Tue'], 'Bad Key' => 'v']]);
        $this->assertSame('Calala', $r->properties['location']);
        $this->assertSame(['Mon', 'Tue'], $r->properties['days'], 'array property values preserved');
        $this->assertArrayHasKey('badkey', $r->properties);
    }

    public function testNonArrayTraitsPropertiesIgnored(): void
    {
        $r = $this->req(['traits' => 'nope', 'properties' => 5]);
        $this->assertSame([], $r->traits);
        $this->assertSame([], $r->properties);
    }

    public function testKeyCountCapped(): void
    {
        $many = [];
        for ($i = 0; $i < 100; $i++) {
            $many["k{$i}"] = 'v';
        }
        $r = $this->req(['properties' => $many]);
        $this->assertLessThanOrEqual(LeadRequest::MAX_KEYS, count($r->properties), 'properties capped');
    }

    public function testHasIdentity(): void
    {
        $this->assertTrue($this->req(['user_id' => 'a@b.com'])->hasIdentity());
        $this->assertFalse($this->req([])->hasIdentity());
    }
}
