<?php

namespace WpContent\Cli\Tests\Results;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use WpContent\Cli\Results\TableResult;

final class TableResultTest extends TestCase
{
    public function testCellsStayUnderTheirHeaderRegardlessOfKeyOrder(): void
    {
        // The row keys are in a different order than the required columns.
        $result = new TableResult([['slug' => 'my-slug', 'name' => 'My Name']], ['name', 'slug']);

        $output = new BufferedOutput();
        $result->human($output);
        $rendered = $output->fetch();

        // With correct alignment the "name" value comes before the "slug" value in the row.
        self::assertStringContainsString('My Name', $rendered);
        self::assertStringContainsString('my-slug', $rendered);
        self::assertLessThan(
            strpos($rendered, 'my-slug'),
            strpos($rendered, 'My Name'),
            'the value must render under its own header'
        );
    }

    public function testMissingColumnsAreFilledSoRowsStayAligned(): void
    {
        $result = new TableResult([['name' => 'Only Name']], ['name', 'slug']);

        $output = new BufferedOutput();
        $result->human($output);

        self::assertStringContainsString('Only Name', $output->fetch());
    }

    public function testBareLessThanIsNotTruncated(): void
    {
        $result = new TableResult([['requires' => 'Compatible with PHP <8.0 only']], ['requires']);

        $output = new BufferedOutput();
        $result->human($output);

        // strip_tags() would have dropped everything from the bare '<' onwards.
        self::assertStringContainsString('8.0 only', $output->fetch());
    }

    /**
     * A style tag in a value is text, and the table says so.
     *
     * The tag stripper only removes what *looks like* a tag — a name, then
     * optional attributes — so that a bare `<` survives. `<fg=red>` is neither,
     * and unescaped it recoloured everything after it and shifted that row's
     * border by a column, on any value the registry or a header block carried.
     */
    public function testAnAttributeStyleTagIsPrintedRatherThanApplied(): void
    {
        $result = new TableResult([['description' => 'red <fg=red>alert']], ['description']);

        $output = new BufferedOutput(decorated: true);
        $result->human($output);
        $rendered = $output->fetch();

        self::assertStringContainsString('red <fg=red>alert', $rendered);
        self::assertStringNotContainsString("\033[31m", $rendered, 'nothing here asked to be red');
    }
}
