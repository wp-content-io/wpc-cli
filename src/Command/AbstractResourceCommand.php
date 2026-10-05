<?php

namespace WpContent\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Api\RegistryClient;
use WpContent\Cli\Builder\BuildProgress;
use WpContent\Cli\Builder\NullProgress;
use WpContent\Cli\Builder\PlainProgress;
use WpContent\Cli\ResourceType;
use WpContent\Cli\Results\CommandFailure;
use WpContent\Cli\Results\OutputRouter;
use WpContent\Cli\Runtime;
use WpContent\Cli\Tui\StepProgress;

/**
 * Base of every `plugin`/`theme` command. Concrete commands only declare
 * their name (#[AsCommand]) and their {@see ResourceType}; all the shared
 * logic lives in the per-verb abstract commands.
 *
 * Collaborators come in through the constructor rather than being fetched off
 * the application. A command that reaches back into its container can only be
 * exercised through a real one — which is how the four commands that talk to
 * the registry ended up with no test at all.
 */
abstract class AbstractResourceCommand extends Command
{
    public function __construct(
        protected readonly RegistryClient $registry,
        protected readonly OutputRouter $router,
        protected readonly Runtime $runtime,
    ) {
        // No name passed on purpose: the #[AsCommand] attribute of the concrete
        // class is what carries the name and the aliases, and Command reads it
        // off static::class when the constructor is given none.
        parent::__construct();
    }

    abstract protected function resourceType(): ResourceType;

    /**
     * Report what this run would need from the configuration and does not
     * have — the API key, for a command that talks to the registry as an
     * organization — and return the exit code, or null when nothing is missing.
     *
     * Checked at the top of `execute()`, after Symfony has validated the
     * arguments (a missing slug is still reported as such) and before anything
     * is built or sent: `build --push` without a key used to build the whole
     * archive first, and `list` asked production who the organization was.
     * Same shape and code as the missing repository the application checks:
     * the invocation lacks something, which is a usage error (2).
     */
    protected function unmetRequirements(OutputInterface $output): ?int
    {
        $missing = $this->runtime->requirements(authenticated: true);
        if ($missing === []) {
            return null;
        }

        foreach ($missing as $message) {
            $this->router->display($output, new CommandFailure($message, Command::INVALID));
        }

        return Command::INVALID;
    }

    protected function catalog(): ResourceCatalog
    {
        return new ResourceCatalog($this->registry, $this->resourceType());
    }

    /**
     * How a run reports what it is doing: silent under `--output=json`, where
     * any extra byte would corrupt the payload; the live step list on a
     * terminal; the historical `-v` lines everywhere else.
     *
     * Those lines go to **stderr**: progress is a diagnostic, not the answer,
     * and a build that failed before producing anything used to leave "Reading
     * sources" in the file the artifact path was being collected into.
     */
    protected function progress(OutputInterface $output): BuildProgress
    {
        if ($this->runtime->isJson()) {
            return new NullProgress();
        }

        if ($this->runtime->isInteractive() && $output instanceof ConsoleOutputInterface) {
            return new StepProgress($output);
        }

        return new PlainProgress($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
    }
}
