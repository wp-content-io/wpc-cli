<?php

namespace WpContent\Cli;

use Phar;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use WpContent\Cli\Api\GuzzleRegistryClient;
use WpContent\Cli\Api\RegistryClient;
use WpContent\Cli\Command\AbstractListCommand;
use WpContent\Cli\Command\AbstractResourceCommand;
use WpContent\Cli\Command\Listing;
use WpContent\Cli\Command\Plugins\Build as BuildPlugin;
use WpContent\Cli\Command\Plugins\Index as ListPlugins;
use WpContent\Cli\Command\Plugins\Info as InfoPlugin;
use WpContent\Cli\Command\Plugins\Init as InitPlugin;
use WpContent\Cli\Command\Plugins\Manifest as ManifestPlugin;
use WpContent\Cli\Command\Plugins\Push as PushPlugin;
use WpContent\Cli\Command\SelfUpdate;
use WpContent\Cli\Command\Themes\Build as BuildTheme;
use WpContent\Cli\Command\Themes\Index as ListThemes;
use WpContent\Cli\Command\Themes\Info as InfoTheme;
use WpContent\Cli\Command\Themes\Init as InitTheme;
use WpContent\Cli\Command\Themes\Manifest as ManifestTheme;
use WpContent\Cli\Command\Themes\Push as PushTheme;
use WpContent\Cli\Results\CommandFailure;
use WpContent\Cli\Results\CommandOutput;
use WpContent\Cli\Results\OutputRouter;
use WpContent\Cli\Tui\Environment;
use WpContent\Cli\Update\UpdateChecker;
use WpContent\Cli\Update\Version;

class Application extends BaseApplication
{
    public const VERSION = '@cli_version@';

    /**
     * The v1 spelling of `--page` on `plugin:ls` / `theme:ls`.
     *
     * Not declared on the command: Symfony has no hidden option, and anything
     * in the definition shows up in `--help`, in `wpc list`, in the shell
     * completion and in the pinned contract — all places where the old name
     * would be advertised to people who never typed it. It is rewritten in the
     * input instead, once the command is known, see {@see renameLegacyOptions()}.
     */
    private const LEGACY_PAGE_OPTION = '--paged';

    private readonly Runtime $runtime;

    private readonly RegistryClient $registry;

    private readonly OutputRouter $router;

    /** The full output, kept because renderThrowable() only receives stderr. */
    private ?OutputInterface $output = null;

    private int $runDepth = 0;

    /** @var list<string>|null words typed before the cursor while completing, binary included */
    private ?array $completionWords = null;

    /**
     * The two collaborators are arguments so a test can hand in its own — that
     * is the whole point of the commands taking theirs by constructor. Neither
     * needs the configuration yet: {@see Runtime} is filled in once the input
     * has been parsed, and everything downstream reads it from there.
     *
     * The version is one too, for the same reason: {@see VERSION} is whatever
     * Box stamped, and a test cannot stamp it. It is shown without the `v` of
     * the tag ({@see Version::display()}), so `wpc --version` and the update
     * notice read `2.1.0` whatever the tag was spelled.
     */
    public function __construct(?RegistryClient $registry = null, ?Runtime $runtime = null, string $version = self::VERSION)
    {
        parent::__construct('wp-content.io CLI', Version::display($version));

        $this->runtime = $runtime ?? new Runtime();
        $this->registry = $registry ?? new GuzzleRegistryClient($this->runtime);
        $this->router = new OutputRouter($this->runtime);

        $this->addCommands([
            ...$this->resourceCommands(),
            new SelfUpdate($this->router),
        ]);
    }

    /**
     * @return list<AbstractResourceCommand>
     */
    private function resourceCommands(): array
    {
        $commands = [];

        foreach ([
            InitPlugin::class, ListPlugins::class, InfoPlugin::class,
            BuildPlugin::class, PushPlugin::class, ManifestPlugin::class,
            InitTheme::class, ListThemes::class, InfoTheme::class,
            BuildTheme::class, PushTheme::class, ManifestTheme::class,
        ] as $class) {
            $commands[] = new $class($this->registry, $this->router, $this->runtime);
        }

        return $commands;
    }

    /**
     * The grammar is `wpc plugin list`, so the shell hands us two words for
     * what is a single command name. They are glued back together here, before
     * anything parses the input — see {@see CommandLine}.
     *
     * Only the real command line goes through it: a caller passing its own
     * input (the tests, `build --push`) is left alone.
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $input ??= new ArgvInput($this->normalizeArgv($_SERVER['argv'] ?? []));

        // Same default Symfony would apply, kept because renderThrowable() is
        // only ever handed the error stream and the JSON contract needs stdout.
        $this->output = $output ??= new ConsoleOutput();

        return parent::run($input, $output);
    }

    /**
     * Under `--output=json`, a failure is still an answer: it goes to stdout,
     * as one object with the same shape as every other error the CLI reports.
     *
     * Symfony's own rendering — the boxed message on stderr — is what a script
     * asking for JSON used to get for a mistyped command or a missing argument,
     * which meant the contract only held as long as nothing went wrong.
     */
    public function renderThrowable(Throwable $e, OutputInterface $output): void
    {
        if (!$this->runtime->isJson()) {
            parent::renderThrowable($e, $output);

            return;
        }

        $failure = $e instanceof CommandOutput
            ? $e
            : new CommandFailure($e->getMessage(), $this->errorCode($e));

        $failure->json($this->output ?? $output);
    }

    /** A non-zero code, since this only ever describes a failure. */
    private function errorCode(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code !== 0 ? $code : 1;
    }

    /**
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private function normalizeArgv(array $argv): array
    {
        if (($argv[1] ?? null) === '_complete') {
            ['argv' => $argv, 'words' => $this->completionWords] = CommandLine::normalizeCompletion($argv, $this->has(...));

            return $argv;
        }

        return CommandLine::normalize($argv, $this->getDefinition(), $this->has(...));
    }

    protected function getDefaultCommands(): array
    {
        $commands = parent::getDefaultCommands();

        foreach ($commands as $index => $command) {
            if ($command instanceof ListCommand) {
                $commands[$index] = new Listing($this->router);
            }
        }

        return $commands;
    }

    /**
     * The canonical names of a namespace's verbs, aliases excluded.
     *
     * @return list<string>
     */
    public function verbs(string $namespace): array
    {
        $names = [];

        foreach ($this->all() as $name => $command) {
            if ($command->getName() === $name && str_starts_with($name, $namespace . ' ')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * The namespace of a command name, which is now the word before the space.
     *
     * Symfony splits on `:`, so every two-word name fell into `_global` and
     * `wpc list plugin --raw` — a machine contract, and the one thing `Listing`
     * deliberately leaves to the parent — came back empty: only the legacy
     * aliases still landed in the namespace, and the descriptors do not emit
     * aliases.
     */
    public function extractNamespace(string $name, ?int $limit = null): string
    {
        $namespace = strstr($name, ' ', true);

        return $namespace === false ? parent::extractNamespace($name, $limit) : $namespace;
    }

    /**
     * Symfony looks for near-misses by splitting on `:`, so a mistyped verb in
     * the new grammar produces no suggestion at all. Offer the namespace's own
     * verbs instead.
     */
    public function find(string $name): Command
    {
        try {
            return parent::find($name);
        } catch (CommandNotFoundException $e) {
            $namespace = strstr($name, ' ', true);
            $alternatives = $namespace === false ? [] : $this->verbs($namespace);

            // Rethrown rather than propagated, for the code alone: Symfony's own
            // carries 0, which run() turns into an exit status of 1 — and a
            // command that does not exist is a usage error, which is 2.
            //
            // Rethrown *without* Symfony's alternatives, too. They are not
            // decoration: `doRun()` reads them, and on exactly one it stops
            // ahead of renderThrowable() to ask "Do you want to run X instead?"
            // — a question on stdout, an exit status of 1, and no JSON, for
            // every `wpc lst` and `wpc slef-update` in a pipeline. The message
            // Symfony built already spells the suggestion out in words, which
            // is the half a person needs.
            if ($alternatives === []) {
                throw new CommandNotFoundException($e->getMessage(), [], Command::INVALID);
            }

            // Not chained to the original: Symfony renders the whole chain, and
            // the two say the same thing — a mistyped verb printed "is not
            // defined" twice, once with our suggestions and once with Symfony's.
            // The suggestions go in the message and nowhere else, for the same
            // reason as above: handed over as alternatives, a namespace that
            // ever came down to a single verb would start asking questions.
            throw new CommandNotFoundException(
                sprintf(
                    "Command \"%s\" is not defined.\n\nDid you mean one of these?\n    %s",
                    $name,
                    implode("\n    ", $alternatives)
                ),
                [],
                Command::INVALID
            );
        }
    }

    /**
     * A command name is two words now, so the shell needs to be told about them
     * one at a time: the nouns first, then that noun's verbs.
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $words = $this->completionWords;

        if ($words !== null && count($words) === 1) {
            foreach ($this->firstWords() as $word => $description) {
                $suggestions->suggestValue(new Suggestion($word, $description));
            }

            return;
        }

        if ($words !== null && count($words) === 2 && ResourceType::tryFrom($words[1]) !== null) {
            foreach ($this->verbSuggestions($words[1]) as $verb => $description) {
                $suggestions->suggestValue(new Suggestion($verb, $description));
            }

            return;
        }

        parent::complete($input, $suggestions);
    }

    /**
     * @return array<string, string>
     */
    private function firstWords(): array
    {
        $words = [];

        foreach (ResourceType::cases() as $type) {
            $words[$type->value] = $type->manageDescription();
        }

        foreach ($this->all() as $name => $command) {
            if ($command->getName() !== $name || $command->isHidden() || str_contains($name, ' ')) {
                continue;
            }
            $words[$name] = $command->getDescription();
        }

        return $words;
    }

    /**
     * Verbs of a namespace, aliases included — `ls` and `start` are worth
     * completing too, they are what a v1 user reaches for.
     *
     * @return array<string, string>
     */
    private function verbSuggestions(string $namespace): array
    {
        $prefix = $namespace . ' ';
        $verbs = [];

        foreach ($this->all() as $name => $command) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $verbs[substr($name, strlen($prefix))] = $command->getDescription();
        }

        ksort($verbs);

        return $verbs;
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOptions([
            new InputOption('output', null, InputOption::VALUE_REQUIRED, 'Output format (human, plain, json)', Config::DEFAULT_OUTPUT),
            new InputOption('repository', null, InputOption::VALUE_REQUIRED, 'The repository api URL'),
            new InputOption('api-key', null, InputOption::VALUE_REQUIRED, 'API Key to use for request'),
        ]);

        return $definition;
    }

    /**
     * The configuration is resolved here, before the parent looks the command
     * up: `find()` is what throws on a mistyped name, and the JSON contract has
     * to hold for that failure too — which it cannot if the output format is
     * still unknown when the exception is rendered.
     *
     * Resolved once for the whole run, so a nested command (`build --push`)
     * inherits it rather than parsing an input it was never given.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->runtime->isResolved()) {
            $config = new Config($input);
            $errors = $config->validate();

            // `--version` is answered by the parent before any command is
            // looked up, and it used to be answered before the configuration
            // was read at all. What the *invocation* got wrong still counts —
            // a malformed option is a usage error wherever it appears — but
            // what a *command* would have needed does not: a repository that
            // is not set has nothing to do with printing a version number, and
            // failing there breaks every installer checking the binary works.
            if (!$input->hasParameterOption(['--version', '-V'], true)) {
                $errors = [...$errors, ...$config->requirements()];
            }

            // Resolved even when invalid, so the failure is reported in the
            // format that was *asked* for.
            $this->runtime->resolve(
                $config,
                $errors === [] && $config->allowsInteractive() && Environment::supportsInteractive($input, $output)
            );

            if ($errors !== []) {
                // A malformed option is a usage error, same as a missing file:
                // the README's table is what a pipeline branches on.
                foreach ($errors as $error) {
                    $this->router->display($output, new CommandFailure($error, Command::INVALID));
                }

                return Command::INVALID;
            }
        }

        return parent::doRun($input, $output);
    }

    /**
     * @throws Throwable
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $legacy = $this->renameLegacyOptions($command, $input);
        if ($legacy !== null) {
            $input = $legacy;
        }

        $this->runDepth++;
        try {
            $exitCode = parent::doRunCommand($command, $input, $output);
        } catch (CommandNotFoundException $e) {
            // Already carries its code, and does not take one in that position.
            throw $e;
        } catch (ConsoleException $e) {
            // Everything the console itself raises about *how* the command was
            // called — a missing argument, an unknown option, an option handed
            // no value — is a usage error, and exits 2 like every other one.
            //
            // Not chained to the original, same as find(): Symfony's renderer
            // walks the whole chain, so the two identical messages were printed
            // one after the other on every single usage error.
            $class = $e::class;

            throw new $class($e->getMessage(), Command::INVALID);
        } finally {
            $this->runDepth--;
        }

        // Only for the outermost command (build --push runs push through doRun).
        if ($this->runDepth === 0) {
            $this->warnDeprecatedName($command, $input, $output);
            if ($legacy !== null) {
                $this->warnDeprecated(
                    $output,
                    sprintf('"%s" is deprecated, use "--page". The old option will keep working.', self::LEGACY_PAGE_OPTION)
                );
            }
            $this->notifyUpdateAvailable($command, $input, $output);
        }

        return $exitCode;
    }

    /**
     * Tell whoever still types `wpc plugin:ls` that the name has moved.
     *
     * Deliberately louder than the update notice: it does *not* require a TTY
     * and does *not* switch itself off in CI. A pipeline is exactly where an
     * old name survives unnoticed, and its log is the only channel that reaches
     * the person maintaining it. Still silent for `--output=json` and `-q`, so
     * no parsed stream is disturbed, and `WPC_NO_DEPRECATED_WARNING=1` opts out.
     */
    private function warnDeprecatedName(Command $command, InputInterface $input, OutputInterface $output): void
    {
        $typed = $input->getFirstArgument();

        if ($typed === null || !str_contains($typed, ':') || !in_array($typed, $command->getAliases(), true)) {
            return;
        }

        $this->warnDeprecated($output, sprintf(
            '"%s" is deprecated, use "wpc %s". The old name will keep working.',
            $typed,
            $command->getName()
        ));
    }

    /**
     * One rule for every deprecation notice, a name or an option: stderr only,
     * and silent wherever a stream is being parsed or the user opted out.
     */
    private function warnDeprecated(OutputInterface $output, string $message): void
    {
        // No separate error stream means the only place to write is the one the
        // caller may be parsing — say nothing rather than corrupt it.
        if (!$output instanceof ConsoleOutputInterface
            || $this->runtime->isJson()
            || $output->getVerbosity() <= OutputInterface::VERBOSITY_QUIET
            || Env::isTruthy('WPC_NO_DEPRECATED_WARNING')
        ) {
            return;
        }

        $output->getErrorOutput()->writeln('<comment>wpc: ' . OutputFormatter::escape($message) . '</comment>');
    }

    /**
     * `--paged` → `--page`, on the list commands only, or null when there is
     * nothing to rename.
     *
     * Done here rather than in {@see CommandLine::normalize()}: that one only
     * sees the real argv, and before any command is resolved — `-p`/`--paged`
     * mean nothing on `build`, and rewriting there would also miss every input
     * handed in directly. Here the command is known and its input is not bound
     * yet, so renaming the token is all it takes: `--paged=2`, `--paged 2` and
     * a bare `--paged` (which, like v1, asks for a value) all become their
     * `--page` twin, and `-p` was never renamed at all. Nothing after `--` is
     * an option, so nothing there is touched.
     */
    private function renameLegacyOptions(Command $command, InputInterface $input): ?InputInterface
    {
        if (!$command instanceof AbstractListCommand
            || !$input instanceof ArgvInput
            || !$input->hasParameterOption(self::LEGACY_PAGE_OPTION, true)
        ) {
            return null;
        }

        $tokens = [];
        $literal = false;

        foreach ($input->getRawTokens() as $token) {
            if (!$literal && $token === '--') {
                $literal = true;
            } elseif (!$literal && ($token === self::LEGACY_PAGE_OPTION || str_starts_with($token, self::LEGACY_PAGE_OPTION . '='))) {
                $token = '--page' . substr($token, strlen(self::LEGACY_PAGE_OPTION));
            }

            $tokens[] = $token;
        }

        // ArgvInput drops the first entry as the binary name.
        $renamed = new ArgvInput(['wpc', ...$tokens]);
        $renamed->setInteractive($input->isInteractive());

        $stream = $input->getStream();
        if ($stream !== null) {
            $renamed->setStream($stream);
        }

        return $renamed;
    }

    /**
     * Print a one-line "new version available" notice on STDERR, unless any
     * automation-safety condition applies (json output, quiet, non-interactive,
     * non-TTY, opt-out env, dev build, or the self-update command itself).
     */
    private function notifyUpdateAvailable(Command $command, InputInterface $input, OutputInterface $output): void
    {
        if (!$this->shouldCheckForUpdates($command, $input, $output)) {
            return;
        }

        $latest = (new UpdateChecker(SelfUpdate::MANIFEST_URL, $this->getVersion(), $this->updateCacheFile()))
            ->latestIfNewer();

        if ($latest === null) {
            return;
        }

        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stderr->writeln(sprintf(
            '<comment>A new version of wpc is available: %s (you have %s). Run "wpc self-update" to update.</comment>',
            $latest,
            $this->getVersion()
        ));
    }

    private function shouldCheckForUpdates(Command $command, InputInterface $input, OutputInterface $output): bool
    {
        return $command->getName() !== 'self-update'
            && !Env::isTruthy('WPC_NO_UPDATE_CHECK')
            && !Env::isTruthy('CI')
            && !$this->runtime->isJson()
            && $input->isInteractive()
            && $output->getVerbosity() > OutputInterface::VERBOSITY_QUIET
            && $this->isReleaseVersion($this->getVersion())
            && Phar::running(false) !== ''
            && Environment::stderrIsTty($output);
    }

    private function isReleaseVersion(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+(\.\d+)?$/', $version);
    }

    private function updateCacheFile(): string
    {
        $base = Env::get('XDG_CACHE_HOME') ?: (Env::get('HOME') ?: sys_get_temp_dir()) . '/.cache';

        return $base . '/wpc/update-check.json';
    }
}
