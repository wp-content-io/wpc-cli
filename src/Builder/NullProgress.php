<?php

namespace WpContent\Cli\Builder;

/**
 * Reports nothing. Used under `--output=json`, where any extra byte on stdout
 * would corrupt the machine-readable payload.
 */
final class NullProgress implements BuildProgress
{
    public function step(string $label): void
    {
    }

    public function detail(string $detail): void
    {
    }

    public function file(string $path): void
    {
    }

    public function finish(): void
    {
    }

    public function fail(string $message): void
    {
    }
}
