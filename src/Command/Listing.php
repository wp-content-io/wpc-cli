<?php

namespace WpContent\Cli\Command;

use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Application;
use WpContent\Cli\ResourceType;
use WpContent\Cli\Results\ListingResult;
use WpContent\Cli\Results\OutputRouter;
use WpContent\Cli\Tui\Text;

/**
 * The screen behind `wpc`, `wpc list` and `wpc plugin`.
 *
 * Symfony's own listing sorts every command into one alphabetical block, which
 * buries the two that matter under `completion` and `help`, and spells the
 * aliases out inline. This replaces it with the shape docker made familiar:
 * the nouns first, the standalone commands after.
 *
 * Anything that is not the default text rendering (`--format=json|xml|md`,
 * `--raw`, `--short`) falls through to the parent untouched — that output is a
 * machine contract, same as `--output=json`.
 */
class Listing extends ListCommand
{
    public function __construct(private readonly OutputRouter $router)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('format') !== 'txt' || $input->getOption('raw') || $input->getOption('short')) {
            return parent::execute($input, $output);
        }

        $namespace = $input->getArgument('namespace');

        if (is_string($namespace) && ResourceType::tryFrom($namespace) !== null) {
            $this->router->display($output, $this->namespaceListing($namespace));

            return self::SUCCESS;
        }

        if ($namespace !== null) {
            return parent::execute($input, $output);
        }

        $this->router->display($output, $this->applicationListing());

        return self::SUCCESS;
    }

    private function applicationListing(): ListingResult
    {
        $application = $this->application();
        $usage = 'wpc COMMAND [options] [arguments]';

        $namespaces = [];
        foreach (ResourceType::cases() as $type) {
            $namespaces[$type->value] = $type->manageDescription();
        }

        $standalone = [];
        foreach ($application->all() as $name => $command) {
            // Aliases, and the verbs already covered by the section above.
            if ($command->getName() !== $name || $command->isHidden() || str_contains($name, ' ')) {
                continue;
            }
            $standalone[$name] = $command->getDescription();
        }
        ksort($standalone);

        // Commands share a column so the two lists read as one; the options,
        // whose labels are three times longer, keep their own.
        $commandWidth = $this->labelWidth($namespaces, $standalone);
        $options = $this->globalOptions();

        $lines = [
            '<comment>Usage:</comment>',
            "  $usage",
            '',
            'Distribute and update private WordPress plugins and themes.',
            '',
            ...$this->section('Management commands', $namespaces, $commandWidth),
            ...$this->section('Commands', $standalone, $commandWidth),
            ...$this->section('Global options', $options, $this->labelWidth($options)),
            'Run <info>wpc COMMAND --help</info> for more information on a command.',
        ];

        return new ListingResult($lines, [
            'usage' => $usage,
            // Every command, rather than the two nouns the screen groups them
            // under: a wrapper reading this wants the names it can run.
            'commands' => $this->commandPayload($application->all()),
            'options' => $this->optionPayload(),
        ]);
    }

    private function namespaceListing(string $namespace): ListingResult
    {
        $application = $this->application();
        $usage = "wpc $namespace COMMAND [options] [arguments]";

        $rows = [];
        $commands = [];
        foreach ($application->verbs($namespace) as $name) {
            $command = $application->get($name);
            $rows[substr($name, strlen($namespace) + 1)] = $command->getDescription();
            $commands[$name] = $command;
        }

        $lines = [
            '<comment>Usage:</comment>',
            "  $usage",
            '',
            ...$this->section('Commands', $rows, $this->labelWidth($rows)),
            "Run <info>wpc $namespace COMMAND --help</info> for more information on a command.",
        ];

        return new ListingResult($lines, [
            'usage' => $usage,
            'commands' => $this->commandPayload($commands),
            'options' => $this->optionPayload(),
        ]);
    }

    /**
     * @param array<string, Command> $commands
     *
     * @return list<array{name: string, description: string, aliases: list<string>}>
     */
    private function commandPayload(array $commands): array
    {
        $payload = [];

        foreach ($commands as $name => $command) {
            // `all()` keys the aliases at the same command; keep the canonical
            // name once, with its aliases beside it rather than as entries.
            if ($command->getName() !== $name || $command->isHidden()) {
                continue;
            }

            $payload[] = [
                'name' => $name,
                'description' => $command->getDescription(),
                'aliases' => array_values($command->getAliases()),
            ];
        }

        usort($payload, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $payload;
    }

    /**
     * @return list<array{name: string, shortcut: string|null, description: string, accepts_value: bool}>
     */
    private function optionPayload(): array
    {
        $definition = $this->application()->getDefinition();
        $payload = [];

        foreach ($this->optionNames() as $name) {
            $option = $definition->getOption($name);

            $payload[] = [
                'name' => '--' . $option->getName(),
                'shortcut' => $option->getShortcut() !== null ? '-' . $option->getShortcut() : null,
                'description' => $option->getDescription(),
                'accepts_value' => $option->acceptValue(),
            ];
        }

        return $payload;
    }

    /**
     * The wpc options first: they are the ones a reader is here for, and
     * Symfony's own order buries them under `--silent` and `--quiet`.
     *
     * @return list<string>
     */
    private function optionNames(): array
    {
        $ours = ['output', 'repository', 'api-key'];
        $names = array_keys($this->application()->getDefinition()->getOptions());

        // Deduplicated: ours are named twice on purpose, to be pulled to the
        // front. Two identical entries collapse in a map keyed by label, but a
        // JSON list would happily carry both.
        return array_values(array_unique([...$ours, ...$names]));
    }

    /**
     * @return array<string, string>
     */
    private function globalOptions(): array
    {
        $definition = $this->application()->getDefinition();
        $rows = [];

        foreach ($this->optionNames() as $name) {
            $option = $definition->getOption($name);
            $label = $option->getShortcut() !== null ? '-' . $option->getShortcut() . ', ' : '    ';
            $label .= '--' . $option->getName();

            if ($option->isNegatable()) {
                $label .= '|--no-' . $option->getName();
            }

            if ($option->acceptValue()) {
                $label .= '=' . strtoupper($option->getName());
            }

            $rows[$label] = $option->getDescription();
        }

        return $rows;
    }

    /**
     * @param array<string, string> ...$sections
     */
    private function labelWidth(array ...$sections): int
    {
        $width = 0;

        foreach ($sections as $rows) {
            foreach (array_keys($rows) as $label) {
                $width = max($width, Text::width((string) $label));
            }
        }

        return $width;
    }

    /**
     * @param array<string, string> $rows label => description
     *
     * @return list<string>
     */
    private function section(string $title, array $rows, int $width): array
    {
        if ($rows === []) {
            return [];
        }

        $lines = ["<comment>$title:</comment>"];

        foreach ($rows as $label => $description) {
            $lines[] = sprintf('  <info>%s</info>  %s', Text::cell($label, $width), $description);
        }

        $lines[] = '';

        return $lines;
    }

    private function application(): Application
    {
        $application = $this->getApplication();

        if (!$application instanceof Application) {
            throw new LogicException('Command is not attached to the wpc application.');
        }

        return $application;
    }
}
