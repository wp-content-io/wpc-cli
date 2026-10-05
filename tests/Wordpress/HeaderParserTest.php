<?php

namespace WpContent\Cli\Tests\Wordpress;

use PHPUnit\Framework\TestCase;
use WpContent\Cli\Tests\TempDirTrait;
use WpContent\Cli\Wordpress\HeaderParser;

final class HeaderParserTest extends TestCase
{
    use TempDirTrait;

    public function testPluginHeadersAreParsed(): void
    {
        $dir = $this->makeTempDir();
        $file = "$dir/my-plugin.php";
        $this->writeFile($file, <<<'PHP'
            <?php
            /**
             * Plugin Name: My Plugin
             * Version: 3.2.1
             * Author: Jane Doe
             * Requires PHP: 8.2
             * Update URI: https://registry.example
             */
            PHP);

        $data = HeaderParser::plugin($file);

        self::assertSame('My Plugin', $data['Name']);
        self::assertSame('3.2.1', $data['Version']);
        self::assertSame('Jane Doe', $data['Author']);
        self::assertSame('8.2', $data['RequiresPHP']);
        self::assertSame('https://registry.example', $data['UpdateURI']);
    }

    public function testHeaderValueOfZeroIsKept(): void
    {
        $dir = $this->makeTempDir();
        $file = "$dir/my-plugin.php";
        $this->writeFile($file, <<<'PHP'
            <?php
            /**
             * Plugin Name: My Plugin
             * Version: 0
             */
            PHP);

        // "0" is falsy in PHP; a bare truthiness test would drop it.
        self::assertSame('0', HeaderParser::plugin($file)['Version']);
    }

    public function testThemeHeadersAreParsed(): void
    {
        $dir = $this->makeTempDir();
        $file = "$dir/style.css";
        $this->writeFile($file, <<<'CSS'
            /*
            Theme Name: My Theme
            Version: 1.4.0
            Author: John Doe
            */
            CSS);

        $data = HeaderParser::theme($file);

        self::assertSame('My Theme', $data['Name']);
        self::assertSame('1.4.0', $data['Version']);
        self::assertSame('John Doe', $data['Author']);
    }

    public function testAnUnreadableFileYieldsEmptyHeadersRatherThanAWarning(): void
    {
        $data = HeaderParser::plugin('/does/not/exist.php');

        self::assertSame('', $data['Name']);
        self::assertSame('', $data['Version']);
    }
}
