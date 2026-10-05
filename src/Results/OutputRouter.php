<?php

namespace WpContent\Cli\Results;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use WpContent\Cli\Runtime;
use WpContent\Cli\Tui\Screen;

/**
 * Renders a result in the format the run asked for, on the stream that format
 * belongs on.
 *
 * `json` is the automation contract and stays raw, on **stdout** — success and
 * failure alike, since the payload *is* the answer and the exit code is what
 * tells the two apart. Everything a human reads goes to **stderr** when it
 * describes a failure, so `wpc plugin build … > artifact.txt` cannot end up with
 * an error message where the caller expected a path.
 *
 * `human` (the default) upgrades to the interactive rendering when the result
 * supports it and a real terminal is attached, then still prints the static
 * rendering so the state the user navigated to is left behind.
 */
final class OutputRouter
{
    public function __construct(private readonly Runtime $runtime)
    {
    }

    public function display(OutputInterface $output, CommandOutput $result): void
    {
        if ($this->runtime->isJson()) {
            $result->json($output);

            return;
        }

        if ($this->runtime->isInteractive()
            && $result instanceof InteractiveOutput
            && $output instanceof ConsoleOutputInterface
        ) {
            $result->interactive(new Screen($output));
        }

        $result->human($this->humanStream($output, $result));
    }

    /**
     * Errors are diagnostics, not output. They are only kept on stdout when
     * there is no other stream to write them to — a `BufferedOutput`, say —
     * because saying nothing at all would be worse.
     */
    private function humanStream(OutputInterface $output, CommandOutput $result): OutputInterface
    {
        if (!$result instanceof Throwable || !$output instanceof ConsoleOutputInterface) {
            return $output;
        }

        return $output->getErrorOutput();
    }
}
