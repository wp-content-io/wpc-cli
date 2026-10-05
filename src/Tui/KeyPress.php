<?php

namespace WpContent\Cli\Tui;

/**
 * A decoded keystroke: the logical key, plus the character itself when the key
 * is {@see Key::Char} (a whole UTF-8 character, not a single byte).
 */
final class KeyPress
{
    public function __construct(
        public readonly Key $key,
        public readonly string $char = '',
    ) {
    }

    public function is(Key ...$keys): bool
    {
        return in_array($this->key, $keys, true);
    }

    public function isChar(string ...$characters): bool
    {
        return $this->key === Key::Char && in_array($this->char, $characters, true);
    }

    /** Whether this keystroke is a printable character (excludes control keys). */
    public function isPrintable(): bool
    {
        return $this->key === Key::Char && $this->char !== '';
    }
}
