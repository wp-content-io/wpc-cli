<?php

namespace WpContent\Cli\Results;

use WpContent\Cli\Tui\Screen;

/**
 * A result that can additionally take over the terminal.
 *
 * Kept separate from {@see CommandOutput} on purpose: errors and one-shot
 * results only ever need json()/human(), and should not have to pretend to be
 * interactive. When a result implements this, the application still calls
 * human() afterwards, so whatever state the user navigated to is left printed
 * behind the closed TUI.
 */
interface InteractiveOutput extends CommandOutput
{
    public function interactive(Screen $screen): void;
}
