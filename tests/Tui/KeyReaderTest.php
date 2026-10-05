<?php

namespace WpContent\Cli\Tests\Tui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpContent\Cli\Tui\Key;
use WpContent\Cli\Tui\KeyPress;
use WpContent\Cli\Tui\KeyReader;

/**
 * Decoding is tested against an in-memory stream, so the whole key map is
 * covered without needing a real terminal.
 */
final class KeyReaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Key}>
     */
    public static function sequences(): iterable
    {
        yield 'up arrow' => ["\e[A", Key::Up];
        yield 'down arrow' => ["\e[B", Key::Down];
        yield 'right arrow' => ["\e[C", Key::Right];
        yield 'left arrow' => ["\e[D", Key::Left];
        yield 'home' => ["\e[H", Key::Home];
        yield 'end' => ["\e[F", Key::End];
        yield 'home (numeric)' => ["\e[1~", Key::Home];
        yield 'end (numeric)' => ["\e[4~", Key::End];
        yield 'page up' => ["\e[5~", Key::PageUp];
        yield 'page down' => ["\e[6~", Key::PageDown];
        yield 'delete' => ["\e[3~", Key::Delete];
        // Application keypad mode (SS3) — what some terminals send for arrows.
        yield 'up arrow (SS3)' => ["\eOA", Key::Up];
        yield 'down arrow (SS3)' => ["\eOB", Key::Down];
        yield 'carriage return' => ["\r", Key::Enter];
        yield 'line feed' => ["\n", Key::Enter];
        yield 'backspace' => ["\x7f", Key::Backspace];
        yield 'tab' => ["\t", Key::Tab];
        yield 'ctrl+c' => ["\x03", Key::CtrlC];
        yield 'lone escape' => ["\e", Key::Escape];
    }

    #[DataProvider('sequences')]
    public function testSequenceDecodesToItsKey(string $bytes, Key $expected): void
    {
        $key = $this->reader($bytes)->read();

        self::assertInstanceOf(KeyPress::class, $key);
        self::assertSame($expected, $key->key);
    }

    public function testPrintableCharacterCarriesItsValue(): void
    {
        $key = $this->reader('q')->read();

        self::assertInstanceOf(KeyPress::class, $key);
        self::assertSame(Key::Char, $key->key);
        self::assertSame('q', $key->char);
        self::assertTrue($key->isChar('q'));
        self::assertTrue($key->isPrintable());
    }

    public function testMultibyteCharacterIsReadWhole(): void
    {
        // A single é must not be split into two mojibake keystrokes.
        $key = $this->reader('é')->read();

        self::assertInstanceOf(KeyPress::class, $key);
        self::assertSame('é', $key->char);
        self::assertSame(1, mb_strlen($key->char));
    }

    public function testConsecutiveKeysAreReadInOrder(): void
    {
        $reader = $this->reader("\e[B\e[Bq");

        self::assertSame(Key::Down, $reader->read()?->key);
        self::assertSame(Key::Down, $reader->read()?->key);
        self::assertSame('q', $reader->read()?->char);
    }

    public function testEndOfInputReturnsNull(): void
    {
        self::assertNull($this->reader('')->read());
    }

    public function testUnknownSequenceIsInertRatherThanActing(): void
    {
        // An unmapped CSI must neither leak into the search filter nor be taken
        // for Escape, which would close the TUI.
        self::assertSame(Key::Unknown, $this->reader("\e[99~")->read()?->key);
        self::assertSame(Key::Unknown, $this->reader("\e[99X")->read()?->key);
    }

    public function testStrayControlByteIsInert(): void
    {
        // Ctrl+D arriving on stdin used to be reported as Escape, which quit the
        // TUI on the very first frame.
        $key = $this->reader("\x04")->read();

        self::assertSame(Key::Unknown, $key?->key);
        self::assertFalse($key->isPrintable());
    }

    public function testAltChordIsInert(): void
    {
        self::assertSame(Key::Unknown, $this->reader("\eb")->read()?->key);
    }

    public function testStreamThatCannotBeSelectedStillDecodes(): void
    {
        // In-memory streams are not select()able; the reader must fall back to a
        // plain read instead of letting stream_select() blow up.
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\e[Aq");
        rewind($stream);

        $reader = new KeyReader($stream);

        self::assertSame(Key::Up, $reader->read()?->key);
        self::assertSame('q', $reader->read()?->char);
    }

    private function reader(string $input): KeyReader
    {
        // A real file is select()able, so this exercises the nominal path.
        $stream = tmpfile();
        self::assertIsResource($stream);

        fwrite($stream, $input);
        rewind($stream);

        return new KeyReader($stream);
    }
}
