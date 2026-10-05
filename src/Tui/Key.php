<?php

namespace WpContent\Cli\Tui;

/**
 * The keys the TUI reacts to. Anything printable is reported as {@see Key::Char},
 * with the actual character carried by the {@see KeyPress}.
 */
enum Key
{
    case Up;
    case Down;
    case Left;
    case Right;
    case PageUp;
    case PageDown;
    case Home;
    case End;
    case Enter;
    case Escape;
    case Backspace;
    case Delete;
    case Tab;
    case CtrlC;
    case Char;

    /**
     * Anything unrecognised — a stray control byte, an unmapped escape sequence.
     * It must never map to a key that acts: reporting it as Escape would let a
     * spurious byte close the whole TUI.
     */
    case Unknown;
}
