<?php

namespace WpContent\Cli\Tests;

use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A console output whose two streams can be read back separately.
 *
 * `BufferedOutput` merges them, which hides the whole point of writing notices
 * to stderr: that stdout stays exactly what a script would parse.
 */
final class SplitOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $stderr;

    public function __construct(int $verbosity = self::VERBOSITY_NORMAL)
    {
        parent::__construct($verbosity);

        $this->stderr = new BufferedOutput($verbosity);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function fetchError(): string
    {
        return $this->stderr instanceof BufferedOutput ? $this->stderr->fetch() : '';
    }

    public function section(): ConsoleSectionOutput
    {
        // Only the TUI asks for one, and it never starts without a terminal.
        throw new LogicException('SplitOutput has no sections.');
    }
}
