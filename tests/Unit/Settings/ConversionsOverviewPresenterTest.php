<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Settings;

use Mbuzz\WP\Settings\ConversionsOverviewPresenter;
use Mbuzz\WP\Settings\FormSummary;
use Mbuzz\WP\Support\Links;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;
use PHPUnit\Framework\TestCase;

/**
 * The overview view-model is pure data — no WordPress, no escaping. The
 * template turns track_as/type into labels.
 */
class ConversionsOverviewPresenterTest extends TestCase
{
    private function present(array $forms, bool $hasApiKey = true): array
    {
        return (new ConversionsOverviewPresenter())->present($forms, $hasApiKey);
    }

    private function form(string $title, ?FieldMap $map): FormSummary
    {
        return new FormSummary($title, 'https://example.test/wp-admin/admin.php?page=wpcf7&post=9', $map);
    }

    private function map(bool $enabled, string $trackAs, array $fields): FieldMap
    {
        return FieldMap::fromArray([
            FieldMap::K_ENABLED  => $enabled,
            FieldMap::K_TRACK_AS => $trackAs,
            FieldMap::K_TYPE     => 'enquiry',
            FieldMap::K_FIELDS   => $fields,
        ]);
    }

    public function testEmptyStateWhenNoForms(): void
    {
        $vm = $this->present([]);

        $this->assertFalse($vm['has_forms']);
        $this->assertSame(0, $vm['tracked_count']);
        $this->assertSame([], $vm['rows']);
        $this->assertSame(Links::DOCS, $vm['docs_url']);
        $this->assertTrue($vm['has_api_key']);
    }

    public function testApiKeyFlagPassesThrough(): void
    {
        $this->assertFalse($this->present([], false)['has_api_key']);
    }

    public function testFormWithNoMapIsUntracked(): void
    {
        $vm = $this->present([$this->form('Contact', null)]);

        $this->assertTrue($vm['has_forms']);
        $this->assertSame(0, $vm['tracked_count']);

        $row = $vm['rows'][0];
        $this->assertSame('Contact', $row['title']);
        $this->assertStringContainsString('post=9', $row['edit_url']);
        $this->assertFalse($row['tracked']);
        $this->assertSame(TrackAs::OFF, $row['track_as']);
        $this->assertSame('', $row['type']);
        $this->assertSame(0, $row['mapped_count']);
        $this->assertFalse($row['has_user_id']);
    }

    public function testTrackedFormCountsRolesAndUserId(): void
    {
        $vm = $this->present([
            $this->form('Enquiry', $this->map(true, TrackAs::CONVERSION, [
                'CustomerId'   => [FieldMap::K_ROLE => Roles::USER_ID],
                'CustomerName' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'first_name'],
                'Notes'        => [FieldMap::K_ROLE => Roles::IGNORE],
            ])),
        ]);

        $this->assertSame(1, $vm['tracked_count']);

        $row = $vm['rows'][0];
        $this->assertTrue($row['tracked']);
        $this->assertSame(TrackAs::CONVERSION, $row['track_as']);
        $this->assertSame('enquiry', $row['type']);
        $this->assertSame(2, $row['mapped_count']);   // user_id + trait, ignore excluded
        $this->assertSame(1, $row['ignored_count']);
        $this->assertTrue($row['has_user_id']);
    }

    public function testDisabledMapIsNotTracked(): void
    {
        $row = $this->present([
            $this->form('Draft', $this->map(false, TrackAs::CONVERSION, [
                'CustomerId' => [FieldMap::K_ROLE => Roles::USER_ID],
            ])),
        ])['rows'][0];

        $this->assertFalse($row['tracked']);
        $this->assertSame(0, $this->present([
            $this->form('Draft', $this->map(false, TrackAs::CONVERSION, [])),
        ])['tracked_count']);
    }
}
