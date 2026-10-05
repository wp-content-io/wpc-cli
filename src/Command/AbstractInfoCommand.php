<?php

namespace WpContent\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WpContent\Cli\Api\ApiError;
use WpContent\Cli\Results\SingleResult;

/**
 * Shared logic for `plugin info` / `theme info`.
 */
abstract class AbstractInfoCommand extends AbstractResourceCommand
{
    protected function configure(): void
    {
        $type = $this->resourceType();
        $this->setDescription("Get {$type->value} information from distant repository")
            ->addArgument('slug', InputArgument::REQUIRED, "{$type->label()} slug");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($exit = $this->unmetRequirements($output)) !== null) {
            return $exit;
        }

        $type = $this->resourceType();

        try {
            $slug = (string) $input->getArgument('slug');
            $response = $this->registry->get($type->recordPath($slug));

            $result = new SingleResult($response, $type->detailColumns());
            $result->navigable()->setTitle($this->detailTitle($response, $slug, $type->label()));
            $this->router->display($output, $result);

            return Command::SUCCESS;
        } catch (ApiError $e) {
            $this->router->display($output, $e);

            return Command::FAILURE;
        }
    }

    /**
     * Prefer the resource's own name as the panel title, falling back to the
     * slug the user typed.
     */
    private function detailTitle(mixed $response, string $slug, string $label): string
    {
        $name = is_array($response) ? ($response['name'] ?? null) : null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $slug !== '' ? $slug : "$label details";
    }
}
