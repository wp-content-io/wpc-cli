<?php

namespace WpContent\Cli\Tests\Tui;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use WpContent\Cli\Tui\Text;

final class TextTest extends TestCase
{
    /**
     * A theme's author is an object; it is shown by its name, and a record
     * with nothing to be called by is left to the caller.
     */
    public function testARecordIsDisplayedByItsName(): void
    {
        self::assertSame('Jane Doe', Text::display(['user_nicename' => 'jane-doe', 'display_name' => 'Jane Doe', 'avatar' => '']));
        self::assertSame('Acme', Text::display(['name' => 'Acme', 'url' => 'https://acme.test']));
        self::assertSame('42', Text::display(42));
        self::assertNull(Text::display(['default' => 'https://acme.test/icon.png']));
        self::assertNull(Text::display(['display_name' => '']));
        self::assertNull(Text::display(null));
    }

    public function testTagsAreStrippedAndBlocksBecomeLineBreaks(): void
    {
        $plain = Text::plain('<p>First paragraph</p><p>Second <strong>paragraph</strong></p>');

        self::assertSame("First paragraph\nSecond paragraph", $plain);
    }

    public function testBareLessThanSurvivesEvenWhenATagFollows(): void
    {
        // '<[^>]+>' would have eaten everything from '<8.2' up to the '</p>',
        // silently truncating the description.
        $plain = Text::plain('<p>Requires PHP <8.2, and a recent MySQL</p>');

        self::assertSame('Requires PHP <8.2, and a recent MySQL', $plain);
    }

    public function testTagsWithAttributesAreStripped(): void
    {
        $plain = Text::plain('<p class="intro">See <a href="https://example.test">the docs</a></p>');

        self::assertSame('See the docs', $plain);
    }

    /**
     * A value is data, whatever it looks like.
     *
     * Sized with the *rendered* width, `<info>` counted as zero columns: the
     * cell was never truncated, padded to full width on top, and then printed
     * literally at twice it — every column after it out of alignment.
     */
    public function testACellCarryingSomethingTagShapedKeepsItsWidth(): void
    {
        foreach (['<info>x</info>', '<fg=red>hi</>', 'PHP <8.2 only'] as $value) {
            $rendered = (new OutputFormatter(false))->format(Text::cell($value, 12));

            self::assertSame(12, Helper::width($rendered), "\"$value\" must occupy 12 columns");
        }
    }

    public function testAValueIsMeasuredAsItPrints(): void
    {
        // width() is for chrome — a line the TUI assembled, where a tag is markup.
        self::assertSame(1, Text::width('<info>x</info>'));
        self::assertSame(14, Text::valueWidth('<info>x</info>'));
    }

    /**
     * `/u` patterns return null on the first invalid byte, and the casts turned
     * that into an empty string: the wizard printed a blank recap for a value it
     * had none the less written to disk.
     */
    public function testAnInvalidByteCostsItselfAndNotTheWholeValue(): void
    {
        $mangled = "abc\xC3defghij";

        self::assertStringContainsString('abc', Text::singleLine($mangled));
        self::assertStringContainsString('defghij', Text::singleLine($mangled));
        self::assertNotSame([], Text::wrap($mangled, 20));
        self::assertNotSame(Text::ELLIPSIS, Text::truncate($mangled, 5));
    }

    public function testCellIsPaddedToItsColumnWidth(): void
    {
        self::assertSame('ab   ', Text::cell('ab', 5));
        self::assertSame(5, Text::width(Text::cell('ab', 5)));
    }

    public function testCellTruncatesWithAnEllipsis(): void
    {
        self::assertSame('abc…', Text::cell('abcdefgh', 4));
        self::assertSame(4, Text::width(Text::cell('abcdefgh', 4)));
    }

    public function testCellWidthIsMeasuredInColumnsNotBytes(): void
    {
        // Accented characters are multi-byte but single-column.
        self::assertSame(6, Text::width(Text::cell('éàü', 6)));
    }

    public function testCellEscapesMarkupAfterSizing(): void
    {
        // The escaping backslash must not count towards the column width.
        $cell = Text::cell('<8.0', 6);

        self::assertStringContainsString('\\<8.0', $cell);
    }

    public function testMultilineValuesAreCollapsedIntoOneCell(): void
    {
        self::assertSame('a b c', Text::singleLine("a\n b\t\tc"));
    }

    public function testWrapKeepsWordsWhole(): void
    {
        self::assertSame(['one two', 'three'], Text::wrap('one two three', 8));
    }

    public function testWrapPreservesExistingLineBreaks(): void
    {
        self::assertSame(['first', 'second'], Text::wrap("first\nsecond", 20));
    }

    public function testWrapSplitsAWordLongerThanTheViewport(): void
    {
        $lines = Text::wrap('abcdefghij', 4);

        self::assertSame(['abcd', 'efgh', 'ij'], $lines);
    }

    /**
     * A viewport narrower than a single glyph.
     *
     * A double-width character could not be split down to the requested width,
     * so the hard-split returned nothing, the word was never shortened, and the
     * loop ran forever — with the terminal already in raw mode and Ctrl+C turned
     * into a byte nobody was left to read. Overflowing the column is the only
     * answer that terminates.
     */
    public function testWrapMakesProgressOnGlyphsWiderThanTheViewport(): void
    {
        self::assertSame(['漢', '字'], Text::wrap('漢字', 1));
        self::assertSame(['🎉', '🎉'], Text::wrap('🎉🎉', 1));
    }

    public function testWrapFitsDoubleWidthGlyphsWhenItCan(): void
    {
        self::assertSame(['漢字', '漢'], Text::wrap('漢字漢', 4));
    }

    public function testWrapReturnsNothingForANonSensicalWidth(): void
    {
        self::assertSame([], Text::wrap('anything', 0));
    }
}
