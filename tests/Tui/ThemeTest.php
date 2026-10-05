<?php

namespace WpContent\Cli\Tests\Tui;

use PHPUnit\Framework\TestCase;
use WpContent\Cli\Tests\EnvGuardTrait;
use WpContent\Cli\Tui\Theme;

final class ThemeTest extends TestCase
{
    use EnvGuardTrait;

    protected function setUp(): void
    {
        // Cleared in all three sources, and read back the same way: setting the
        // accent with putenv() alone left every one of these tests at the mercy
        // of a WPC_ACCENT_COLOR genuinely exported by the shell running them.
        $this->setEnv(Theme::ACCENT_ENV, null);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    private function accent(string $value): void
    {
        $this->setEnv(Theme::ACCENT_ENV, $value);
    }

    public function testDefaultsToTheBrandColour(): void
    {
        self::assertSame(Theme::BRAND_ACCENT, Theme::accent());
        self::assertSame('#4433db', Theme::accent(), 'accent must track the dashboard --mat-sys-primary');
    }

    public function testOnlyTheSelectionIsColoured(): void
    {
        // The accent belongs to what the cursor is on. Chrome stays on the
        // terminal's own palette, so the CLI does not fight the user's theme.
        self::assertStringContainsString(Theme::BRAND_ACCENT, Theme::selected('row'));
        self::assertStringContainsString('bg=', Theme::selected('row'));

        self::assertStringNotContainsString('bg=', Theme::muted('footer'));
    }

    /**
     * The accent is a button colour: dark, made to sit *behind* white text.
     * Used as a foreground it lands dark-on-dark in any dark terminal — which
     * is exactly how the text being typed once became invisible over SSH.
     */
    public function testTheAccentIsOnlyEverUsedAsABackground(): void
    {
        foreach ([Theme::selected('row'), Theme::caret()] as $rendered) {
            self::assertStringContainsString('bg=' . Theme::accent(), $rendered);
            self::assertStringNotContainsString('fg=' . Theme::accent(), $rendered);
        }
    }

    public function testTheCaretIsAFilledBlockRatherThanAGlyph(): void
    {
        // A lit space shows up whatever the terminal's background is; a "█"
        // painted in the accent does not.
        self::assertStringContainsString(' ', Theme::caret());
        self::assertStringNotContainsString('█', Theme::caret());
    }

    public function testHexAccentIsHonoured(): void
    {
        $this->accent('#E11D48');

        self::assertSame('#e11d48', Theme::accent());
        self::assertStringContainsString('#e11d48', Theme::selected('row'));
    }

    public function testNamedAnsiAccentIsHonoured(): void
    {
        $this->accent('cyan');

        self::assertSame('cyan', Theme::accent());
    }

    public function testUnusableAccentFallsBackInsteadOfThrowing(): void
    {
        // A typo in an env var must not take the CLI down.
        foreach (['not-a-colour', '#12345', 'rgb(1,2,3)', ''] as $value) {
            $this->accent($value);

            self::assertSame(Theme::BRAND_ACCENT, Theme::accent(), "\"$value\" should be ignored");
        }
    }

    public function testTextOnAccentFollowsItsBrightness(): void
    {
        // Dark brand colour → white text.
        self::assertSame('white', Theme::onAccent());

        // A light accent must flip the text to black to stay readable.
        $this->accent('#facc15');
        self::assertSame('black', Theme::onAccent());
    }

    /**
     * Every named accent used to answer `white`, including the three the
     * whitelist accepts that *are* white: the selection band came out white on
     * white, so the row the cursor was on could not be told from the others.
     */
    public function testANamedAccentNeverPutsTextInItsOwnColour(): void
    {
        foreach (['white', 'bright-white', 'bright-yellow', 'cyan', 'blue', 'gray'] as $colour) {
            $this->accent($colour);

            self::assertNotSame($colour, Theme::onAccent(), "\"$colour\" text on \"$colour\" is invisible");
            self::assertStringContainsString(
                sprintf('<fg=%s;bg=%s>', Theme::onAccent(), $colour),
                Theme::selected('row')
            );
        }

        // Except `default`, which is the terminal's own pair: its background,
        // and therefore its foreground.
        $this->accent('default');
        self::assertSame('default', Theme::onAccent());
    }

    public function testShorthandHexIsAccepted(): void
    {
        $this->accent('#fff');

        self::assertSame('#fff', Theme::accent());
        self::assertSame('black', Theme::onAccent());
    }

    public function testAccentIsWhitespaceAndCaseInsensitive(): void
    {
        $this->accent('  #4433DB  ');

        self::assertSame('#4433db', Theme::accent());
    }
}
