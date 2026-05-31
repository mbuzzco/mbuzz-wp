<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Identity\Hooks;
use Mbuzz\WP\Privacy\PersonalData;
use PHPUnit\Framework\TestCase;

/**
 * The Privacy API exporter/eraser. The plugin keeps only the
 * `_mbuzz_last_identified_at` user meta locally, so the exporter discloses
 * third-party processing + that record, and the eraser removes the local meta
 * while reporting the mbuzz-side copy as retained.
 */
class PersonalDataTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    private function user(int $id): object
    {
        return (object) ['ID' => $id];
    }

    public function testExporterAndEraserRegisterUnderOneKey(): void
    {
        $exporters = PersonalData::registerExporter([]);
        $erasers   = PersonalData::registerEraser([]);

        $this->assertArrayHasKey(PersonalData::KEY, $exporters);
        $this->assertSame([PersonalData::class, 'export'], $exporters[PersonalData::KEY]['callback']);
        $this->assertSame([PersonalData::class, 'erase'], $erasers[PersonalData::KEY]['callback']);
    }

    public function testExportDisclosesThirdPartyAndLocalRecordForAKnownUser(): void
    {
        Functions\when('get_user_by')->justReturn($this->user(5));
        Functions\when('get_user_meta')->justReturn('1700000000');

        $result = PersonalData::export('jo@example.com');

        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);
        $group = $result['data'][0];
        $this->assertSame(PersonalData::GROUP_ID, $group['group_id']);

        $values = array_column($group['data'], 'value', 'name');
        $this->assertSame('mbuzz (api.mbuzz.co)', $values['Processed by']);
        $this->assertArrayHasKey('What is shared', $values);
        $this->assertStringContainsString('2023', $values['Last identified to mbuzz']); // 1700000000 → 2023
    }

    public function testExportReturnsNothingForAnEmailWithNoAccount(): void
    {
        Functions\when('get_user_by')->justReturn(false);

        $result = PersonalData::export('stranger@example.com');

        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    public function testEraseDeletesLocalMetaAndRetainsThirdPartyCopy(): void
    {
        Functions\when('get_user_by')->justReturn($this->user(5));
        Functions\when('get_user_meta')->justReturn('1700000000');
        Functions\expect('delete_user_meta')->once()->with(5, Hooks::META_LAST_IDENTIFIED_AT);

        $result = PersonalData::erase('jo@example.com');

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['items_retained'], 'mbuzz-side copy cannot be auto-erased');
        $this->assertNotEmpty($result['messages']);
        $this->assertTrue($result['done']);
    }

    public function testEraseRemovesNothingWhenNoLocalRecord(): void
    {
        Functions\when('get_user_by')->justReturn(false);
        Functions\expect('delete_user_meta')->never();

        $result = PersonalData::erase('stranger@example.com');

        $this->assertFalse($result['items_removed']);
        $this->assertTrue($result['items_retained']); // disclosure still applies
    }
}
