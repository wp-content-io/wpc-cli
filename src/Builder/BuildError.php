<?php

namespace WpContent\Cli\Builder;

use Symfony\Component\Console\Command\Command;
use WpContent\Cli\Results\CommandFailure;

/**
 * A build that could not be produced: missing sources, an unwritable output
 * directory, an archive that would not close.
 *
 * A `RuntimeException` (through {@see CommandFailure}), not an
 * `\AssertionError` — that one belongs to failed `assert()` calls, which are
 * compiled out in production and mean "this code is wrong", not "this plugin
 * has no main file".
 */
class BuildError extends CommandFailure
{
    /**
     * Nothing here comes from HTTP, so there is no status to report — but a
     * failure reporting `"code": 0` reads as a success to anything checking the
     * field rather than the exit status.
     */
    public function __construct(string $message, int $code = 1)
    {
        parent::__construct($message, $code);
    }

    /**
     * The command was called wrongly: a path that is not there, an option that
     * cannot be read. Exits 2, wherever it is thrown from — a missing source
     * directory used to exit 1 from `build` and 2 from `manifest`.
     */
    public static function invalidInput(string $message): self
    {
        $error = new self($message, Command::INVALID);
        $error->exitCode = Command::INVALID;

        return $error;
    }
}
