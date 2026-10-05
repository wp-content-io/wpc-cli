<?php

namespace WpContent\Cli\Command;

use Cocur\Slugify\Slugify;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WpContent\Cli\Builder\BuildError;
use WpContent\Cli\Builder\Generator;
use WpContent\Cli\Results\CommandFailure;
use WpContent\Cli\Results\SingleResult;
use WpContent\Cli\Tui\Cancelled;
use WpContent\Cli\Tui\Prompt;

/**
 * Shared logic for `plugin init` / `theme init`. Concrete commands provide
 * their {@see optionalHeaders} map, which is where the two families diverge
 * (version option name, URI header, network vs tags...).
 */
abstract class AbstractInitCommand extends AbstractResourceCommand
{
    /** Set when the wizard was escaped; execute() then writes nothing. */
    private bool $cancelled = false;

    /**
     * Header label => option name, e.g. ['Version' => 'plugin-version', ...].
     *
     * @return array<string, string>
     */
    abstract protected function optionalHeaders(): array;

    protected function configure(): void
    {
        $type = $this->resourceType();

        $this->setDescription("Start a new {$type->value}")
            ->addArgument('name', InputArgument::OPTIONAL, "{$type->label()} name")
            ->addArgument('slug', InputArgument::OPTIONAL, "{$type->label()} slug");

        foreach ($this->optionalHeaders() as $headerName => $optionName) {
            $this->addOption($optionName, null, InputOption::VALUE_REQUIRED, "{$headerName} {$type->value} header");
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $this->resourceType();

        // Backing out of the wizard is a decision, not a failure to describe:
        // say so once, on the stream diagnostics belong on, and write nothing
        // to disk — nor to stdout, which a caller may be collecting a path from.
        if ($this->cancelled) {
            $this->router->display($output, new CommandFailure('Cancelled.', Command::FAILURE));

            return Command::FAILURE;
        }

        $name = trim((string) ($input->getArgument('name') ?? ''));
        $slug = trim((string) ($input->getArgument('slug') ?? ''));

        if (!$name) {
            $error = BuildError::invalidInput("{$type->label()} name is required");
            $this->router->display($output, $error);

            return $error->exitCode();
        }

        // Slugified even when it was given. Taken verbatim, the argument put the
        // new resource wherever it pointed — `init "Acme" "../../tmp/x"` wrote
        // outside the working directory — and a source that slugifies to
        // nothing (punctuation only, or CJK) resolved `$root . '/' . ''` back to
        // the caller's own cwd: a hidden `.php` file, reported as a success with
        // the cwd as its path.
        $source = $slug !== '' ? $slug : $name;
        $slug = (new Slugify())->slugify($source);

        if ($slug === '') {
            $error = BuildError::invalidInput(sprintf('"%s" does not produce a usable slug.', $source));
            $this->router->display($output, $error);

            return $error->exitCode();
        }

        // Symfony only calls interact() on an interactive input, so under `-n`
        // nothing was seeded: the Author the registry knows never reached the
        // file, and the registry refuses a push without one — `init -n` then
        // `build --push` failed on a header nobody had been asked for.
        if (!$input->isInteractive()) {
            $this->prefill($input);
        }

        $headers = [
            $type->nameHeader() => '',
            'Description' => '',
            'Version' => '1.0.0',
        ];

        foreach ($this->optionalHeaders() as $headerName => $optionName) {
            // Compared rather than tested for truth: `--network=0` is a header
            // the caller asked for, and it used to be dropped on the floor.
            $value = (string) ($input->getOption($optionName) ?? '');

            if ($value !== '') {
                $headers[$headerName] = $value;
            }
        }

        $headers[$type->nameHeader()] = $name;
        $headers['Update URI'] = $this->runtime->repository();

        $artifact = (new Generator())->generateMainFile($type, $slug, $headers);

        if ($artifact === null) {
            // It used to return FAILURE having written nothing at all, so a
            // directory that could not be created looked like a silent success.
            $this->router->display($output, new BuildError("Could not create the $slug directory"));

            return Command::FAILURE;
        }

        // The same shape `build` answers with, so `--output=json` is one object
        // here too: {"path": "…"}.
        $this->router->display($output, new SingleResult(['path' => $artifact]));

        return Command::SUCCESS;
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if ($this->runtime->isInteractive() && $output instanceof ConsoleOutputInterface) {
            try {
                $this->wizard($input, new Prompt($output));
            } catch (Cancelled) {
                $this->cancelled = true;
            }

            return;
        }

        $this->questionnaire($input, $output);
    }

    /**
     * The keyboard-driven wizard: name, then tick the headers worth filling in,
     * then fill only those. The old flow asked all twelve in a row.
     */
    private function wizard(InputInterface $input, Prompt $prompt): void
    {
        $type = $this->resourceType();
        $optionalHeaders = $this->optionalHeaders();
        $prefilled = $this->prefill($input);

        if (!$input->getArgument('name')) {
            $input->setArgument('name', $prompt->text("{$type->label()} name"));
        }

        // Unticked headers keep their value (that is what the plain flow does
        // when the user declines the extra questions); ticking one only means
        // "let me type this value". Everything already carrying one is ticked,
        // so a header passed on the command line is visible rather than hidden
        // behind a question the user never sees.
        $chosen = $prompt->multiselect(
            'Headers to edit',
            array_keys($optionalHeaders),
            $prefilled,
        );

        foreach ($chosen as $headerName) {
            $optionName = $optionalHeaders[$headerName];
            $input->setOption($optionName, $prompt->text($headerName, (string) ($input->getOption($optionName) ?? '')));
        }
    }

    /**
     * The plain question flow, kept verbatim for pipes, CI and terminals that
     * cannot be driven — it is also what keeps `init` scriptable.
     */
    private function questionnaire(InputInterface $input, OutputInterface $output): void
    {
        $type = $this->resourceType();
        // Instantiated rather than pulled from the helper set: Symfony 7 types
        // getHelper() as HelperInterface, which does not carry ask().
        $helper = new QuestionHelper();
        $optionalHeaders = $this->optionalHeaders();
        $this->prefill($input);

        $nameQuestion = new Question("{$type->label()} name: ");
        $showExtraQuestions = new ConfirmationQuestion(
            "Would you like to define {$type->value} additional headers ? [N/y] ",
            false
        );

        if (!$input->getArgument('name')) {
            $input->setArgument('name', $helper->ask($input, $output, $nameQuestion));
        }

        $extraQuestions = [];
        foreach ($optionalHeaders as $headerName => $optionName) {
            $defaultValue = $input->getOption($optionName);
            if ($defaultValue) {
                $headerName .= " ($defaultValue)";
            }
            $extraQuestions[$optionName] = new Question("$headerName: ", $defaultValue);
        }

        if ($helper->ask($input, $output, $showExtraQuestions)) {
            foreach ($extraQuestions as $optionName => $question) {
                $input->setOption($optionName, $helper->ask($input, $output, $question));
            }
        }
    }

    /**
     * Seed the defaults into the input, leaving alone whatever the caller
     * already said.
     *
     * The seeding used to be unconditional, so an explicit value was gone
     * before anything read it: `--plugin-version=2.1.0` was handed straight
     * back to 1.0.0, and `--author "John Doe"` replaced by whatever the
     * registry answered. The options are VALUE_REQUIRED with no default, so
     * null is exactly "not given".
     *
     * @return list<string> the headers now carrying a value, seeded or given
     */
    private function prefill(InputInterface $input): array
    {
        $optionalHeaders = $this->optionalHeaders();

        foreach ($this->headerDefaults() as $headerName => $value) {
            if ($input->getOption($optionalHeaders[$headerName]) === null) {
                $input->setOption($optionalHeaders[$headerName], $value);
            }
        }

        $filled = [];
        foreach ($optionalHeaders as $headerName => $optionName) {
            if ((string) ($input->getOption($optionName) ?? '') !== '') {
                $filled[] = $headerName;
            }
        }

        return $filled;
    }

    /**
     * Pre-filled values for the optional headers: version 1.0.0, plus whatever
     * the API already knows about the current author.
     *
     * @return array<string, string>
     */
    private function headerDefaults(): array
    {
        $optionalHeaders = $this->optionalHeaders();
        $defaults = isset($optionalHeaders['Version']) ? ['Version' => '1.0.0'] : [];

        foreach ($this->registry->author() as $headerName => $headerValue) {
            if (!isset($optionalHeaders[$headerName]) || !is_scalar($headerValue) || (string) $headerValue === '') {
                continue;
            }

            $defaults[$headerName] = (string) $headerValue;
        }

        return $defaults;
    }
}
