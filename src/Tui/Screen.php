<?php

namespace WpContent\Cli\Tui;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Terminal;

/**
 * The TUI event loop and its drawing surface.
 *
 * Frames are painted into a {@see ConsoleSectionOutput}, so each redraw rewrites
 * only the block the TUI owns instead of repainting the whole terminal — the
 * user's scrollback above it is left untouched. On exit the block is cleared,
 * which is what lets the caller print the plain rendering in its place.
 */
final class Screen
{
    /**
     * Rows kept free below the frame: writing on the very last row of a terminal
     * scrolls it, which would desynchronise the section's line accounting.
     */
    private const RESERVED_ROWS = 1;

    /**
     * How long the terminal size is trusted before asking again.
     *
     * Asking costs a `stty size` — a fork, ~5 ms — and the loop repaints on
     * every keystroke, so asking each time put that on every character typed.
     * A resize still lands within half a second, which no one can perceive.
     */
    private const SIZE_TTL_MILLISECONDS = 500;

    private readonly ConsoleSectionOutput $section;

    private readonly RawMode $rawMode;

    private readonly KeyReader $reader;

    private int $width = 80;

    private int $height = 24;

    private float $sizeReadAt = 0.0;

    public function __construct(
        private readonly ConsoleOutputInterface $output,
        ?KeyReader $reader = null,
    ) {
        $this->section = $output->section();
        $this->rawMode = new RawMode($output);
        $this->reader = $reader ?? new KeyReader();
        $this->refreshDimensions(true);
    }

    /**
     * Columns a frame may use — one short of the terminal on purpose: a line
     * filling the very last column wraps, which would make the section clear
     * fewer lines than it drew and leave debris behind on exit.
     */
    public function width(): int
    {
        return max(1, $this->width - 1);
    }

    /** Number of rows a frame may use. */
    public function height(): int
    {
        return max(1, $this->height - self::RESERVED_ROWS);
    }

    /**
     * Run the event loop until the input ends, Ctrl+C is pressed, or $onKey
     * returns false.
     *
     * @param callable(): list<string> $render draws the current state
     * @param callable(KeyPress): bool $onKey  handles a keystroke; false quits
     *
     * @return bool false when the loop was interrupted (Ctrl+C or end of input)
     *              rather than closed by $onKey — prompts need to tell a
     *              cancellation from an answer
     */
    public function run(callable $render, callable $onKey): bool
    {
        $this->rawMode->enable();
        $completed = false;

        try {
            while (true) {
                $this->refreshDimensions();
                $this->paint($render());

                $key = $this->reader->read();

                if ($key === null || $key->is(Key::CtrlC)) {
                    break;
                }

                if ($onKey($key) === false) {
                    $completed = true;

                    break;
                }
            }
        } finally {
            // Clear before restoring: disable() shows the cursor again by writing
            // straight to the stream, and anything written outside the section
            // throws off its line accounting, leaving the last frame behind.
            $this->section->clear();
            $this->rawMode->disable();
        }

        return $completed;
    }

    /**
     * Paint one frame. Lines are capped to the viewport height; their width is
     * the components' responsibility, since truncating here could cut a line in
     * the middle of a formatting tag.
     *
     * @param list<string> $lines
     */
    private function paint(array $lines): void
    {
        $this->section->overwrite(array_slice($lines, 0, $this->height()));
    }

    /**
     * Re-read the terminal size every frame so a resize mid-session is picked up.
     * Symfony's Terminal caches its dimensions in static properties, so `stty
     * size` is asked directly and Terminal is only the fallback.
     */
    private function refreshDimensions(bool $force = false): void
    {
        $now = hrtime(true) / 1e6;

        if (!$force && $now - $this->sizeReadAt < self::SIZE_TTL_MILLISECONDS) {
            return;
        }

        $this->sizeReadAt = $now;
        $previousHeight = $this->height();
        $previousWidth = $this->width;

        $size = shell_exec('stty size 2>/dev/null');

        if (is_string($size)
            && preg_match('/^(\d+)\s+(\d+)$/', trim($size), $matches)
            && (int) $matches[1] > 0
            && (int) $matches[2] > 0
        ) {
            $this->height = (int) $matches[1];
            $this->width = (int) $matches[2];
        } else {
            $terminal = new Terminal();
            $this->width = $terminal->getWidth();
            $this->height = $terminal->getHeight();
        }

        // On the first read too, not only on a change: the properties start at
        // 80x24, which is also what Terminal falls back to, so a terminal that
        // size would never have published anything at all.
        if ($force || $this->width !== $previousWidth || $this->height() !== $previousHeight) {
            $this->publishDimensions();
        }

        if ($this->height() !== $previousHeight) {
            $this->section->setMaxHeight($this->height());
        }
    }

    /**
     * Hand the new size to the section we paint into.
     *
     * `ConsoleSectionOutput` sizes every line it accounts for with
     * `Terminal::getWidth()`, which reads `COLUMNS` before anything else — a
     * variable Symfony's `Application::run()` exports once at startup and that
     * nothing else refreshes. Reading `stty size` here without republishing it
     * left the section measuring a resized terminal against the old width: it
     * counted several rows for each line it occupied one of, moved the cursor
     * up that many, and erased to the end of the screen — taking the scrollback
     * above the TUI with it.
     */
    private function publishDimensions(): void
    {
        if (!function_exists('putenv')) {
            return;
        }

        @putenv('COLUMNS=' . $this->width);
        @putenv('LINES=' . $this->height);
    }
}
