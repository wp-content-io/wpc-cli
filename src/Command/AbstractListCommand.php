<?php

namespace WpContent\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Api\ApiError;
use WpContent\Cli\Builder\BuildError;
use WpContent\Cli\Results\TableResult;
use WpContent\Cli\Tui\Component\Component;
use WpContent\Cli\Tui\Component\DetailView;
use WpContent\Cli\Tui\Page;

/**
 * Shared logic for `plugin list` / `theme list`.
 */
abstract class AbstractListCommand extends AbstractResourceCommand
{
    protected function configure(): void
    {
        $this->setDescription($this->listDescription())
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page number')
            ->addOption('per-page', 'c', InputOption::VALUE_REQUIRED, 'Number of items per page');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($exit = $this->unmetRequirements($output)) !== null) {
            return $exit;
        }

        try {
            $number = $this->positiveInteger($input, 'page', 1);
            $perPage = $this->positiveInteger($input, 'per-page', ResourceCatalog::DEFAULT_PER_PAGE);
        } catch (BuildError $e) {
            $this->router->display($output, $e);

            return $e->exitCode();
        }

        $catalog = $this->catalog();

        try {
            $page = $catalog->page($number, $perPage);

            $result = new TableResult($page->rows, $this->resourceType()->listColumns());
            $result->setTitle($this->resourceType()->label() . 's');

            // Further pages are fetched only if the user walks into them, and
            // Enter drills into the highlighted row.
            $result->setPager(
                $page,
                static fn (int $number): ?Page => $catalog->pageOrNull($number, $perPage),
                fn (array $row): ?Component => $this->detailFor($catalog, $row),
            );

            $this->router->display($output, $result);

            return Command::SUCCESS;
        } catch (ApiError $e) {
            $this->router->display($output, $e);

            return Command::FAILURE;
        }
    }

    /**
     * A pagination option, or a refusal to guess.
     *
     * `--per-page=abc` used to cast to 0 and travel all the way to the API as
     * `per_page=0`, whose answer is anyone's guess; `--page=-1` was silently
     * clamped. Both are typos, and a typo deserves to be named.
     *
     * @throws BuildError
     */
    private function positiveInteger(InputInterface $input, string $option, int $default): int
    {
        $value = $input->getOption($option);

        if ($value === null) {
            return $default;
        }

        if (!is_numeric($value) || (int) $value != $value || (int) $value < 1) {
            throw BuildError::invalidInput(sprintf('--%s must be a positive integer, got "%s".', $option, (string) $value));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function detailFor(ResourceCatalog $catalog, array $row): ?Component
    {
        $slug = $row['slug'] ?? null;

        if (!is_string($slug) || $slug === '') {
            return null;
        }

        $record = $catalog->record($slug);

        if ($record === null) {
            return null;
        }

        return new DetailView($record, $this->resourceType()->detailColumns(), $this->detailTitle($record, $slug));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function detailTitle(array $record, string $slug): string
    {
        $name = $record['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $slug;
    }

    private function listDescription(): string
    {
        return sprintf('List %s on the remote repository', $this->resourceType()->collection());
    }
}
