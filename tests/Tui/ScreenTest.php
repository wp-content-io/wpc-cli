<?php

namespace WpContent\Cli\Tests\Tui;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use WpContent\Cli\Tests\EnvGuardTrait;
use WpContent\Cli\Tests\FakeTerminal;
use WpContent\Cli\Tui\Screen;

/**
 * The size a frame is drawn for, and the size the section it is drawn into
 * believes in, are the same size.
 */
final class ScreenTest extends TestCase
{
    use EnvGuardTrait;

    #[After]
    public function putTheEnvironmentBack(): void
    {
        $this->restoreEnv();
    }

    /**
     * `ConsoleSectionOutput` sizes every line it accounts for with
     * `Terminal::getWidth()`, which reads `COLUMNS` before anything else — a
     * variable Symfony's `Application::run()` exports once at startup. `Screen`
     * reads `stty size` itself, precisely so a resize mid-session is seen, and
     * used to keep the answer to itself: the section then counted several rows
     * for each line it occupied one of, moved the cursor up that many, and
     * erased to the end of the screen, taking the scrollback with it.
     */
    public function testTheSizeItReadsIsTheSizeTheSectionSees(): void
    {
        $this->setEnv('COLUMNS', null);
        $this->setEnv('LINES', null);

        $screen = new Screen(new FakeTerminal());

        self::assertNotFalse(getenv('COLUMNS'), 'the section has no other way to be told');
        self::assertNotFalse(getenv('LINES'));

        // width() hands back one column less than the terminal has, so a line
        // filling it cannot wrap; height() keeps one row for the same reason.
        self::assertSame($screen->width() + 1, (int) getenv('COLUMNS'));
        self::assertSame($screen->height() + 1, (int) getenv('LINES'));
    }
}
