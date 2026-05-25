<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Settings\Fields;
use PHPUnit\Framework\TestCase;

class SettingsFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('sanitize_text_field')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function testSanitizeReturnsDefaultsForNonArrayInput(): void
    {
        $clean = Fields::sanitize('nonsense');

        $this->assertSame('', $clean['api_key']);
        $this->assertTrue($clean['enabled']);
    }

    public function testSanitizeCoercesCheckboxAbsenceToFalse(): void
    {
        // Unchecked checkboxes simply don't appear in form POST.
        $clean = Fields::sanitize(['api_key' => 'sk_live_x']);

        $this->assertFalse($clean['enabled']);
        $this->assertFalse($clean['debug']);
        $this->assertFalse($clean['track_admins']);
    }

    public function testSanitizeAcceptsValidIdentifyAt(): void
    {
        $clean = Fields::sanitize(['identify_at' => 'every_page']);

        $this->assertSame('every_page', $clean['identify_at']);
    }

    public function testSanitizeRejectsInvalidIdentifyAt(): void
    {
        $clean = Fields::sanitize(['identify_at' => 'malicious']);

        // Falls back to the default rather than persisting garbage.
        $this->assertSame('login', $clean['identify_at']);
    }

    public function testSanitizeSplitsSkipPathsByLine(): void
    {
        $clean = Fields::sanitize([
            'skip_paths' => "/foo\n/bar\n\n  /baz  \n",
        ]);

        $this->assertSame(['/foo', '/bar', '/baz'], $clean['skip_paths']);
    }
}
