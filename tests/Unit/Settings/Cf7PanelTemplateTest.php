<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Settings\Cf7PanelPresenter;
use Mbuzz\WP\Support\View;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;
use PHPUnit\Framework\TestCase;

/**
 * The panel template must not emit an inline <script>. CF7 6.1+ routes editor
 * panel output through WPCF7_HTMLFormatter::print(), which runs wp_kses() with
 * the admin allowlist — that list has no `script` element, so the tags are
 * stripped and the JS body renders as visible page text. Panel behaviour must
 * ship as an enqueued asset instead.
 */
class Cf7PanelTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'esc_attr'       => static fn($v) => $v,
            'esc_html'       => static fn($v) => $v,
            'esc_url'        => static fn($v) => $v,
            'esc_attr__'     => static fn($v) => $v,
            'esc_html__'     => static fn($v) => $v,
            'esc_html_e'     => static function ($v): void { echo $v; },
            '__'             => static fn($v) => $v,
            'wp_json_encode' => static fn($v) => json_encode($v),
            'checked'        => static function ($a, $b = true): void {},
            'selected'       => static function ($a, $b = true): void {},
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testPanelEmitsNoInlineScript(): void
    {
        $this->assertStringNotContainsStringIgnoringCase(
            '<script',
            $this->renderPanel(),
            'CF7 6.1+ strips <script> from editor panels and prints its contents as text. '
                . 'Enqueue the behaviour as an asset instead of inlining it.'
        );
    }

    public function testKeyedRoleRendersItsNameInputVisible(): void
    {
        $html = $this->renderPanel();

        // The Property row's name input must not be hidden server-side — the
        // panel showing "—" on a populated Property row reads as data loss.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="mbuzz_map\[fields\]\[Location\]\[key\]"[^>]*value="location"(?![^>]*display:none)[^>]*>/',
            $html
        );
    }

    public function testUnkeyedRoleHidesItsNameInput(): void
    {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="mbuzz_map\[fields\]\[GuardianEmail\]\[key\]"[^>]*display:none[^>]*>/',
            $this->renderPanel()
        );
    }

    private function renderPanel(): string
    {
        $map = FieldMap::fromArray([
            FieldMap::K_ENABLED  => true,
            FieldMap::K_TRACK_AS => TrackAs::EVENT,
            FieldMap::K_TYPE     => 'submit_enquiry',
            FieldMap::K_FIELDS   => [
                'Location'      => [FieldMap::K_ROLE => Roles::PROPERTY, FieldMap::K_KEY => 'location'],
                'GuardianEmail' => [FieldMap::K_ROLE => Roles::USER_ID],
            ],
        ]);

        $vm = (new Cf7PanelPresenter('mbuzz_map'))
            ->present($map, ['Location', 'GuardianEmail']);

        return View::capture('admin/cf7-panel', $vm);
    }
}
