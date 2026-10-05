<?php

namespace WpContent\Cli\Tui;

use ValueError;

/**
 * Turns raw bytes coming from a terminal in cbreak mode into {@see KeyPress}es.
 *
 * The terminal must already be in raw mode (see {@see RawMode}); this class only
 * decodes, it never touches the terminal settings. It is stream-based on purpose
 * so the decoding can be unit-tested against an in-memory stream, with no TTY.
 */
final class KeyReader
{
    /**
     * How long to wait for the rest of an escape sequence before deciding a lone
     * ESC byte really was the Escape key. Terminals emit the whole sequence in
     * one burst, so a few milliseconds are plenty.
     */
    private const ESCAPE_TIMEOUT_MICROSECONDS = 25000;

    /** @var resource */
    private $stream;

    /**
     * Whether the stream can be select()ed. Not every stream can — in-memory
     * ones cannot — and such a stream never blocks on read anyway, so the
     * timeout is simply skipped for them. Resolved on first use.
     */
    private ?bool $selectable = null;

    /**
     * @param resource|null $stream defaults to STDIN
     */
    public function __construct($stream = null)
    {
        /** @var resource $resolved */
        $resolved = $stream ?? \STDIN;
        $this->stream = $resolved;
    }

    /**
     * Block until the next keystroke. Returns null on end of input, which is how
     * a closed stdin (or an exhausted test fixture) ends the event loop.
     */
    public function read(): ?KeyPress
    {
        $byte = $this->readByte();

        if ($byte === null) {
            return null;
        }

        return match ($byte) {
            "\x1b" => $this->readEscapeSequence(),
            "\r", "\n" => new KeyPress(Key::Enter),
            "\x7f", "\x08" => new KeyPress(Key::Backspace),
            "\t" => new KeyPress(Key::Tab),
            "\x03" => new KeyPress(Key::CtrlC),
            default => $this->readCharacter($byte),
        };
    }

    /**
     * Decode what follows an ESC byte. A lone ESC (nothing else arrives before
     * the timeout) is the Escape key; otherwise it is a CSI/SS3 sequence.
     */
    private function readEscapeSequence(): KeyPress
    {
        $next = $this->readByte(self::ESCAPE_TIMEOUT_MICROSECONDS);

        if ($next === null) {
            return new KeyPress(Key::Escape);
        }

        // SS3 ("\eO…") is what terminals send for the arrows in application
        // keypad mode; the final byte is the same as in CSI.
        if ($next === 'O') {
            $final = $this->readByte(self::ESCAPE_TIMEOUT_MICROSECONDS);

            return $final === null ? new KeyPress(Key::Unknown) : $this->fromFinalByte($final, '');
        }

        // ESC followed by anything else is an Alt-chord we do not bind.
        if ($next !== '[') {
            return new KeyPress(Key::Unknown);
        }

        // CSI: parameter bytes, then a single final byte in the @–~ range.
        $parameters = '';
        while (true) {
            $byte = $this->readByte(self::ESCAPE_TIMEOUT_MICROSECONDS);

            if ($byte === null) {
                return new KeyPress(Key::Unknown);
            }

            if (preg_match('/[A-Za-z~]/', $byte)) {
                return $this->fromFinalByte($byte, $parameters);
            }

            $parameters .= $byte;

            // Guard against a malformed sequence flooding the buffer.
            if (strlen($parameters) > 8) {
                return new KeyPress(Key::Unknown);
            }
        }
    }

    private function fromFinalByte(string $final, string $parameters): KeyPress
    {
        if ($final === '~') {
            return new KeyPress(match ($parameters) {
                '1', '7' => Key::Home,
                '3' => Key::Delete,
                '4', '8' => Key::End,
                '5' => Key::PageUp,
                '6' => Key::PageDown,
                default => Key::Unknown,
            });
        }

        return new KeyPress(match ($final) {
            'A' => Key::Up,
            'B' => Key::Down,
            'C' => Key::Right,
            'D' => Key::Left,
            'H' => Key::Home,
            'F' => Key::End,
            'Z' => Key::Tab,
            default => Key::Unknown,
        });
    }

    /**
     * Assemble a whole UTF-8 character from its leading byte, so accented input
     * in the search filter is not split into mojibake.
     */
    private function readCharacter(string $leadingByte): KeyPress
    {
        $codePoint = ord($leadingByte);

        if ($codePoint < 0x20) {
            // An unbound control byte (Ctrl+D, Ctrl+Z…) must do nothing, not quit.
            return new KeyPress(Key::Unknown);
        }

        $continuationBytes = match (true) {
            $codePoint >= 0xF0 => 3,
            $codePoint >= 0xE0 => 2,
            $codePoint >= 0xC0 => 1,
            default => 0,
        };

        $character = $leadingByte;
        for ($i = 0; $i < $continuationBytes; $i++) {
            $byte = $this->readByte(self::ESCAPE_TIMEOUT_MICROSECONDS);
            if ($byte === null) {
                break;
            }
            $character .= $byte;
        }

        // Pasted latin-1, or a sequence cut short by the timeout: an invalid
        // byte does nothing rather than entering a buffer every `/u` pattern
        // downstream would choke on.
        if (preg_match('//u', $character) !== 1) {
            return new KeyPress(Key::Unknown);
        }

        return new KeyPress(Key::Char, $character);
    }

    /**
     * Read a single byte, optionally giving up after $timeoutMicroseconds.
     * A null timeout blocks until something arrives.
     */
    private function readByte(?int $timeoutMicroseconds = null): ?string
    {
        if ($timeoutMicroseconds !== null && !$this->waitForInput($timeoutMicroseconds)) {
            return null;
        }

        $byte = fread($this->stream, 1);

        return $byte === false || $byte === '' ? null : $byte;
    }

    private function waitForInput(int $timeoutMicroseconds): bool
    {
        if ($this->selectable === false) {
            return true;
        }

        $read = [$this->stream];
        $write = null;
        $except = null;

        try {
            $ready = @stream_select($read, $write, $except, 0, $timeoutMicroseconds);
        } catch (ValueError) {
            // Raised when the stream turned out not to be select()able: PHP drops
            // it from the array and then complains it was handed nothing.
            $this->selectable = false;

            return true;
        }

        if ($ready === false) {
            $this->selectable = false;

            return true;
        }

        $this->selectable = true;

        return $ready > 0;
    }
}
