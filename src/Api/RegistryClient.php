<?php

namespace WpContent\Cli\Api;

/**
 * The registry, as the commands need it.
 *
 * Deliberately free of any console type: the transport reports progress through
 * a callback and says nothing else, so how a run is rendered stays entirely the
 * caller's business — the same separation {@see \WpContent\Cli\Builder\BuildProgress}
 * already gives the builder.
 */
interface RegistryClient
{
    /**
     * @throws ApiError
     */
    public function get(string $endpoint): mixed;

    /**
     * @param array<string, string>           $files      field name => file path
     * @param array<string, string>           $fields     field name => value
     * @param (callable(int, int): void)|null $onProgress receives (uploaded, total) bytes
     *
     * @throws ApiError
     */
    public function upload(string $endpoint, array $files = [], array $fields = [], ?callable $onProgress = null): mixed;

    /**
     * Who the API key belongs to, used to pre-fill the `init` wizard.
     *
     * Best effort by design — it runs *before* the first question, so a registry
     * that is slow or unreachable must cost a few seconds of missing defaults,
     * never a wizard that appears to hang. It answers with an empty array rather
     * than throwing.
     *
     * @return array<string, mixed>
     */
    public function author(): array;
}
