<?php

namespace WpContent\Cli\Results;

use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Tui\Text;

/**
 * One named value — what `manifest --get Version` answers.
 *
 * Human output is the bare value, so `VERSION=$(wpc plugin manifest … --get
 * Version)` keeps working: no styling, no trailing newline, and **raw**, since
 * the formatter would otherwise swallow a header reading "requires PHP <8.0".
 * JSON output is still an object, so the automation contract holds here too.
 */
final class ValueResult implements CommandOutput
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {
    }

    public function json(OutputInterface $output): void
    {
        $output->write(
            Json::encode([$this->name => $this->value]),
            false,
            OutputInterface::VERBOSITY_QUIET
        );
    }

    public function human(OutputInterface $output): void
    {
        // The only human path that writes bytes straight off a file, so it is
        // also the only one that has to drop the bad ones itself: everything
        // else goes through Text, which does it at the door.
        $output->write(Text::utf8($this->value), false, OutputInterface::VERBOSITY_QUIET | OutputInterface::OUTPUT_RAW);
    }
}
