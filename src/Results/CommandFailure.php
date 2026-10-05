<?php

namespace WpContent\Cli\Results;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Something the user asked for that could not be done, in a shape both a person
 * and a script can read.
 *
 * One class so the failure schema is single: `{code, message, errors}` under
 * `--output=json`, a marked line plus its details under `human`. The subclasses
 * only add where the failure came from, and what the user can do about it.
 */
class CommandFailure extends RuntimeException implements CommandOutput
{
    /** @var array<int|string, mixed> field => what is wrong with it */
    public array $validationErrors = [];

    /**
     * What this failure exits with.
     *
     * Carried by the failure rather than decided at each `catch`, which is the
     * only way the promise holds: the same cause gives the same code in every
     * command. `INVALID` (2) means the command was called wrongly — a missing
     * file, a malformed option; everything else is `FAILURE` (1).
     */
    protected int $exitCode = Command::FAILURE;

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function json(OutputInterface $output): void
    {
        $output->write(Json::encode([
            // Never 0: a failure reporting `"code": 0` reads as a success to
            // anything checking the field rather than the exit status. An HTTP
            // status when there is one, the exit code otherwise.
            'code' => $this->getCode() ?: $this->exitCode,
            'message' => $this->getMessage(),
            'errors' => $this->validationErrors,
        ]), false, OutputInterface::VERBOSITY_QUIET);
    }

    public function human(OutputInterface $output): void
    {
        $output->writeln(sprintf(' <error> ✗ </error> <options=bold>%s</>', OutputFormatter::escape($this->getMessage())));

        foreach ($this->validationErrors as $field => $detail) {
            $output->writeln(sprintf(
                '   <fg=gray>%s</> %s',
                OutputFormatter::escape((string) $field),
                OutputFormatter::escape(is_scalar($detail) ? (string) $detail : Json::encode($detail))
            ));
        }

        if ($hint = $this->hint()) {
            $output->writeln('   <comment>' . $hint . '</comment>');
        }
    }

    /** What the user can actually do about it, when there is a useful answer. */
    protected function hint(): ?string
    {
        return null;
    }
}
