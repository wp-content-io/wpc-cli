<?php

namespace WpContent\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Builder\BuildError;
use WpContent\Cli\ResourceType;
use WpContent\Cli\Results\SingleResult;
use WpContent\Cli\Results\ValueResult;
use WpContent\Cli\Wordpress\HeaderParser;

/**
 * Shared logic for `plugin manifest` / `theme manifest`. Reads the WordPress
 * header block of a plugin/theme main file.
 */
abstract class AbstractManifestCommand extends AbstractResourceCommand
{
    protected function configure(): void
    {
        $type = $this->resourceType();

        $this->setDescription('Get manifest from input file')
            ->addArgument('input', InputArgument::REQUIRED, "Path to the {$type->value} main file")
            ->addOption('get', null, InputOption::VALUE_REQUIRED, 'Print a single header value instead of the whole manifest');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputFile = (string) $input->getArgument('input');

        if (!is_file($inputFile)) {
            // It used to return INVALID and print nothing at all, so a typo in
            // the path looked exactly like a file with no headers.
            $error = BuildError::invalidInput("$inputFile not found");
            $this->router->display($output, $error);

            return $error->exitCode();
        }

        $data = match ($this->resourceType()) {
            ResourceType::Plugin => HeaderParser::plugin($inputFile),
            ResourceType::Theme => HeaderParser::theme($inputFile),
        };

        // Compared to null rather than tested for truth, so `--get 0` asks for
        // a header named "0" instead of falling through to the whole manifest.
        if (($property = $input->getOption('get')) !== null) {
            $property = (string) $property;

            // An empty answer with exit 0, as in 1.x: scripts capture this with
            // `$(wpc … --get "Requires PHP")` under `set -e`, and an optional
            // header a plugin does not declare must not abort them. Making it a
            // usage error (as a 2.0 pre-release did) broke exactly those.
            //
            // What is not there is still *said*, on stderr only, so a typo like
            // `--get Verison` does not go unnoticed. The parser fills every
            // field of the map, with an empty string for the ones the file does
            // not carry, hence two notices: an unknown field name, and a known
            // header the file leaves out.
            $value = array_key_exists($property, $data) ? (string) $data[$property] : '';

            if (!array_key_exists($property, $data)) {
                $this->notice($output, sprintf(
                    'No "%s" header field for a %s (known fields: %s); answering with an empty value.',
                    $property,
                    $this->resourceType()->value,
                    implode(', ', array_keys($data))
                ));
            } elseif ($value === '') {
                $this->notice($output, sprintf('No "%s" header in %s; answering with an empty value.', $property, $inputFile));
            }

            $this->router->display($output, new ValueResult($property, $value));

            return Command::SUCCESS;
        }

        $this->router->display($output, new SingleResult($data));

        return Command::SUCCESS;
    }

    /**
     * A diagnostic on stderr, never on stdout: the answer of `--get` is what a
     * script captures. Silent under `--output=json` (the empty value in the
     * object says it) and `-q`.
     */
    private function notice(OutputInterface $output, string $message): void
    {
        if (!$output instanceof ConsoleOutputInterface
            || $this->runtime->isJson()
            || $output->getVerbosity() <= OutputInterface::VERBOSITY_QUIET
        ) {
            return;
        }

        $output->getErrorOutput()->writeln('<comment>wpc: ' . OutputFormatter::escape($message) . '</comment>');
    }
}
