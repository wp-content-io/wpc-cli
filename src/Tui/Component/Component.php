<?php

namespace WpContent\Cli\Tui\Component;

use WpContent\Cli\Tui\KeyPress;

/**
 * A drawable, keyboard-driven piece of the TUI.
 *
 * Components compose by overlaying: a list opens a detail view on top of itself
 * and forwards both drawing and keystrokes to it until it closes.
 */
interface Component
{
    /**
     * Draw the current state into at most $height lines of $width columns.
     *
     * @return list<string>
     */
    public function render(int $width, int $height): array;

    /**
     * Handle a keystroke. Returning false closes the component — which quits the
     * TUI for a top-level component, or dismisses an overlay.
     */
    public function handle(KeyPress $key): bool;
}
