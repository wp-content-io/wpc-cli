<?php

namespace WpContent\Cli\Tests\Tui\Component;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use WpContent\Cli\Tui\Component\Component;
use WpContent\Cli\Tui\Component\DataTable;
use WpContent\Cli\Tui\Key;
use WpContent\Cli\Tui\KeyPress;
use WpContent\Cli\Tui\Page;

final class DataTableTest extends TestCase
{
    private const COLUMNS = ['name', 'slug', 'version'];

    public function testRendersTheRowsOfTheCurrentPage(): void
    {
        $frame = $this->plain($this->table()->render(80, 12));

        self::assertStringContainsString('Plugins', $frame);
        self::assertStringContainsString('NAME', $frame);
        self::assertStringContainsString('Alpha', $frame);
        self::assertStringContainsString('gamma', $frame);
    }

    public function testFooterReportsPagesAndTotalSeparately(): void
    {
        // The old footer printed the item count where it announced pages.
        $frame = $this->plain($this->table()->render(80, 12));

        self::assertStringContainsString('Page 1/3', $frame);
        self::assertStringContainsString('67 plugins', $frame);
    }

    public function testCursorStartsOnTheFirstRowAndMovesDown(): void
    {
        $table = $this->table();

        self::assertCursorRow('Alpha', $this->cursorLine($table));

        $table->handle(new KeyPress(Key::Down));
        self::assertCursorRow('Beta', $this->cursorLine($table));

        $table->handle(new KeyPress(Key::Down));
        $table->handle(new KeyPress(Key::Up));
        self::assertCursorRow('Beta', $this->cursorLine($table));
    }

    public function testCursorStopsAtTheBoundaries(): void
    {
        $table = $this->table();

        $table->handle(new KeyPress(Key::Up));
        self::assertCursorRow('Alpha', $this->cursorLine($table));

        $table->handle(new KeyPress(Key::End));
        self::assertCursorRow('Gamma', $this->cursorLine($table));

        $table->handle(new KeyPress(Key::Down));
        self::assertCursorRow('Gamma', $this->cursorLine($table));
    }

    public function testRightArrowLoadsTheNextPage(): void
    {
        $requested = [];
        $table = $this->table(function (int $number) use (&$requested): Page {
            $requested[] = $number;

            return new Page([['name' => 'Delta', 'slug' => 'delta', 'version' => '4.0']], $number, 3, 67);
        });

        $table->handle(new KeyPress(Key::Right));

        self::assertSame([2], $requested);
        self::assertSame(2, $table->page()->number);
        self::assertStringContainsString('Delta', $this->plain($table->render(80, 12)));
        self::assertStringContainsString('Page 2/3', $this->plain($table->render(80, 12)));
    }

    public function testPagingPastTheLastPageIsRefusedWithoutARequest(): void
    {
        $calls = 0;
        $table = $this->table(function (int $number) use (&$calls): Page {
            $calls++;

            return new Page([], $number, 3, 67);
        });

        $table->handle(new KeyPress(Key::Left));

        self::assertSame(0, $calls, 'no request should be issued before the first page');
        self::assertStringContainsString('Already on the first page', $this->plain($table->render(80, 12)));
    }

    public function testFailedPageLoadKeepsTheCurrentPage(): void
    {
        $table = $this->table(static fn (): ?Page => null);

        $table->handle(new KeyPress(Key::Right));

        self::assertSame(1, $table->page()->number);
        self::assertStringContainsString('Could not load page 2', $this->plain($table->render(80, 12)));
    }

    public function testSlashFiltersTheLoadedRows(): void
    {
        $table = $this->table();

        $table->handle(new KeyPress(Key::Char, '/'));
        foreach (['g', 'a', 'm'] as $character) {
            $table->handle(new KeyPress(Key::Char, $character));
        }

        $frame = $this->plain($table->render(80, 12));

        self::assertStringContainsString('Gamma', $frame);
        self::assertStringNotContainsString('Alpha', $frame);
        self::assertStringContainsString('1 match on this page', $frame);
    }

    public function testEscapeClearsTheFilterInsteadOfQuitting(): void
    {
        $table = $this->table();

        $table->handle(new KeyPress(Key::Char, '/'));
        $table->handle(new KeyPress(Key::Char, 'z'));
        self::assertTrue($table->handle(new KeyPress(Key::Escape)));

        self::assertStringContainsString('Alpha', $this->plain($table->render(80, 12)));
    }

    public function testCharactersAreTypedIntoTheFilterRatherThanTriggeringShortcuts(): void
    {
        $table = $this->table();
        $table->handle(new KeyPress(Key::Char, '/'));

        // 'q' quits the table, but while filtering it is just a character.
        self::assertTrue($table->handle(new KeyPress(Key::Char, 'q')));
    }

    public function testQuitReturnsFalse(): void
    {
        self::assertFalse($this->table()->handle(new KeyPress(Key::Char, 'q')));
    }

    public function testEnterOpensAnOverlayThatReceivesTheKeys(): void
    {
        $overlay = new class () implements Component {
            public int $handled = 0;

            public function render(int $width, int $height): array
            {
                return ['OVERLAY'];
            }

            public function handle(KeyPress $key): bool
            {
                $this->handled++;

                return !$key->isChar('q');
            }
        };

        $table = $this->table(onSelect: static fn (array $row): Component => $overlay);

        $table->handle(new KeyPress(Key::Enter));
        self::assertSame(['OVERLAY'], $table->render(80, 12));

        // Keys go to the overlay until it closes, then back to the table.
        $table->handle(new KeyPress(Key::Down));
        self::assertSame(1, $overlay->handled);

        self::assertTrue($table->handle(new KeyPress(Key::Char, 'q')));
        self::assertStringContainsString('Alpha', $this->plain($table->render(80, 12)));
    }

    public function testEmptyPageRendersAndIsSafeToInteractWith(): void
    {
        $table = new DataTable(new Page([], 1, 1, 0), self::COLUMNS, 'Plugins', 'plugins');

        self::assertStringContainsString('No results', $this->plain($table->render(80, 12)));

        // Moving or selecting with nothing to point at must not blow up.
        self::assertTrue($table->handle(new KeyPress(Key::Down)));
        self::assertTrue($table->handle(new KeyPress(Key::Enter)));
        self::assertStringContainsString('Page 1/1', $this->plain($table->render(80, 12)));
    }

    public function testFilterMatchingNothingSaysSoInsteadOfLookingEmpty(): void
    {
        $table = $this->table();

        $table->handle(new KeyPress(Key::Char, '/'));
        $table->handle(new KeyPress(Key::Char, 'z'));

        $frame = $this->plain($table->render(80, 12));

        self::assertStringContainsString('No match on this page', $frame);
        self::assertStringContainsString('0 matches on this page', $frame);
    }

    public function testValuesContainingMarkupAreNotSwallowed(): void
    {
        $page = new Page([['name' => 'Needs PHP <8.0', 'slug' => 'legacy', 'version' => '1.0']], 1, 1, 1);
        $table = new DataTable($page, self::COLUMNS, 'Plugins', 'plugins');

        self::assertStringContainsString('Needs PHP <8.0', $this->plain($table->render(80, 12)));
    }

    public function testLongListScrollsInsteadOfOverflowingTheViewport(): void
    {
        $rows = [];
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = ['name' => "Plugin $i", 'slug' => "plugin-$i", 'version' => '1.0'];
        }

        $table = new DataTable(new Page($rows, 1, 1, 40), self::COLUMNS, 'Plugins', 'plugins');

        for ($i = 0; $i < 39; $i++) {
            $table->handle(new KeyPress(Key::Down));
        }

        $lines = $table->render(80, 12);

        self::assertLessThanOrEqual(12, count($lines), 'a frame must fit its viewport');
        self::assertStringContainsString('Plugin 40', $this->plain($lines));
        self::assertStringNotContainsString('Plugin 1 ', $this->plain($lines));
    }

    /**
     * @param (callable(int): ?Page)|null                       $loadPage
     * @param (callable(array<string, mixed>): ?Component)|null $onSelect
     */
    private function table(?callable $loadPage = null, ?callable $onSelect = null): DataTable
    {
        $page = new Page([
            ['name' => 'Alpha', 'slug' => 'alpha', 'version' => '1.0'],
            ['name' => 'Beta', 'slug' => 'beta', 'version' => '2.0'],
            ['name' => 'Gamma', 'slug' => 'gamma', 'version' => '3.0'],
        ], 1, 3, 67);

        return new DataTable($page, self::COLUMNS, 'Plugins', 'plugins', $loadPage, $onSelect);
    }

    /** Assert the cursor sits on the row whose name cell is $name. */
    private static function assertCursorRow(string $name, string $renderedRow): void
    {
        self::assertStringStartsWith("▸ $name ", $renderedRow);
    }

    /** The highlighted line, with column padding collapsed so it reads as one row. */
    private function cursorLine(DataTable $table): string
    {
        foreach ($table->render(80, 12) as $line) {
            $plain = $this->plain([$line]);
            if (str_starts_with($plain, '▸ ')) {
                return trim((string) preg_replace('/\s+/', ' ', $plain));
            }
        }

        return '';
    }

    /**
     * Render lines the way a terminal would: this both resolves the formatting
     * tags (so an invalid one would blow up here) and yields the visible text.
     *
     * @param list<string> $lines
     */
    private function plain(array $lines): string
    {
        $formatter = new OutputFormatter(false);

        return implode("\n", array_map(static fn (string $line): string => $formatter->format($line) ?? '', $lines));
    }
}
