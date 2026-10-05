<?php

namespace WpContent\Cli\Tests\Command;

use PHPUnit\Framework\TestCase;
use WpContent\Cli\Command\ResourceCatalog;
use WpContent\Cli\ResourceType;
use WpContent\Cli\Tests\Fake\FakeRegistryClient;

final class ResourceCatalogTest extends TestCase
{
    /**
     * The detail panel `list` opens asks for the row's slug — which is the
     * registry's, but still a value: it stays one path segment.
     */
    public function testARecordIsAskedForByItsEncodedSlug(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/themes/my%20theme', ['slug' => 'my theme']);

        $record = (new ResourceCatalog($registry, ResourceType::Theme))->record('my theme');

        self::assertSame(['slug' => 'my theme'], $record);
        self::assertSame(['/themes/my%20theme'], $registry->calls);
    }

    public function testAPageIsAskedForOnTheCollectionPath(): void
    {
        $registry = (new FakeRegistryClient())->willReturn('/plugins?page=2&per_page=5', [
            'plugins' => [],
            'info' => ['page' => 2, 'pages' => 2, 'results' => 5],
        ]);

        (new ResourceCatalog($registry, ResourceType::Plugin))->page(2, 5);

        self::assertSame(['/plugins?page=2&per_page=5'], $registry->calls);
    }
}
