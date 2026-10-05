<?php

namespace WpContent\Cli\Command;

use WpContent\Cli\Api\ApiError;
use WpContent\Cli\Api\RegistryClient;
use WpContent\Cli\ResourceType;
use WpContent\Cli\Tui\Page;

/**
 * Reads a resource collection off the API.
 *
 * Pulled out of `list` so `info` can browse the same pages when it is asked for
 * a resource without being told which one.
 */
final class ResourceCatalog
{
    public const DEFAULT_PER_PAGE = 36;

    public function __construct(
        private readonly RegistryClient $registry,
        private readonly ResourceType $type,
    ) {
    }

    /**
     * @throws ApiError
     */
    public function page(int $number, int $perPage = self::DEFAULT_PER_PAGE): Page
    {
        $query = http_build_query(['page' => max(1, $number), 'per_page' => $perPage]);

        return Page::fromResponse($this->registry->get($this->type->collectionPath() . "?$query"), $this->type->collection());
    }

    /**
     * Same, swallowing API errors: a failed page turn inside the TUI must show a
     * message, not tear the screen down.
     */
    public function pageOrNull(int $number, int $perPage = self::DEFAULT_PER_PAGE): ?Page
    {
        try {
            return $this->page($number, $perPage);
        } catch (ApiError) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function record(string $slug): ?array
    {
        try {
            $response = $this->registry->get($this->type->recordPath($slug));
        } catch (ApiError) {
            return null;
        }

        if (!is_array($response)) {
            return null;
        }

        /** @var array<string, mixed> $response */
        return $response;
    }
}
