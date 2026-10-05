<?php

namespace WpContent\Cli\Tui;

use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Puts the terminal in raw mode for the duration of a TUI, and — this is the
 * part that matters — guarantees it is put back however the process ends.
 *
 * Leaving a terminal in raw mode means no echo and no visible cursor for every
 * later command the user types, so restoration is defended three ways:
 *
 *  1. `-isig` makes Ctrl+C arrive as a plain \x03 byte instead of a signal, so
 *     the event loop sees it as {@see Key::CtrlC} and unwinds normally. This is
 *     the only defence that works without ext-pcntl, which the phar cannot
 *     assume (the Docker image only installs ext-zip).
 *  2. A shutdown function catches exits and uncaught exceptions.
 *  3. A SIGINT/SIGTERM handler, when ext-pcntl happens to be available, covers
 *     signals sent from outside the terminal (e.g. `kill`).
 */
final class RawMode
{
    /** @var list<self> */
    private static array $active = [];

    private static bool $hooksRegistered = false;

    /**
     * The terminal's settings as we found them, read once.
     *
     * Also a correctness fix, not just one fork saved per prompt: asking again
     * while a TUI is already up would read back the *raw* mode and restore that.
     */
    private static ?string $pristineSttyMode = null;

    private ?string $originalSttyMode = null;

    private readonly Cursor $cursor;

    public function __construct(private readonly OutputInterface $output)
    {
        $this->cursor = new Cursor($output);
    }

    public static function isSupported(): bool
    {
        return Terminal::hasSttyAvailable();
    }

    public function enable(): void
    {
        if ($this->originalSttyMode !== null) {
            return;
        }

        if (self::$pristineSttyMode === null) {
            $mode = shell_exec('stty -g 2>/dev/null');
            self::$pristineSttyMode = is_string($mode) ? trim($mode) : '';
        }

        if (self::$pristineSttyMode === '') {
            return;
        }

        $this->originalSttyMode = self::$pristineSttyMode;
        shell_exec('stty -icanon -echo -isig 2>/dev/null');
        $this->cursor->hide();

        self::$active[] = $this;
        self::registerHooks();
    }

    public function disable(): void
    {
        if ($this->originalSttyMode === null) {
            return;
        }

        $this->cursor->show();
        shell_exec('stty ' . $this->originalSttyMode . ' 2>/dev/null');
        $this->originalSttyMode = null;

        self::$active = array_values(array_filter(self::$active, fn (self $mode): bool => $mode !== $this));
    }

    private static function registerHooks(): void
    {
        if (self::$hooksRegistered) {
            return;
        }

        self::$hooksRegistered = true;

        register_shutdown_function(static function (): void {
            self::restoreAll();
        });

        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([\SIGINT, \SIGTERM] as $signal) {
            pcntl_signal($signal, static function (int $signal): void {
                self::restoreAll();

                exit(128 + $signal);
            });
        }
    }

    private static function restoreAll(): void
    {
        foreach (self::$active as $mode) {
            $mode->disable();
        }
    }
}
