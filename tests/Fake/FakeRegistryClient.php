<?php

namespace WpContent\Cli\Tests\Fake;

use WpContent\Cli\Api\ApiError;
use WpContent\Cli\Api\RegistryClient;

/**
 * The registry, in memory.
 *
 * The four commands that talk to it — list, info, push, init — had no test at
 * all, because reaching them meant reaching through the application to a Guzzle
 * singleton. They now take a {@see RegistryClient}, and this is the one the
 * tests hand them.
 */
final class FakeRegistryClient implements RegistryClient
{
    /** @var array<string, mixed> endpoint => decoded response */
    private array $responses = [];

    /** @var array<string, ApiError> endpoint => what it fails with */
    private array $failures = [];

    /** @var array<string, mixed> */
    private array $author = [];

    /** @var list<array{endpoint: string, files: array<string, string>, fields: array<string, string>}> */
    public array $uploads = [];

    /** @var list<string> every endpoint asked for, in order */
    public array $calls = [];

    public function willReturn(string $endpoint, mixed $response): self
    {
        $this->responses[$endpoint] = $response;

        return $this;
    }

    public function willFail(string $endpoint, ApiError $error): self
    {
        $this->failures[$endpoint] = $error;

        return $this;
    }

    /**
     * @param array<string, mixed> $author
     */
    public function withAuthor(array $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function get(string $endpoint): mixed
    {
        $this->calls[] = $endpoint;

        if (isset($this->failures[$endpoint])) {
            throw $this->failures[$endpoint];
        }

        if (!array_key_exists($endpoint, $this->responses)) {
            throw new ApiError(404);
        }

        return $this->responses[$endpoint];
    }

    public function upload(string $endpoint, array $files = [], array $fields = [], ?callable $onProgress = null): mixed
    {
        $this->calls[] = $endpoint;
        $this->uploads[] = ['endpoint' => $endpoint, 'files' => $files, 'fields' => $fields];

        if ($onProgress !== null) {
            // Enough to exercise the reporting: a partial tick, then completion.
            $onProgress(50, 100);
            $onProgress(100, 100);
        }

        if (isset($this->failures[$endpoint])) {
            throw $this->failures[$endpoint];
        }

        return $this->responses[$endpoint] ?? ['status' => 'ok'];
    }

    public function author(): array
    {
        return $this->author;
    }
}
