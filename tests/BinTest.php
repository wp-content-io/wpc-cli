<?php

namespace WpContent\Cli\Tests;

use PHPUnit\Framework\TestCase;

/**
 * `bin/wpc` is run three ways: from a checkout, inside the phar, and through
 * the proxy `composer global require` writes into vendor/bin — where the
 * autoloader is the consumer's, handed over in `$_composer_autoload_path`.
 * Each is run in a child process: the entry point builds an Application and
 * exits, which nothing in this process can survive.
 */
final class BinTest extends TestCase
{
    use TempDirTrait;

    public function testFromACheckoutTheAutoloaderNextToItIsUsed(): void
    {
        [$exit, $stdout] = $this->php(sprintf('$argv = $_SERVER["argv"] = ["wpc", "--version"]; require %s;', var_export($this->bin(), true)));

        self::assertSame(0, $exit);
        self::assertStringContainsString('wp-content.io CLI', $stdout);
    }

    public function testThroughComposersProxyItsAutoloaderIsUsed(): void
    {
        $autoload = $this->makeTempDir() . '/autoload.php';
        $marker = $autoload . '.used';
        $this->writeFile($autoload, sprintf(
            "<?php\nfile_put_contents(%s, 'yes');\nreturn require %s;\n",
            var_export($marker, true),
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
        ));

        [$exit, $stdout] = $this->php(sprintf(
            '$_composer_autoload_path = %s; $argv = $_SERVER["argv"] = ["wpc", "--version"]; require %s;',
            var_export($autoload, true),
            var_export($this->bin(), true),
        ));

        self::assertSame(0, $exit);
        self::assertStringContainsString('wp-content.io CLI', $stdout);
        self::assertFileExists($marker, 'the autoloader Composer named is the one loaded');
    }

    private function bin(): string
    {
        return dirname(__DIR__) . '/bin/wpc';
    }

    /**
     * @return array{int, string}
     */
    private function php(string $code): array
    {
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['WPC_NO_UPDATE_CHECK' => '1', 'PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout];
    }
}
