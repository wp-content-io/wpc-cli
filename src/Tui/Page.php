<?php

namespace WpContent\Cli\Tui;

/**
 * One page of a listing, as the API describes it.
 *
 * The registry returns `info: {page, pages, results}` alongside the rows; the
 * three are kept together here so the footer can never again show the item
 * count where it announces a page count.
 */
final class Page
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $number = 1,
        public readonly int $pages = 1,
        public readonly int $total = 0,
    ) {
    }

    /**
     * Build a page from a listing response.
     *
     * @param array<string, mixed> $response
     */
    public static function fromResponse(mixed $response, string $collection): self
    {
        $response = is_array($response) ? $response : [];
        $rows = $response[$collection] ?? [];
        $info = is_array($response['info'] ?? null) ? $response['info'] : [];

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

        return new self(
            $rows,
            max(1, (int) ($info['page'] ?? 1)),
            max(1, (int) ($info['pages'] ?? 1)),
            (int) ($info['results'] ?? count($rows)),
        );
    }
}
