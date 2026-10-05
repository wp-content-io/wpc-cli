<?php

namespace WpContent\Cli\Results;

use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Tui\Text;

/**
 * Something that happened, said twice: a sentence for a person, an object for a
 * script.
 *
 * `self-update` is the case that needs it — "wpc is already up to date." reads
 * well on a terminal and is unparseable, while `{"update_available": false}` is
 * the answer a scheduled job is after. A table would serve neither.
 */
final class StatusResult implements CommandOutput
{
    /**
     * @param array<string, scalar> $payload
     */
    public function __construct(
        private readonly string $message,
        private readonly array $payload,
        private readonly string $style = 'info',
    ) {
    }

    public function json(OutputInterface $output): void
    {
        $output->write(Json::encode($this->payload), false, OutputInterface::VERBOSITY_QUIET);
    }

    public function human(OutputInterface $output): void
    {
        $output->writeln(sprintf('<%s>%s</%s>', $this->style, Text::escape($this->message), $this->style));
    }
}
