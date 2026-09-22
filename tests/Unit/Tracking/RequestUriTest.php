<?php

declare(strict_types=1);

namespace Mbuzz\WP\Tests\Unit\Tracking;

use Mbuzz\WP\Tracking\RequestUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The REQUEST_URI a cached page's session is recorded under. It must carry the
 * query string, because that is where every ad click lives: on 2026-09-22 it
 * was rebuilt from the path alone, and BSA's Meta ad visits all arrived as
 * organic_social. Spec: lib/specs/query-string-capture-spec.md, All States.
 */
class RequestUriTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function pageUrls(): array
    {
        return [
            'ad click keeps its query'  => ['https://site.test/centres/x/?fbclid=AB&utm_source=facebook', '/centres/x/?fbclid=AB&utm_source=facebook'],
            'no query is unchanged'     => ['https://site.test/centres/x/', '/centres/x/'],
            'query on the root'         => ['https://site.test/?gclid=G1', '/?gclid=G1'],
            'root with no path'         => ['https://site.test?gclid=G1', '/?gclid=G1'],
            'fragment is dropped'       => ['https://site.test/x/?utm_source=a#form', '/x/?utm_source=a'],
            'empty query marker'        => ['https://site.test/x/?', '/x/'],
            'encoding is preserved'     => ['https://site.test/x/?utm_campaign=spring%20sale', '/x/?utm_campaign=spring%20sale'],
            'blank url falls back'      => ['', '/'],
            'unparseable url falls back' => ['http://', '/'],
        ];
    }

    #[DataProvider('pageUrls')]
    public function testBuildsTheRequestUriAServerWouldPresent(string $pageUrl, string $expected): void
    {
        $this->assertSame($expected, RequestUri::fromPageUrl($pageUrl));
    }
}
