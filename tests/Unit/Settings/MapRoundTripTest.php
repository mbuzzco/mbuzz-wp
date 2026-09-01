<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Settings\Cf7PanelPresenter;
use Mbuzz\WP\Settings\Cf7EditorPanel;
use Mbuzz\WP\Settings\FormMapFields;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The save round trip: a form's saved map must survive being rendered in the
 * panel and saved again untouched.
 *
 * This is the invariant 0.3.2 broke. The panel only renders rows for fields the
 * CF7 scanner returns, and a save only keeps fields present in the POST — so
 * anything the scanner stops returning is silently dropped from the map of
 * every form saved afterwards. Excluding `group` tags as cosmetic tidying
 * therefore deleted real mappings from a live account's forms.
 *
 * These tests simulate the full trip — scan → present → POST → sanitize — so a
 * change to ANY of those three stages that loses a mapping fails here.
 */
class MapRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('sanitize_text_field')->alias(static fn ($v) => trim((string) $v));
        Functions\when('sanitize_key')->alias(static fn ($v) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    /**
     * A real-world form: identity, properties, ignored sensitive fields, and
     * Conditional Fields group containers wrapping most of it.
     *
     * @return array<int, array<string, string>>
     */
    private function tags(): array
    {
        return [
            ['name' => 'Location',            'basetype' => 'hidden'],
            ['name' => 'GuardianFirstName',   'basetype' => 'text'],
            ['name' => 'GuardianLastName',    'basetype' => 'text'],
            ['name' => 'GuardianEmail',       'basetype' => 'email'],
            ['name' => 'GuardianMobilePhone', 'basetype' => 'tel'],
            ['name' => 'Postcode',            'basetype' => 'text'],
            ['name' => 'group-1',             'basetype' => 'group'],
            ['name' => 'Child1FirstName',     'basetype' => 'text'],
            ['name' => 'Child1DOB',           'basetype' => 'date'],
            ['name' => 'send',                'basetype' => 'submit'],
        ];
    }

    /** @return array<string, array<string, string>> */
    private function savedFields(): array
    {
        return [
            'Location'            => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'location'],
            'GuardianFirstName'   => [FieldMap::K_ROLE => Roles::TRAIT,    FieldMap::K_KEY => 'first_name'],
            'GuardianLastName'    => [FieldMap::K_ROLE => Roles::TRAIT,    FieldMap::K_KEY => 'last_name'],
            'GuardianEmail'       => [FieldMap::K_ROLE => Roles::USER_ID],
            'GuardianMobilePhone' => [FieldMap::K_ROLE => Roles::TRAIT,    FieldMap::K_KEY => 'phone'],
            'Postcode'            => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'postcode'],
            'Child1FirstName'     => [FieldMap::K_ROLE => Roles::IGNORE],
            'Child1DOB'           => [FieldMap::K_ROLE => Roles::IGNORE],
            // A container tag the admin has already saved a role for. Whatever
            // we think of mapping a container, the map says it — and a re-save
            // must not quietly rewrite what the admin saved.
            'group-1'             => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'group_1'],
            // Injected by another plugin: never a CF7 tag, but posted with the form.
            'form_mode'           => [FieldMap::K_ROLE => Roles::EVENT_TYPE],
        ];
    }

    private function savedMap(): FieldMap
    {
        return FieldMap::fromArray([
            FieldMap::K_ENABLED         => true,
            FieldMap::K_TRACK_AS        => TrackAs::EVENT,
            FieldMap::K_TYPE            => 'enquiry',
            FieldMap::K_CAPTURE_PAGE_AS => null,
            FieldMap::K_FIELDS          => $this->savedFields(),
        ]);
    }

    public function testResavingNeverLosesOrAltersAMapping(): void
    {
        $resaved = $this->roundTrip($this->savedMap())->fields;

        // Every saved mapping survives re-saving byte for byte. A re-save may
        // ADD a row (a newly rendered field defaults to Ignore, which sends
        // nothing) but must never drop or change one.
        foreach ($this->savedFields() as $field => $config) {
            $this->assertSame($config, $resaved[$field] ?? null, "Mapping for '{$field}' changed on re-save.");
        }
    }

    public function testResavingNeverStartsSendingAFieldThatWasNotMapped(): void
    {
        $resaved = $this->roundTrip($this->savedMap())->fields;
        $added   = array_diff_key($resaved, $this->savedFields());

        $this->assertSame([], array_keys(array_filter(
            $added,
            static fn (array $c): bool => $c[FieldMap::K_ROLE] !== Roles::IGNORE
        )), 'A re-save started sending fields the admin never mapped.');

        // Anything a re-save adds must be inert. Silently promoting an unmapped
        // field to a property would leak data the admin never opted into.
        foreach ($added as $field => $config) {
            $this->assertSame(
                Roles::IGNORE,
                $config[FieldMap::K_ROLE],
                "Re-saving started sending '{$field}', which was never mapped."
            );
        }
    }

    public function testResavingKeepsEveryMappedFieldEvenWhenNotACf7Tag(): void
    {
        $resaved = $this->roundTrip($this->savedMap());

        foreach (array_keys($this->savedFields()) as $field) {
            $this->assertArrayHasKey(
                $field,
                $resaved->fields,
                "Re-saving the form dropped the mapping for '{$field}'."
            );
        }
    }

    public function testResavingKeepsTheFormTrackable(): void
    {
        $resaved = $this->roundTrip($this->savedMap());

        $this->assertTrue($resaved->isTrackable());
        $this->assertSame('enquiry', $resaved->type);
        $this->assertSame(TrackAs::EVENT, $resaved->trackAs);
    }

    public function testResavingKeepsSensitiveFieldsIgnored(): void
    {
        $resaved = $this->roundTrip($this->savedMap());

        $this->assertSame(Roles::IGNORE, $resaved->fields['Child1FirstName'][FieldMap::K_ROLE]);
        $this->assertSame(Roles::IGNORE, $resaved->fields['Child1DOB'][FieldMap::K_ROLE]);
    }

    public function testAMappingSurvivesEvenIfTheScannerStopsReturningItsField(): void
    {
        // The 0.3.2 regression, reproduced as a permanent guard. Two things had
        // to be true for real customer mappings to be deleted: the CF7 scanner
        // stopped returning a tag type, AND the presenter rendered only scanned
        // fields. This asserts the second never regresses — whatever the
        // scanner does, a saved mapping is still rendered, so a save keeps it.
        $vm = (new Cf7PanelPresenter('mbuzz_map'))
            ->present($this->savedMap(), ['Location']); // scanner returns almost nothing

        $rendered = array_column($vm['rows'], 'field');

        foreach (array_keys($this->savedFields()) as $field) {
            $this->assertContains(
                $field,
                $rendered,
                "'{$field}' is mapped but was not rendered, so saving would delete it."
            );
        }
    }

    /**
     * Render the saved map into panel rows, turn those rows into the POST the
     * browser would send, and sanitize it back into a map — the exact path a
     * "Save" takes through wp-admin.
     */
    private function roundTrip(FieldMap $map): FieldMap
    {
        $scan = new ReflectionMethod(Cf7EditorPanel::class, 'scanFieldNames');
        $scan->setAccessible(true);
        $fieldNames = $scan->invoke(null, $this->contactForm());

        $vm = (new Cf7PanelPresenter('mbuzz_map'))->present($map, $fieldNames);

        $posted = [
            FieldMap::K_ENABLED         => $vm['enabled'] ? '1' : '',
            FieldMap::K_TRACK_AS        => $vm['track_as'],
            FieldMap::K_TYPE            => $vm['type'],
            FieldMap::K_CAPTURE_PAGE_AS => $vm['capture_page_as'],
            FieldMap::K_FIELDS          => [],
        ];

        // Every rendered row posts its role and its key — exactly what the form does.
        foreach ($vm['rows'] as $row) {
            $posted[FieldMap::K_FIELDS][$row['field']] = [
                FieldMap::K_ROLE => $row['role'],
                FieldMap::K_KEY  => $row['key'],
            ];
        }

        return FormMapFields::sanitize($posted);
    }

    private function contactForm(): object
    {
        return new class ($this->tags()) {
            /** @param array<int, array<string, string>> $tags */
            public function __construct(private array $tags)
            {
            }

            /** @return array<int, array<string, string>> */
            public function scan_form_tags(): array
            {
                return $this->tags;
            }
        };
    }
}
