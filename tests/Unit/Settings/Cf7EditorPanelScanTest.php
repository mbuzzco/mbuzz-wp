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
    public function testExcludesLayoutContainersAndSubmit(): void
    {
        $names = $this->scan([
            ['name' => 'GuardianEmail',  'basetype' => 'email'],
            ['name' => 'group-1',        'basetype' => 'group'],
            ['name' => 'cf7mls_step-1',  'basetype' => 'cf7mls_step'],
            ['name' => 'send',           'basetype' => 'submit'],
        ]);

        $this->assertSame(['GuardianEmail'], $names);
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
