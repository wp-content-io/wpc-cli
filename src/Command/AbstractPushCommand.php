<?php

namespace WpContent\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Api\ApiError;
use WpContent\Cli\Builder\BuildError;
use WpContent\Cli\Results\SingleResult;

/**
 * Shared logic for `plugin push` / `theme push`.
 */
abstract class AbstractPushCommand extends AbstractResourceCommand
{
    protected function configure(): void
    {
        $this->setDescription('Push input zip to repository')
            ->addArgument('artifact', InputArgument::REQUIRED, 'Path to zip file')
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Release notes for this version')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'Remove zip file after uploading');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($exit = $this->unmetRequirements($output)) !== null) {
            return $exit;
        }

        $type = $this->resourceType();
        $artifact = (string) $input->getArgument('artifact');

        if (!file_exists($artifact)) {
            $error = BuildError::invalidInput("Artifact $artifact not found");
            $this->router->display($output, $error);

            return $error->exitCode();
        }

        // The upload reports through the same interface as the build, so the
        // rendering decision is made in one place and the transport knows
        // nothing about it.
        $progress = $this->progress($output);
        $progress->step(sprintf('Uploading %s', basename($artifact)));

        try {
            $fields = [];
            if ($message = $input->getOption('message')) {
                $fields['release_notes'] = (string) $message;
            }

            $result = $this->registry->upload(
                $type->collectionPath(),
                [$type->uploadField() => $artifact],
                $fields,
                static function (int $uploaded, int $total) use ($progress): void {
                    $progress->detail($total > 0 ? sprintf('%d%%', (int) round($uploaded / $total * 100)) : '');
                }
            );

            if ($input->getOption('clean')) {
                @unlink($artifact);
            }

            $progress->step('Published');
            $progress->finish();

            $this->router->display($output, new SingleResult($result));

            return Command::SUCCESS;
        } catch (ApiError $e) {
            $progress->fail($e->getMessage());
            $this->router->display($output, $e);

            // FAILURE, like `list` and `info`: the same cause has to produce the
            // same code. INVALID stays for what it means — a command that was
            // called wrongly, such as the missing artifact above.
            return Command::FAILURE;
        }
    }
}
