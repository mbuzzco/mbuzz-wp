<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Settings;

use Mbuzz\WP\Settings\Cf7EditorPanel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Only real data fields belong in the mapping table. Layout containers that
 * add-ons register as form tags — Conditional Fields' `group`, CF7MLS's
 * `cf7mls_step` — are not values a form submits, so listing them invites
 * mapping a container by mistake.
 */
class Cf7EditorPanelScanTest extends TestCase
{
    public function testExcludesOnlyTheSubmitButton(): void
    {
        $names = $this->scan([
            ['name' => 'GuardianEmail',  'basetype' => 'email'],
            ['name' => 'group-1',        'basetype' => 'group'],
            ['name' => 'send',           'basetype' => 'submit'],
        ]);

        // Only submit is excluded. Container tags stay: the scanned list feeds
        // what a save persists, so excluding a type drops it from saved maps.
        $this->assertSame(['GuardianEmail', 'group-1'], $names);
    }

    public function testKeepsOrdinaryFieldsAndDedupes(): void
    {
        $names = $this->scan([
            ['name' => 'Location',  'basetype' => 'hidden'],
            ['name' => 'Postcode',  'basetype' => 'text'],
            ['name' => 'Location',  'basetype' => 'hidden'],
        ]);

        $this->assertSame(['Location', 'Postcode'], $names);
    }

    /**
     * @param array<int, array<string, string>> $tags
     * @return array<int, string>
     */
    private function scan(array $tags): array
    {
        $contactForm = new class ($tags) {
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

        $method = new ReflectionMethod(Cf7EditorPanel::class, 'scanFieldNames');
        $method->setAccessible(true);

        return $method->invoke(null, $contactForm);
    }
}
