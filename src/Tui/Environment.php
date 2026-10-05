<?php

namespace WpContent\Cli\Tui;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use WpContent\Cli\Env;

/**
 * Decides whether the current run may take over the terminal.
 *
 * Everything the CLI does interactively has to degrade to the plain rendering
 * when it is not talking to a human: piped output, CI, `-n`, `-q`, `--no-ansi`,
 * a terminal without stty. Both the TUI and the passive "new version available"
 * notice share these checks so the two can never disagree.
 */
final class Environment
{
    /** Set to any truthy value to opt out of the interactive rendering entirely. */
    public const NO_TUI_ENV = 'WPC_NO_TUI';

    public static function supportsInteractive(InputInterface $input, OutputInterface $output): bool
    {
        return $input->isInteractive()
            && $output->getVerbosity() > OutputInterface::VERBOSITY_QUIET
            && $output->isDecorated()
            && $output instanceof ConsoleOutputInterface
            && RawMode::isSupported()
            && self::isTty(\STDIN)
            && self::streamIsTty($output)
            && !Env::isTruthy('CI')
            && !Env::isTruthy(self::NO_TUI_ENV);
    }

    /** Whether the output's own stream (usually stdout) is a terminal. */
    public static function streamIsTty(OutputInterface $output): bool
    {
        return $output instanceof StreamOutput && self::isTty($output->getStream());
    }

    /** Whether the error output (stderr) is a terminal. */
    public static function stderrIsTty(OutputInterface $output): bool
    {
        return self::streamIsTty($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
    }

    /**
     * @param resource $stream
     */
    private static function isTty($stream): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }

        return function_exists('posix_isatty') && @posix_isatty($stream);
    }
}
