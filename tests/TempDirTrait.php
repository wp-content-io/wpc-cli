<?php

namespace WpContent\Cli\Tests;

use PHPUnit\Framework\Attributes\After;

trait TempDirTrait
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/wpc-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    #[After]
    protected function removeTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeRecursive($dir);
        }
        $this->tempDirs = [];
    }

    private function removeRecursive(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeRecursive("$path/$entry");
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
