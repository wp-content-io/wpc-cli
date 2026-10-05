<?php

namespace WpContent\Cli\Tests\Builder;

use PHPUnit\Framework\TestCase;
use WpContent\Cli\Builder\DirectoryIndex;
use WpContent\Cli\Tests\TempDirTrait;

final class DirectoryIndexTest extends TestCase
{
    use TempDirTrait;

    public function testHonorsWpcignoreRulesIncludingNegation(): void
    {
        $root = $this->makeTempDir();
        $this->writeFile("$root/main.php", '<?php');
        $this->writeFile("$root/keep.txt", 'keep');
        $this->writeFile("$root/debug.log", 'log');
        $this->writeFile("$root/important.log", 'log');
        $this->writeFile("$root/sub/a.php", '<?php');
        $this->writeFile("$root/sub/b.log", 'log');
        $this->writeFile("$root/node_modules/lib.js", 'js');
        $this->writeFile("$root/.wpcignore", "*.log\nnode_modules/\n!important.log\n");

        $relatives = [];
        foreach (new DirectoryIndex($root) as $file) {
            $relatives[] = $file['relative'];
        }
        sort($relatives);

        self::assertSame(
            ['important.log', 'keep.txt', 'main.php', 'sub/a.php'],
            $relatives
        );
    }

    public function testWpcignoreFileItselfIsAlwaysExcluded(): void
    {
        $root = $this->makeTempDir();
        $this->writeFile("$root/main.php", '<?php');
        $this->writeFile("$root/.wpcignore", "# nothing\n");

        $relatives = [];
        foreach (new DirectoryIndex($root) as $file) {
            $relatives[] = $file['relative'];
        }

        self::assertSame(['main.php'], $relatives);
    }

    public function testRootedPatternIsAnchoredToTheRoot(): void
    {
        $root = $this->makeTempDir();
        $this->writeFile("$root/keep.php", '<?php');
        $this->writeFile("$root/dist/app.zip", 'zip');       // excluded by /dist/*.zip
        $this->writeFile("$root/sub/dist/app.zip", 'zip');   // kept: not at the root
        $this->writeFile("$root/.wpcignore", "/dist/*.zip\n");

        $relatives = [];
        foreach (new DirectoryIndex($root) as $file) {
            $relatives[] = $file['relative'];
        }
        sort($relatives);

        self::assertSame(['keep.php', 'sub/dist/app.zip'], $relatives);
    }

    public function testNestedIgnoreFileIsHonored(): void
    {
        $root = $this->makeTempDir();
        $this->writeFile("$root/main.php", '<?php');
        $this->writeFile("$root/a/b/keep.txt", 'keep');
        $this->writeFile("$root/a/b/secret.txt", 'secret');
        $this->writeFile("$root/a/b/.wpcignore", "secret.txt\n"); // two levels deep

        $relatives = [];
        foreach (new DirectoryIndex($root) as $file) {
            $relatives[] = $file['relative'];
        }
        sort($relatives);

        self::assertSame(['a/b/keep.txt', 'main.php'], $relatives);
    }

    public function testKeptEmptyDirectoriesAreReportedButIgnoredOnesAreNot(): void
    {
        $root = $this->makeTempDir();
        $this->writeFile("$root/main.php", '<?php');
        mkdir("$root/cache");  // kept, empty
        mkdir("$root/logs");   // ignored, empty
        $this->writeFile("$root/.wpcignore", "logs/\n");

        $index = new DirectoryIndex($root);
        $empty = $index->emptyDirectories();
        sort($empty);

        self::assertSame(['cache'], $empty);
    }
}
