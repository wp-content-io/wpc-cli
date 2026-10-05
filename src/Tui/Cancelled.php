<?php

namespace WpContent\Cli\Tui;

use RuntimeException;

/**
 * The user backed out of a prompt — Escape, Ctrl+C, or an input that ended.
 *
 * Thrown rather than returned so it unwinds the *whole* flow. A wizard that
 * treated a cancelled question as "keep the default" would carry on asking the
 * next eleven, and create the plugin anyway: pressing Escape means "not this",
 * never "whatever you think".
 */
final class Cancelled extends RuntimeException
{
    public function __construct(string $message = 'Cancelled.')
    {
        parent::__construct($message);
    }
}
