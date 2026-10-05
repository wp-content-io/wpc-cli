<?php

namespace WpContent\Cli\Builder;

trait EnsurePathTrait
{
    /**
     * Resolve a path to its realpath, optionally creating it as a directory.
     * Returns false when the path does not exist and could not be created.
     */
    protected function ensurePath(string $path, bool $create = false): string|false
    {
        if (file_exists($path)) {
            return realpath($path);
        }

        if ($create && @mkdir($path, 0777, true)) {
            return realpath($path);
        }

        return false;
    }
}
