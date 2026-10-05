<?php

namespace WpContent\Cli;

/**
 * What the current run resolved to: the configuration, and whether this run may
 * take over the terminal.
 *
 * It exists because of an ordering problem. Commands are registered — and now
 * receive their collaborators — when the application is constructed, but the
 * configuration only exists once an input has been parsed. Handing every command
 * this object at construction time, and filling it in at {@see Application::doRun()},
 * is what lets the dependencies be injected without waiting for the input.
 *
 * Every accessor answers with the documented default until then, so a caller
 * reached before resolution (rendering an exception thrown while parsing, say)
 * gets sane behaviour instead of an error about an unresolved runtime.
 */
final class Runtime
{
    private ?Config $config = null;

    private bool $interactive = false;

    public function resolve(Config $config, bool $interactive): void
    {
        $this->config = $config;
        $this->interactive = $interactive;
    }

    public function isResolved(): bool
    {
        return $this->config !== null;
    }

    public function outputFormat(): string
    {
        return $this->config !== null ? $this->config->outputFormat : Config::DEFAULT_OUTPUT;
    }

    /** Whether this run's output is the machine-readable contract. */
    public function isJson(): bool
    {
        return $this->outputFormat() === Config::OUTPUT_JSON;
    }

    /**
     * Whether this run may take over the terminal: a real terminal *and* an
     * output format that allows it.
     */
    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function repository(): string
    {
        return $this->config !== null ? $this->config->repository : Config::DEFAULT_REPOSITORY;
    }

    public function apiKey(): ?string
    {
        return $this->config?->apiKey;
    }

    /**
     * {@see Config::requirements()} for the resolved run. Before resolution
     * there is no key to speak of, so an authenticated caller is told so.
     *
     * @return list<string>
     */
    public function requirements(bool $authenticated = false): array
    {
        if ($this->config !== null) {
            return $this->config->requirements($authenticated);
        }

        return $authenticated ? [Config::MISSING_API_KEY] : [];
    }
}
