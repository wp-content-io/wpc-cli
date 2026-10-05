<?php

namespace WpContent\Cli\Tests\Tui;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use WpContent\Cli\Tests\FakeTerminal;
use WpContent\Cli\Tui\Cancelled;
use WpContent\Cli\Tui\KeyReader;
use WpContent\Cli\Tui\Prompt;
use WpContent\Cli\Tui\Theme;

/**
 * The prompts, driven from a stream instead of a terminal.
 *
 * What matters here is what a prompt does when it is *not* answered: backing
 * out has to unwind the whole wizard, never quietly hand back the default and
 * let the next eleven questions run.
 */
final class PromptTest extends TestCase
{
    public function testEnterAnswersWithWhatWasTyped(): void
    {
        self::assertSame('acme-analytics', $this->prompt("acme-analytics\r")->text('Plugin name'));
    }

    public function testTypingIsEditable(): void
    {
        self::assertSame('acme', $this->prompt("acmex\x7f\r")->text('Plugin name'));
    }

    public function testTheDefaultIsKeptWhenNothingIsTyped(): void
    {
        self::assertSame('1.0.0', $this->prompt("\r")->text('Version', '1.0.0'));
    }

    public function testEscapeCancelsRatherThanAcceptingTheDefault(): void
    {
        $this->expectException(Cancelled::class);

        $this->prompt("acme\e")->text('Plugin name', 'fallback');
    }

    public function testCtrlCCancels(): void
    {
        $this->expectException(Cancelled::class);

        $this->prompt("acme\x03")->text('Plugin name');
    }

    public function testInputEndingCancels(): void
    {
        // stdin closing under us is not an answer either.
        $this->expectException(Cancelled::class);

        $this->prompt('')->text('Plugin name', 'fallback');
    }

    public function testEscapeCancelsAConfirmation(): void
    {
        $this->expectException(Cancelled::class);

        $this->prompt("\e")->confirm('Continue?', true);
    }

    public function testEscapeCancelsAMultiselect(): void
    {
        $this->expectException(Cancelled::class);

        $this->prompt("\e")->multiselect('Headers', ['Author', 'License'], ['Author']);
    }

    public function testMultiselectTicksWithSpaceAndAnswersOnEnter(): void
    {
        // Author is preselected; space unticks it, down + space ticks License.
        $chosen = $this->prompt(" \e[B \r")->multiselect('Headers', ['Author', 'License'], ['Author']);

        self::assertSame(['License'], $chosen);
    }

    /**
     * The frames, not just the answer.
     *
     * The render callback used to be an arrow function, and `fn` captures by
     * value: every frame redrew the answer as it was before the first keystroke,
     * so typing showed nothing at all. Asserting only the return value missed it
     * completely — the prompt returned the right string while displaying none of it.
     */
    public function testWhatIsTypedIsDrawnAsItIsTyped(): void
    {
        $terminal = new FakeTerminal();
        $this->prompt("Acme\r", $terminal)->text('Plugin name');

        $painted = $terminal->paint();

        foreach (['A', 'Ac', 'Acm', 'Acme'] as $step) {
            self::assertStringContainsString("  $step", $painted, "\"$step\" was never drawn");
        }
    }

    public function testTypedTextIsLeftOnTheTerminalsOwnForeground(): void
    {
        // The accent is a dark button colour; as a foreground it vanishes into
        // a dark terminal. What the user types must never carry it.
        $terminal = new FakeTerminal();
        $this->prompt("Acme\r", $terminal)->text('Plugin name');

        self::assertStringNotContainsString($this->rendered(sprintf('<fg=%s>Acme</>', Theme::accent())), $terminal->paint());
    }

    public function testConfirmRedrawsTheChoiceAsItMoves(): void
    {
        $terminal = new FakeTerminal();
        $answer = $this->prompt("\e[C\r", $terminal)->confirm('Continue?', false);

        self::assertTrue($answer);
        // Both sides must have been drawn selected at some point: "No" first,
        // then "Yes" once the arrow moved.
        self::assertStringContainsString($this->rendered(Theme::selected(' No ')), $terminal->paint());
        self::assertStringContainsString($this->rendered(Theme::selected(' Yes ')), $terminal->paint());
    }

    /**
     * The wizard asks a dozen questions through one Prompt, and they all share
     * a single drawing surface. Each answer has to collapse to its recap line
     * and leave the surface free for the next question — no leftover frame, and
     * the transcript in the order it was answered.
     */
    public function testSuccessiveQuestionsShareOneSurfaceAndLeaveATranscript(): void
    {
        $terminal = new FakeTerminal();
        $prompt = $this->prompt("Acme\r1.2.3\ry\r", $terminal);

        self::assertSame('Acme', $prompt->text('Plugin name'));
        self::assertSame('1.2.3', $prompt->text('Version'));
        self::assertTrue($prompt->confirm('Continue?'));

        $painted = $terminal->paint();

        $positions = [];
        foreach (['Plugin name', 'Version', 'Continue?'] as $label) {
            $recap = $this->rendered('<info>✓</info> ' . $label);
            $position = strpos($painted, $recap);

            self::assertNotFalse($position, "\"$label\" was never recapped");
            $positions[] = $position;
        }

        self::assertLessThan($positions[1], $positions[0], 'the recaps must read in the order they were answered');
        self::assertLessThan($positions[2], $positions[1]);
    }

    public function testEveryPromptSaysHowToBackOut(): void
    {
        $terminal = new FakeTerminal();

        try {
            $this->prompt("\e", $terminal)->text('Plugin name');
        } catch (Cancelled) {
        }

        self::assertStringContainsString('esc cancel', $terminal->paint());
    }

    /**
     * The escape sequences a piece of markup turns into, derived rather than
     * spelled out: an assertion holding a literal `\e[…;48;2;68;51;219m` pins
     * both the brand colour and the terminal's colour depth, and quietly stops
     * testing anything the day either one moves.
     */
    private function rendered(string $markup): string
    {
        return (new OutputFormatter(true))->format($markup);
    }

    private function prompt(string $keystrokes, ?FakeTerminal $terminal = null): Prompt
    {
        $stream = tmpfile();
        self::assertNotFalse($stream);
        fwrite($stream, $keystrokes);
        rewind($stream);

        return new Prompt($terminal ?? new FakeTerminal(), new KeyReader($stream));
    }
}
