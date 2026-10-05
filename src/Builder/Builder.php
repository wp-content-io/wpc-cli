<?php

namespace WpContent\Cli\Builder;

use Closure;
use WpContent\Cli\ResourceType;
use WpContent\Cli\Wordpress\HeaderParser;
use ZipArchive;

class Builder
{
    use EnsurePathTrait;

    public const MISSING_ZIP_MESSAGE = 'The zip PHP extension is required to build an archive.';

    /** @var array<string, string> */
    private array $replacementHeaders = [];

    /** @var Closure(): bool */
    private readonly Closure $zipAvailable;

    /**
     * The archive is no longer opened here: `new ZipArchive()` in the
     * constructor was a fatal `Class "ZipArchive" not found` on any PHP built
     * without ext-zip — no message the user could act on, no JSON under
     * `--output=json`, and an exit status of 255.
     *
     * @param (callable(): bool)|null $zipAvailable whether ext-zip is loaded;
     *                                              only the tests answer it
     */
    private function __construct(
        private readonly ResourceType $type,
        private readonly string $slug,
        private readonly string $filename,
        ?callable $zipAvailable = null,
    ) {
        $this->zipAvailable = $zipAvailable !== null
            ? Closure::fromCallable($zipAvailable)
            : static fn (): bool => class_exists(ZipArchive::class);
    }

    /**
     * @param (callable(): bool)|null $zipAvailable see the constructor
     */
    public static function for(ResourceType $type, string $slug, ?string $filename = null, ?callable $zipAvailable = null): self
    {
        return new self($type, $slug, $filename ?? "$slug.zip", $zipAvailable);
    }

    public function setHeader(string $name, string $value): self
    {
        $this->replacementHeaders[$name] = $value;

        return $this;
    }

    /**
     * Build the resource zip archive and return its path.
     *
     * @throws BuildError when the sources, output directory or archive cannot be prepared
     */
    public function generate(string $sourceDir, string $outputDir, BuildProgress $progress): string
    {
        $progress->step('Reading sources');

        // Usage errors, wherever the caller enters from: `build` guesses the
        // slug from the source directory and catches a missing one on the way,
        // but `--slug` skips that guess entirely — and the same typo answering
        // 2 without the option and 1 with it is exactly what a pipeline
        // branching on the exit code cannot survive.
        $sourcePath = $this->ensurePath($sourceDir);
        if (!is_string($sourcePath) || !is_dir($sourcePath)) {
            throw BuildError::invalidInput("$sourceDir is not a valid directory");
        }

        $outputPath = $this->ensurePath($outputDir, true);
        if (!is_string($outputPath) || !is_dir($outputPath)) {
            throw BuildError::invalidInput("Could not create $outputDir directory");
        }

        $mainFile = $this->findMainFile($sourcePath);
        if ($mainFile === null) {
            throw new BuildError('Main file is missing');
        }

        // Checked once the input is known to be right, so a missing directory is
        // still the usage error (2) it is everywhere else, whatever PHP runs it.
        // This one is a plain failure (1): the command was called rightly, on a
        // PHP that cannot do the job.
        if (!($this->zipAvailable)()) {
            throw new BuildError(self::MISSING_ZIP_MESSAGE);
        }

        $zip = new ZipArchive();
        $zipPath = $outputPath . '/' . $this->filename;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BuildError("Could not create $zipPath zip archive");
        }

        $progress->step('Packing archive');

        $index = new DirectoryIndex($sourcePath);
        $packed = 0;

        foreach ($index as $file) {
            $progress->file($file['absolute']);
            $progress->detail(sprintf('%d file%s', ++$packed, $packed === 1 ? '' : 's'));

            $entry = $this->slug . '/' . $file['relative'];

            if ($this->replacementHeaders && $mainFile === $file['relative']) {
                $contents = (string) file_get_contents($file['absolute']);
                $zip->addFromString($entry, $this->rewriteHeaders($contents));
                continue;
            }

            $zip->addFile($file['absolute'], $entry);
        }

        // Preserve kept-but-empty directories, which carry no file to create them.
        foreach ($index->emptyDirectories() as $directory) {
            $zip->addEmptyDir($this->slug . '/' . $directory);
        }

        if ($zip->close() !== true) {
            throw new BuildError("Could not close $zipPath zip archive");
        }

        $progress->detail(sprintf('%s · %s', basename($zipPath), $this->humanSize((int) filesize($zipPath))));
        $progress->finish();

        return $zipPath;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $size, $units[$unit]);
    }

    private function rewriteHeaders(string $contents): string
    {
        foreach ($this->replacementHeaders as $header => $value) {
            $pattern = '/(^[ \t\/*#@]*' . preg_quote($header, '/') . ':\s*)(.*)$/mi';
            // Use a callback so '$' and '\' in the user-supplied value are inserted
            // literally instead of being read as backreferences.
            $contents = (string) preg_replace_callback(
                $pattern,
                static fn (array $matches): string => $matches[1] . $value,
                $contents
            );
        }

        return $contents;
    }

    private function findMainFile(string $sourceDir): ?string
    {
        return match ($this->type) {
            ResourceType::Plugin => $this->findPluginMainFile($sourceDir),
            ResourceType::Theme => $this->findThemeMainFile($sourceDir),
        };
    }

    private function findPluginMainFile(string $sourceDir): ?string
    {
        foreach (glob("$sourceDir/*.php") ?: [] as $file) {
            if (HeaderParser::plugin($file)['Name'] !== '') {
                return basename($file);
            }
        }

        return null;
    }

    private function findThemeMainFile(string $sourceDir): ?string
    {
        if (HeaderParser::theme("$sourceDir/style.css")['Name'] !== '') {
            return 'style.css';
        }

        return null;
    }
}
