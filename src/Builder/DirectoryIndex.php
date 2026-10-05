<?php

namespace WpContent\Cli\Builder;

use FilesystemIterator;
use Iterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Iterates the files of a source directory, skipping anything matched by a
 * `.wpcignore` file (gitignore-like syntax, including negation and globs).
 *
 * @implements Iterator<int, array{relative: string, absolute: string}>
 */
class DirectoryIndex implements Iterator
{
    protected int $iteratorPosition = 0;

    protected string $root;

    protected string $ignoreFile = '.wpcignore';

    /** @var array<string, bool> pattern => isNegation */
    protected array $rules = [];

    /** @var list<SplFileInfo> */
    protected array $files = [];

    /** @var list<string> relative paths of kept directories that hold no file */
    protected array $emptyDirectories = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\') . '/';

        // Load every ignore file in the tree (root first, then any depth) before
        // filtering, so all rules are known. A plain glob('**/') only reaches one
        // level deep, so nested ignore files were previously never applied.
        foreach ($this->scan() as $entry) {
            if ($entry->isFile() && $entry->getFilename() === $this->ignoreFile) {
                $this->processIgnoreFile($entry->getPathname());
            }
        }

        // Collect the surviving files and every directory, so kept-but-empty
        // directories can still be added to the archive.
        $directories = [];
        foreach ($this->scan() as $entry) {
            if ($entry->isDir()) {
                $directories[] = $entry->getPathname();
            } elseif ($entry->isFile() && !$this->isFileIgnored($entry->getPathname())) {
                $this->files[] = $entry;
            }
        }

        $this->collectEmptyDirectories($directories);
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function scan(): iterable
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
    }

    /**
     * Record directories that survive the ignore rules but contain no included
     * file, so they are not silently dropped from the archive.
     *
     * @param list<string> $directories absolute directory paths
     */
    private function collectEmptyDirectories(array $directories): void
    {
        $rootPath = rtrim($this->root, '/\\');

        // Any ancestor directory of an included file is created implicitly by that file.
        $withFiles = [];
        foreach ($this->files as $file) {
            $dir = dirname($file->getPathname());
            while (strlen($dir) >= strlen($rootPath)) {
                $withFiles[$dir] = true;
                if ($dir === $rootPath) {
                    break;
                }
                $dir = dirname($dir);
            }
        }

        foreach ($directories as $dir) {
            // Test the directory with a trailing slash so directory ignore rules match.
            if (isset($withFiles[$dir]) || $this->isFileIgnored(rtrim($dir, '/\\') . '/')) {
                continue;
            }
            $this->emptyDirectories[] = $this->getRelativePath($dir);
        }
    }

    /**
     * Relative paths of kept directories that hold no file (and would otherwise vanish).
     *
     * @return list<string>
     */
    public function emptyDirectories(): array
    {
        return $this->emptyDirectories;
    }

    protected function getRelativePath(string $path): string
    {
        return str_replace($this->root, '', $path);
    }

    protected function processIgnoreFile(string $filepath): void
    {
        $root = dirname($filepath);

        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Negation rules (!)
            $isNegation = str_starts_with($line, '!');
            if ($isNegation) {
                $line = substr($line, 1);
            }

            // A leading slash anchors the pattern to this ignore file's directory.
            // Strip it up front so the escaping and wildcard translation below still
            // apply — the old rooted branch discarded the translated pattern and
            // re-used the raw line, producing a broken regex.
            $rooted = str_starts_with($line, '/');
            $relative = $rooted ? substr($line, 1) : $line;

            // Escape regex metacharacters, then translate the glob wildcards.
            $pattern = strtr(preg_quote($relative), [
                '\*\*/' => '.*/', // Match any directory depth
                '\*\*' => '.*',   // Match anything
                '\*' => '[^/]*',  // Match anything except /
                '\?' => '[^/]',   // Match a single character except /
            ]);

            // A pattern without a leading slash can match at any depth.
            if (!$rooted) {
                $pattern = "(.*/)?$pattern";
            }

            // Anchor to the ignore file's directory
            $pattern = '^' . preg_quote($root) . '/' . $pattern;

            // Directory rules match everything below them; file rules match a file or a directory
            if (str_ends_with($line, '/')) {
                $pattern .= '.*';
            } else {
                $pattern .= '(?:$|/.*)';
            }

            $this->rules["#$pattern#"] = $isNegation;
        }
    }

    protected function isFileIgnored(string $filepath): bool
    {
        if (basename($filepath) === $this->ignoreFile) {
            return true;
        }

        $ignored = false;

        foreach ($this->rules as $pattern => $isNegation) {
            if (preg_match($pattern, $filepath)) {
                $ignored = !$isNegation;
            }
        }

        return $ignored;
    }

    /**
     * @return array{relative: string, absolute: string}
     */
    public function current(): array
    {
        $file = $this->files[$this->iteratorPosition];

        return [
            'relative' => $this->getRelativePath($file->getPathname()),
            'absolute' => $file->getPathname(),
        ];
    }

    public function next(): void
    {
        ++$this->iteratorPosition;
    }

    public function key(): int
    {
        return $this->iteratorPosition;
    }

    public function valid(): bool
    {
        return isset($this->files[$this->iteratorPosition]);
    }

    public function rewind(): void
    {
        $this->iteratorPosition = 0;
    }
}
