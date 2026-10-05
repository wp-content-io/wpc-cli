<?php

namespace WpContent\Cli\Wordpress;

/**
 * Reads the header block of a plugin/theme main file, the way WordPress core
 * does, without loading WordPress.
 *
 * This used to be three functions declared in the global namespace under their
 * WordPress names (`get_plugin_data()`, `get_file_data()`, …) and pulled in
 * through composer's `autoload.files`. Nothing guards a redeclaration, so the
 * phar was one `require` of a WordPress bootstrap away from a fatal error — and
 * the CLI is a tool people run *inside* WordPress projects.
 */
final class HeaderParser
{
    /** @var array<string, string> field => header label */
    private const PLUGIN_HEADERS = [
        'Name' => 'Plugin Name',
        'PluginURI' => 'Plugin URI',
        'Version' => 'Version',
        'Description' => 'Description',
        'Author' => 'Author',
        'AuthorURI' => 'Author URI',
        'TextDomain' => 'Text Domain',
        'DomainPath' => 'Domain Path',
        'Network' => 'Network',
        'RequiresWP' => 'Requires at least',
        'RequiresPHP' => 'Requires PHP',
        'UpdateURI' => 'Update URI',
        'RequiresPlugins' => 'Requires Plugins',
        // Site Wide Only is deprecated in favor of Network.
        '_sitewide' => 'Site Wide Only',
    ];

    /** @var array<string, string> field => header label */
    private const THEME_HEADERS = [
        'Name' => 'Theme Name',
        'ThemeURI' => 'Theme URI',
        'Description' => 'Description',
        'Author' => 'Author',
        'AuthorURI' => 'Author URI',
        'Version' => 'Version',
        'Template' => 'Template',
        'Status' => 'Status',
        'UpdateURI' => 'Update URI',
    ];

    /**
     * @return array<string, string>
     */
    public static function plugin(string $file): array
    {
        return self::fileData($file, self::PLUGIN_HEADERS);
    }

    /**
     * @return array<string, string>
     */
    public static function theme(string $file): array
    {
        return self::fileData($file, self::THEME_HEADERS);
    }

    /**
     * @param array<string, string> $headers field => header label
     *
     * @return array<string, string> field => value
     */
    public static function fileData(string $file, array $headers): array
    {
        // Pull only the first 8 KB of the file in.
        $contents = @file_get_contents($file, false, null, 0, 8 * 1024);

        if ($contents === false) {
            $contents = '';
        }

        // Make sure we catch CR-only line endings.
        $contents = str_replace("\r", "\n", $contents);

        $data = [];
        foreach ($headers as $field => $label) {
            $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($label, '/') . ':(.*)$/mi';

            // Compare against '' rather than a bare truthiness test so a header
            // value of "0" is kept.
            $data[$field] = preg_match($pattern, $contents, $match) && trim($match[1]) !== ''
                ? self::cleanup($match[1])
                : '';
        }

        return $data;
    }

    private static function cleanup(string $value): string
    {
        return trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $value));
    }
}
