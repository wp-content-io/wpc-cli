<?php

namespace WpContent\Cli\Command;

use LogicException;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Builder\Builder;
use WpContent\Cli\Builder\BuildError;
use WpContent\Cli\Results\SingleResult;

/**
 * Shared logic for `plugin build` / `theme build`.
 */
abstract class AbstractBuildCommand extends AbstractResourceCommand
{
    /** Where a build lands when nothing says otherwise — also where `push` looks. */
    public const DEFAULT_OUTPUT_DIR = '../dist';

    protected function configure(): void
    {
        $type = $this->resourceType();

        $this->setDescription('Generate zip file to export')
            ->addArgument('input', InputArgument::OPTIONAL, "Input directory container {$type->value} sources", getcwd())
            ->addOption('slug', 's', InputOption::VALUE_REQUIRED, "{$type->label()} slug (if no slug is provided, current directory will be used)")
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for the ZIP file', self::DEFAULT_OUTPUT_DIR)
            ->addOption('filename', 'f', InputOption::VALUE_REQUIRED, 'Zip filename')
            ->addOption('header', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, "Override {$type->value} header");

        // Push options
        $this->addOption('push', 'p', InputOption::VALUE_NONE, 'Push zip to repository')
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Release notes for this version')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'Remove zip file after uploading');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $this->resourceType();
        $sourceDir = (string) $input->getArgument('input');
        $outputDir = (string) ($input->getOption('output-dir') ?? self::DEFAULT_OUTPUT_DIR);

        // Only a build that is going to push needs the key — and it needs to
        // know before the archive is built, not after.
        if ($input->getOption('push') && ($exit = $this->unmetRequirements($output)) !== null) {
            return $exit;
        }

        // Held rather than passed inline, so the failure path can close the step
        // it was drawing instead of leaving its spinner turning.
        $progress = $this->progress($output);

        try {
            $builder = Builder::for($type, $this->slug($input, $sourceDir), $this->filename($input));
            foreach ((array) $input->getOption('header') as $header) {
                // Split on the first '=' only, so values may contain '=' (e.g. URLs with a query string).
                [$name, $value] = array_pad(explode('=', (string) $header, 2), 2, null);

                // Refused rather than passed on: `--header "Version: 1.0.1"` was
                // taken for a header literally named "Version: 1.0.1", matched
                // nothing, and the build went out — then the push — with the
                // version from the file, which is the one the override was there
                // to replace.
                if ($value === null || trim((string) $name) === '') {
                    throw BuildError::invalidInput(sprintf('Malformed --header "%s": expected Name=value, e.g. --header="Version=1.2.3".', $header));
                }

                $builder->setHeader((string) $name, $value);
            }
            $artifact = $builder->generate($sourceDir, $outputDir, $progress);
        } catch (BuildError $e) {
            $progress->fail($e->getMessage());
            $this->router->display($output, $e);

            // The failure carries its own code: a source directory that is not
            // there is a usage error (2), an archive that would not close is not.
            return $e->exitCode();
        }

        if ($input->getOption('push')) {
            $arguments = [
                'command' => $type->value . ' push',
                'artifact' => $artifact,
            ];

            if ($message = $input->getOption('message')) {
                $arguments['--message'] = $message;
            }

            if ($input->getOption('clean')) {
                $arguments['--clean'] = true;
            }

            return $this->application()->doRun(new ArrayInput($arguments), $output);
        }

        $this->router->display($output, new SingleResult(['artifact' => $artifact]));

        return Command::SUCCESS;
    }

    /**
     * The slug the archive is built under: the option when there is one, the
     * source directory's own name otherwise.
     *
     * @throws BuildError when the option was given no value, or the directory
     *                    the slug would be guessed from does not exist
     */
    private function slug(InputInterface $input, string $sourceDir): string
    {
        $slug = $input->getOption('slug');

        // Before the slug is guessed, not after: realpath() of a directory that
        // is not there returns false, so the guess was `basename('')` — an empty
        // slug, and an archive whose entries began with a "/".
        if ($slug === null) {
            return $this->slugFromDirectory($sourceDir);
        }

        $slug = trim((string) $slug);

        // `--slug=` is what a CI job produces from a variable it never set.
        // Guessing from the directory here would build the right archive under
        // the wrong name and push it as another resource, so it is a usage
        // error — the same one `--slug` with no value at all would be.
        if ($slug === '') {
            throw BuildError::invalidInput('--slug was given no value.');
        }

        return $slug;
    }

    /**
     * @throws BuildError when the option was given no value, or a path
     */
    private function filename(InputInterface $input): ?string
    {
        $filename = $input->getOption('filename');

        if ($filename === null) {
            return null;
        }

        $filename = trim((string) $filename);

        // Empty, this built `$outputDir . '/' . ''` and failed on "Could not
        // create … zip archive", which names the directory rather than the
        // option that was actually wrong.
        if ($filename === '') {
            throw BuildError::invalidInput('--filename was given no value.');
        }

        // The archive is written inside --output-dir; a separator would put it
        // somewhere else entirely, which no build ever means to say.
        if (basename($filename) !== $filename) {
            throw BuildError::invalidInput("--filename must be a file name, not a path: \"$filename\".");
        }

        return $filename;
    }

    /**
     * The directory's own name, which is what a plugin built in place is called.
     *
     * @throws BuildError when the directory does not exist
     */
    private function slugFromDirectory(string $sourceDir): string
    {
        $path = realpath($sourceDir);

        if ($path === false || !is_dir($path)) {
            throw BuildError::invalidInput("$sourceDir is not a valid directory");
        }

        return basename($path);
    }

    /**
     * `build --push` hands the artifact to the push command rather than
     * duplicating the upload, which means going back through the application —
     * the one thing here that genuinely needs it.
     */
    private function application(): SymfonyApplication
    {
        $application = $this->getApplication();

        if ($application === null) {
            throw new LogicException('Command is not attached to an application.');
        }

        return $application;
    }
}
