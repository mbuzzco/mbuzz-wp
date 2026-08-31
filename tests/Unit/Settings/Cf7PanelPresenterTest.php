<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Settings;

use Mbuzz\WP\Settings\Cf7PanelPresenter;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;
use PHPUnit\Framework\TestCase;

/**
 * The panel view-model is pure data — no WordPress, no escaping. The template
 * consumes it.
 */
class Cf7PanelPresenterTest extends TestCase
{
    private function present(FieldMap $map, array $fieldNames): array
    {
        return (new Cf7PanelPresenter('mbuzz_map'))->present($map, $fieldNames);
    }

    private function map(array $fields): FieldMap
    {
        return FieldMap::fromArray([
            FieldMap::K_ENABLED  => true,
            FieldMap::K_TRACK_AS => TrackAs::CONVERSION,
            FieldMap::K_TYPE     => 'enquiry',
            FieldMap::K_FIELDS   => $fields,
        ]);
    }

    public function testTopLevelViewModel(): void
    {
        $vm = $this->present($this->map([]), []);

        $this->assertTrue($vm['enabled']);
        $this->assertSame('mbuzz_map[enabled]', $vm['enabled_name']);
        $this->assertSame(TrackAs::CONVERSION, $vm['track_as']);
        $this->assertSame('mbuzz_map[track_as]', $vm['track_as_name']);
        $this->assertSame('enquiry', $vm['type']);
        $this->assertSame('mbuzz_map[type]', $vm['type_name']);
        $this->assertSame(TrackAs::ALL, $vm['track_as_options']);
        $this->assertSame(Roles::ALL, $vm['role_options']);
        $this->assertSame(Roles::KEYED, $vm['keyed_roles']);
    }

    public function testRowsCarryInputNamesAndCurrentValues(): void
    {
        $vm = $this->present(
            $this->map([
                'CustomerEmail' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'email'],
            ]),
            ['CustomerEmail', 'Postcode'] // Postcode is unmapped
        );

        $this->assertCount(2, $vm['rows']);

        [$email, $postcode] = $vm['rows'];

        $this->assertSame('CustomerEmail', $email['field']);
        $this->assertSame(Roles::TRAIT, $email['role']);
        $this->assertSame('email', $email['key']);
        $this->assertSame('mbuzz_map[fields][CustomerEmail][role]', $email['role_name']);
        $this->assertSame('mbuzz_map[fields][CustomerEmail][key]', $email['key_name']);
        $this->assertSame('mbuzz-role-0', $email['role_id']);
        $this->assertTrue($email['key_used']); // trait carries a mbuzz name

        // Unmapped field defaults to ignore with no key.
        $this->assertSame('Postcode', $postcode['field']);
        $this->assertSame(Roles::IGNORE, $postcode['role']);
        $this->assertSame('', $postcode['key']);
        $this->assertSame('mbuzz-role-1', $postcode['role_id']);
        $this->assertFalse($postcode['key_used']); // ignore takes no name
    }

    public function testUserIdRoleTakesNoKey(): void
    {
        $vm = $this->present(
            $this->map([
                'CustomerRef' => [FieldMap::K_ROLE => Roles::USER_ID],
            ]),
            ['CustomerRef']
        );

        [$row] = $vm['rows'];
        $this->assertSame(Roles::USER_ID, $row['role']);
        $this->assertFalse($row['key_used']); // user_id is the key — no mbuzz name
    }
    public function testKeepsAMappedFieldTheScannerCannotSee(): void
    {
        // Injected by another plugin as raw HTML: absent from scan_form_tags(),
        // present in the posted data. A saved mapping must not vanish.
        $map = $this->map([
            'lineleader_form_mode' => [FieldMap::K_ROLE => Roles::EVENT_TYPE],
        ]);

        $fields = array_column($this->present($map, ['GuardianEmail'])['rows'], 'field');

        $this->assertSame(['GuardianEmail', 'lineleader_form_mode'], $fields);
    }

    public function testDoesNotDuplicateAFieldThatIsAlsoATag(): void
    {
        $map = $this->map([
            'GuardianEmail' => [FieldMap::K_ROLE => Roles::USER_ID],
        ]);

        $fields = array_column($this->present($map, ['GuardianEmail'])['rows'], 'field');

        $this->assertSame(['GuardianEmail'], $fields);
    }

}
