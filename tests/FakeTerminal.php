<?php

namespace WpContent\Cli\Tests;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A console output that supports sections but paints into memory.
 *
 * `ConsoleOutput` cannot be borrowed for this: it opens php://stdout in a
 * *private* method behind a static cache, so a subclass cannot redirect it and
 * a TUI under test would repaint over the test run itself.
 *
 * Decorated on purpose — the escape sequences are half of what a frame is, and
 * {@see paint()} is how a test reads back what was actually drawn.
 */
final class FakeTerminal extends StreamOutput implements ConsoleOutputInterface
{
    /** @var list<ConsoleSectionOutput> */
    private array $sections = [];

    private OutputInterface $stderr;

    public function __construct()
    {
        /** @var resource $stream */
        $stream = fopen('php://temp', 'w+');

        parent::__construct($stream, self::VERBOSITY_NORMAL, true);

        $this->stderr = new NullOutput();
    }

    public function section(): ConsoleSectionOutput
    {
        return new ConsoleSectionOutput(
            $this->getStream(),
            $this->sections,
            $this->getVerbosity(),
            $this->isDecorated(),
            $this->getFormatter()
        );
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    /** Everything drawn so far, escape sequences included. */
    public function paint(): string
    {
        $stream = $this->getStream();
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
