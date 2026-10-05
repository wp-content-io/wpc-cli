<?php

namespace WpContent\Cli;

use Symfony\Component\Console\Input\InputInterface;

class Config
{
    public const DEFAULT_REPOSITORY = 'https://registry.wp-content.io';

    /** Interactive on a terminal, plain tables everywhere else. */
    public const OUTPUT_HUMAN = 'human';

    /** The plain tables, even on a terminal. */
    public const OUTPUT_PLAIN = 'plain';

    /** Raw machine-readable output; never decorated, never interactive. */
    public const OUTPUT_JSON = 'json';

    public const OUTPUT_FORMATS = [self::OUTPUT_HUMAN, self::OUTPUT_PLAIN, self::OUTPUT_JSON];

    public const DEFAULT_OUTPUT = self::OUTPUT_HUMAN;

    public const MISSING_REPOSITORY = 'Repository is not set. Use --repository option or WPC_REPO_URL environment variable.';

    public const MISSING_API_KEY = 'API key is not set. Use --api-key option or WPC_API_KEY environment variable.';

    public readonly string $repository;

    public readonly ?string $apiKey;

    public readonly string $outputFormat;

    public function __construct(InputInterface $input)
    {
        $this->repository = $this->option($input, '--repository')
            ?? $this->env('WPC_REPO_URL', self::DEFAULT_REPOSITORY)
            ?? self::DEFAULT_REPOSITORY;
        $this->apiKey = $this->option($input, '--api-key') ?? $this->env('WPC_API_KEY');
        $this->outputFormat = $this->option($input, '--output') ?? self::DEFAULT_OUTPUT;
    }

    /**
     * Whether this format lets the CLI take the terminal over — for a table, a
     * progress bar or a question alike.
     *
     * Only `human` does. `plain` exists precisely to say "the static rendering
     * even on a terminal", and `json` is a contract: neither may be answered
     * with a screen the caller cannot read.
     */
    public function allowsInteractive(): bool
    {
        return $this->outputFormat === self::OUTPUT_HUMAN;
    }

    /**
     * What is wrong with the invocation itself — a malformed option, which is
     * a usage error whatever the run then goes on to do.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        // An unknown format used to fall through to the human rendering unnoticed,
        // which silently produced tables for a script expecting machine output.
        if (!in_array($this->outputFormat, self::OUTPUT_FORMATS, true)) {
            return [sprintf(
                'Unknown output format "%s". Supported formats are: %s.',
                $this->outputFormat,
                implode(', ', self::OUTPUT_FORMATS)
            )];
        }

        return [];
    }

    /**
     * What a command needs in order to run, as opposed to what is wrong with
     * the command line.
     *
     * Kept apart from {@see validate()} because the two are not fatal at the
     * same moment: `wpc --version` answers without a repository, and an
     * installer or a CI preflight step checks the binary that way long before
     * anything is configured.
     *
     * `$authenticated` adds the API key, for the commands that cannot get an
     * answer without one (`list`, `info`, `push`, `build --push`). Not checked
     * for everything: `init` only uses the registry to pre-fill the author, and
     * `build`, `manifest` and `self-update` never call it. Asked for anyway,
     * the registry answered a missing key with "Organization not found" — a
     * round trip to production to learn something known before leaving.
     *
     * @return list<string>
     */
    public function requirements(bool $authenticated = false): array
    {
        if ($this->repository === '') {
            return [self::MISSING_REPOSITORY];
        }

        if ($authenticated && ($this->apiKey === null || $this->apiKey === '')) {
            return [self::MISSING_API_KEY];
        }

        return [];
    }

    /**
     * Read a global option straight from the raw tokens so its position on the
     * command line does not matter. The application binds only its own global
     * options before a command runs, which would otherwise silently drop a
     * global option placed after a command-specific one.
     */
    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getParameterOption($name);

        return is_string($value) ? $value : null;
    }

    private function env(string $name, ?string $default = null): ?string
    {
        return Env::get($name) ?? $default;
    }
}
