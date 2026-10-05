<?php

namespace WpContent\Cli\Tui\Component;

use WpContent\Cli\Tui\Key;
use WpContent\Cli\Tui\KeyPress;
use WpContent\Cli\Tui\Page;
use WpContent\Cli\Tui\Text;
use WpContent\Cli\Tui\Theme;

/**
 * The scrollable, paginated list at the heart of `plugin list` / `theme list`.
 *
 * Pages are pulled through a loader as the user walks into them rather than
 * fetched up front, so opening the list stays one request no matter how many
 * plugins the organization has.
 */
final class DataTable implements Component
{
    /** Rows reserved for the title, the column header, the rule and the footer. */
    private const CHROME_ROWS = 4;

    private const MIN_COLUMN_WIDTH = 6;

    private const MAX_COLUMN_WIDTH = 40;

    private const CURSOR_MARKER = '▸ ';

    private int $cursor = 0;

    private int $scroll = 0;

    /** Applied filter; empty means no filtering. */
    private string $filter = '';

    /** Whether keystrokes are currently editing the filter. */
    private bool $filtering = false;

    private string $status = '';

    private ?Component $overlay = null;

    /**
     * @param list<string>                       $columns
     * @param (callable(int): ?Page)|null        $loadPage  fetches another page
     * @param (callable(array<string, mixed>): ?Component)|null $onSelect opens a detail view
     */
    public function __construct(
        private Page $page,
        private readonly array $columns,
        private readonly string $title,
        private readonly string $itemLabel,
        private $loadPage = null,
        private $onSelect = null,
    ) {
    }

    /** The page currently on screen, so the caller can print it once the TUI closes. */
    public function page(): Page
    {
        return $this->page;
    }

    public function render(int $width, int $height): array
    {
        if ($this->overlay !== null) {
            return $this->overlay->render($width, $height);
        }

        $rows = $this->visibleRows();
        $bodyHeight = max(1, $height - self::CHROME_ROWS);
        $this->clampCursor(count($rows), $bodyHeight);

        $widths = $this->columnWidths($rows, $width);

        $lines = [$this->titleLine($width)];
        $lines[] = $this->headerLine($widths);

        if ($rows === []) {
            $lines[] = '  <fg=gray>' . ($this->filter !== '' ? 'No match on this page' : 'No results') . '</>';
        }

        foreach (array_slice($rows, $this->scroll, $bodyHeight, true) as $index => $row) {
            $lines[] = $this->rowLine($row, $widths, $index === $this->cursor, $width);
        }

        $lines[] = '<fg=gray>' . str_repeat('─', max(0, $width)) . '</>';
        $lines[] = $this->footerLine();

        return $lines;
    }

    public function handle(KeyPress $key): bool
    {
        if ($this->overlay !== null) {
            if (!$this->overlay->handle($key)) {
                $this->overlay = null;
            }

            return true;
        }

        $this->status = '';

        if ($this->filtering) {
            return $this->handleFilterInput($key);
        }

        return match (true) {
            $key->is(Key::Up) => $this->moveCursor(-1),
            $key->is(Key::Down) => $this->moveCursor(1),
            $key->is(Key::Home) => $this->moveCursorTo(0),
            $key->is(Key::End) => $this->moveCursorTo(PHP_INT_MAX),
            $key->is(Key::Left, Key::PageUp) => $this->goToPage($this->page->number - 1),
            $key->is(Key::Right, Key::PageDown) => $this->goToPage($this->page->number + 1),
            $key->is(Key::Enter) => $this->select(),
            $key->isChar('/') => $this->startFiltering(),
            $key->isChar('q'), $key->is(Key::Escape) => false,
            default => true,
        };
    }

    private function handleFilterInput(KeyPress $key): bool
    {
        if ($key->is(Key::Enter)) {
            $this->filtering = false;

            return true;
        }

        if ($key->is(Key::Escape)) {
            $this->filtering = false;
            $this->filter = '';
            $this->resetCursor();

            return true;
        }

        if ($key->is(Key::Backspace)) {
            $this->filter = mb_substr($this->filter, 0, -1);
            $this->resetCursor();

            return true;
        }

        if ($key->isPrintable()) {
            $this->filter .= $key->char;
            $this->resetCursor();
        }

        return true;
    }

    private function startFiltering(): bool
    {
        $this->filtering = true;

        return true;
    }

    private function select(): bool
    {
        $rows = $this->visibleRows();
        $row = array_values($rows)[$this->cursor] ?? null;

        if ($row === null || $this->onSelect === null) {
            return true;
        }

        $this->overlay = ($this->onSelect)($row);

        return true;
    }

    private function moveCursor(int $delta): bool
    {
        return $this->moveCursorTo($this->cursor + $delta);
    }

    private function moveCursorTo(int $position): bool
    {
        $this->cursor = max(0, min($position, max(0, count($this->visibleRows()) - 1)));

        return true;
    }

    private function goToPage(int $number): bool
    {
        if ($this->loadPage === null) {
            return true;
        }

        if ($number < 1 || $number > $this->page->pages) {
            $this->status = $number < 1 ? 'Already on the first page' : 'Already on the last page';

            return true;
        }

        $page = ($this->loadPage)($number);

        if ($page === null) {
            $this->status = 'Could not load page ' . $number;

            return true;
        }

        $this->page = $page;
        $this->filter = '';
        $this->resetCursor();

        return true;
    }

    private function resetCursor(): void
    {
        $this->cursor = 0;
        $this->scroll = 0;
    }

    /**
     * Keep the cursor inside the list and the viewport following it.
     */
    private function clampCursor(int $rowCount, int $bodyHeight): void
    {
        $this->cursor = max(0, min($this->cursor, max(0, $rowCount - 1)));

        if ($this->cursor < $this->scroll) {
            $this->scroll = $this->cursor;
        } elseif ($this->cursor >= $this->scroll + $bodyHeight) {
            $this->scroll = $this->cursor - $bodyHeight + 1;
        }

        $this->scroll = max(0, min($this->scroll, max(0, $rowCount - $bodyHeight)));
    }

    /**
     * Rows matching the filter. The API exposes no search parameter, so this
     * only ever narrows the page already in memory — the footer says so.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleRows(): array
    {
        if ($this->filter === '') {
            return $this->page->rows;
        }

        $needle = mb_strtolower($this->filter);

        return array_values(array_filter(
            $this->page->rows,
            function (array $row) use ($needle): bool {
                foreach ($this->columns as $column) {
                    $value = Text::display($row[$column] ?? null);
                    if ($value !== null && str_contains(mb_strtolower($value), $needle)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    /**
     * Give every column the width its content needs, then shave the widest ones
     * until the whole table fits the terminal.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function columnWidths(array $rows, int $terminalWidth): array
    {
        $widths = [];
        foreach ($this->columns as $column) {
            // valueWidth, not width: these are values, and a name carrying
            // something that looks like a tag takes the columns it prints.
            $widest = Text::valueWidth($column);
            foreach ($rows as $row) {
                $widest = max($widest, Text::valueWidth(Text::singleLine(Text::display($row[$column] ?? null) ?? '')));
            }
            $widths[$column] = min($widest, self::MAX_COLUMN_WIDTH);
        }

        $gaps = max(0, count($this->columns) - 1);
        $available = $terminalWidth - Text::width(self::CURSOR_MARKER) - $gaps;

        while (array_sum($widths) > $available) {
            $widest = array_search(max($widths), $widths, true);
            if (!is_string($widest) || $widths[$widest] <= self::MIN_COLUMN_WIDTH) {
                break;
            }
            $widths[$widest]--;
        }

        return $widths;
    }

    private function titleLine(int $width): string
    {
        $title = ' ' . $this->title . ' ';
        $rule = str_repeat('─', max(0, $width - Text::width($title) - 2));

        return sprintf('<fg=gray>──</><options=bold>%s</><fg=gray>%s</>', Text::escape($title), $rule);
    }

    /**
     * @param array<string, int> $widths
     */
    private function headerLine(array $widths): string
    {
        $cells = [];
        foreach ($widths as $column => $columnWidth) {
            $cells[] = Text::cell(strtoupper(str_replace('_', ' ', $column)), $columnWidth);
        }

        return '<fg=gray;options=bold>' . str_repeat(' ', Text::width(self::CURSOR_MARKER))
            . implode(' ', $cells) . '</>';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int>   $widths
     */
    private function rowLine(array $row, array $widths, bool $selected, int $terminalWidth): string
    {
        $cells = [];
        foreach ($widths as $column => $columnWidth) {
            $cells[] = Text::cell(Text::display($row[$column] ?? null) ?? '', $columnWidth);
        }

        $marker = $selected ? self::CURSOR_MARKER : str_repeat(' ', Text::width(self::CURSOR_MARKER));
        $line = $marker . implode(' ', $cells);

        if (!$selected) {
            return $line;
        }

        // Pad the highlight across the terminal so the selection reads as a band.
        $line .= str_repeat(' ', max(0, $terminalWidth - Text::width($line)));

        return Theme::selected($line);
    }

    private function footerLine(): string
    {
        if ($this->filtering || $this->filter !== '') {
            $matches = count($this->visibleRows());

            // The query stays on the terminal's own foreground: it is what the
            // user typed, and it has to be readable on any theme.
            return sprintf(
                '/%s%s  %s',
                Text::escape($this->filter),
                $this->filtering ? Theme::caret() : '',
                Theme::muted(sprintf(
                    '%d match%s on this page · ⏎ apply · esc clear',
                    $matches,
                    $matches === 1 ? '' : 'es'
                ))
            );
        }

        if ($this->status !== '') {
            return '<comment>' . Text::escape($this->status) . '</>';
        }

        return sprintf(
            '<fg=gray>Page %d/%d · %d %s · ↑↓ move · ←→ page · ⏎ details · / filter · q quit</>',
            $this->page->number,
            $this->page->pages,
            $this->page->total,
            $this->itemLabel
        );
    }
}
