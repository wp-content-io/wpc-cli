<?php

namespace WpContent\Cli\Results;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The command listing, said twice: the docker-shaped screen for a person, the
 * commands and global options as data for whatever is wrapping the CLI.
 *
 * `Listing` used to write its screen straight to the output, which meant `wpc`,
 * `wpc list` and `wpc plugin` answered `--output=json` with `Usage:` and a
 * paragraph of prose — the exact regression the router exists to prevent, on
 * the three entry points a wrapper reaches for first to discover the verbs.
 */
final class ListingResult implements CommandOutput
{
    /**
     * @param list<string>         $lines   the screen, already assembled
     * @param array<string, mixed> $payload the machine answer
     */
    public function __construct(
        private readonly array $lines,
        private readonly array $payload,
    ) {
    }

    public function json(OutputInterface $output): void
    {
        // VERBOSITY_QUIET, like every other payload: `-q` silences the
        // diagnostics, not the thing the caller is parsing.
        $output->write(Json::encode($this->payload), false, OutputInterface::VERBOSITY_QUIET);
    }

    public function human(OutputInterface $output): void
    {
        $output->writeln($this->lines);
    }
}
