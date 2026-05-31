<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Tracking;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mbuzz\WP\Tracking\FieldMap;
use Mbuzz\WP\Tracking\FieldMapRepository;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\Source;
use Mbuzz\WP\Tracking\TrackAs;
use PHPUnit\Framework\TestCase;

/**
 * Per-form map storage. CF7 maps live in the form's post meta.
 */
class FieldMapRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function testForReturnsNullWhenNoMeta(): void
    {
        Functions\when('get_post_meta')->justReturn(''); // WP returns '' when unset

        $this->assertNull(FieldMapRepository::for(Source::CF7, 7));
    }

    public function testForBuildsFieldMapFromMeta(): void
    {
        Functions\when('get_post_meta')->justReturn([
            FieldMap::K_ENABLED  => true,
            FieldMap::K_TRACK_AS => TrackAs::CONVERSION,
            FieldMap::K_TYPE     => 'enquiry',
            FieldMap::K_FIELDS   => [
                'CustomerEmail' => [FieldMap::K_ROLE => Roles::TRAIT, FieldMap::K_KEY => 'email'],
            ],
        ]);

        $map = FieldMapRepository::for(Source::CF7, 7);

        $this->assertInstanceOf(FieldMap::class, $map);
        $this->assertSame('enquiry', $map->type);
        $this->assertTrue($map->isTrackable());
    }

    public function testSavePersistsToPostMeta(): void
    {
        $map = FieldMap::fromArray([
            FieldMap::K_ENABLED  => true,
            FieldMap::K_TRACK_AS => TrackAs::CONVERSION,
            FieldMap::K_TYPE     => 'enquiry',
        ]);

        Functions\expect('update_post_meta')
            ->once()
            ->with(7, FieldMapRepository::META_KEY, $map->toArray());

        FieldMapRepository::save(Source::CF7, 7, $map);

        // The Mockery expectation above is the assertion (verified on tearDown).
        $this->addToAssertionCount(1);
    }

    public function testUnknownSourceReturnsNull(): void
    {
        $this->assertNull(FieldMapRepository::for('unsupported', 1));
    }
}
