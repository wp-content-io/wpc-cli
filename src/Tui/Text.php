<?php

namespace WpContent\Cli\Tui;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;

/**
 * Cell and line formatting for the TUI.
 *
 * Widths are measured in terminal columns, not bytes: an accented name or a CJK
 * title must not push a column out of alignment. Escaping comes last, once the
 * text has been sized — which only works because values are sized with
 * {@see valueWidth()}, where nothing is a tag. Measuring them with
 * {@see width()} counted `<info>` as zero columns, and the cell was then padded
 * to full width and rendered at twice it.
 */
final class Text
{
    public const ELLIPSIS = '…';

    /**
     * Visible width of a **rendered** string, in terminal columns: the styling
     * tags it carries are markup, and do not show.
     *
     * For chrome — a line the TUI itself assembled. A value read off the API is
     * measured with {@see valueWidth()}.
     */
    public static function width(string $text): int
    {
        return Helper::width(Helper::removeDecoration(new OutputFormatter(), self::utf8($text)));
    }

    /**
     * Width of a **value**, once escaped and rendered: a `<info>` inside a
     * plugin name is six columns of text, not a tag.
     */
    public static function valueWidth(string $text): int
    {
        return Helper::width(self::utf8($text));
    }

    /**
     * Keys that name a record, in order of preference — `display_name` first,
     * since that is what a theme's author object puts its person under.
     */
    private const NAME_KEYS = ['display_name', 'name'];

    /**
     * A field value as one piece of text, or null when it has none.
     *
     * Most values are scalars. A theme's `author` is not: the registry mirrors
     * the WordPress.org themes API, where it is an object (`display_name`,
     * `user_nicename`, `author_url`…). Every renderer used to keep scalars
     * only, so the author of a theme was a blank cell — or, in the static
     * table, a nested table of avatar URLs. A record that carries a name is
     * shown by it; anything else is left to the caller, and to `--output=json`,
     * which never goes through here.
     */
    public static function display(mixed $value): ?string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (self::NAME_KEYS as $key) {
            if (isset($value[$key]) && is_scalar($value[$key]) && trim((string) $value[$key]) !== '') {
                return (string) $value[$key];
            }
        }

        return null;
    }

    /** Collapse a multi-line value so it cannot break the table layout. */
    public static function singleLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::utf8($text)));
    }

    public static function truncate(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        $text = self::utf8($text);

        if (self::valueWidth($text) <= $maxWidth) {
            return $text;
        }

        $truncated = '';
        $width = 0;
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $character) {
            $characterWidth = self::valueWidth($character);
            if ($width + $characterWidth > $maxWidth - 1) {
                break;
            }
            $truncated .= $character;
            $width += $characterWidth;
        }

        return $truncated . self::ELLIPSIS;
    }

    /** Pad on the right to exactly $width columns (truncating when too long). */
    private static function pad(string $text, int $width): string
    {
        $text = self::truncate($text, $width);

        return $text . str_repeat(' ', max(0, $width - self::valueWidth($text)));
    }

    /**
     * Neutralise formatting tags in a value. Plugin names and descriptions are
     * user content: an unescaped "<8.0" would be swallowed as a tag.
     */
    public static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }

    /** A cell: collapsed, truncated, padded and escaped, in that order. */
    public static function cell(string $text, int $width): string
    {
        return self::escape(self::pad(self::singleLine($text), $width));
    }

    /**
     * Turn an HTML-ish API value (descriptions, changelogs) into plain text,
     * keeping block-level tags as line breaks.
     *
     * Only well-formed tags are stripped: unlike strip_tags(), a bare '<' (as in
     * "requires PHP <8.0") is left alone instead of eating the rest of the value.
     */
    public static function plain(string $text): string
    {
        $text = self::utf8($text);
        $blocks = 'h\d|p|div|li|ul|ol|tr|blockquote';

        $normalized = preg_replace(
            ['#<(' . $blocks . ')(\s[^<>]*)?>#i', '#</(' . $blocks . ')>#i', '#<br\s*/?>#i', "#\n+#"],
            ["\n<$1>", "</$1>\n", "\n", "\n"],
            $text
        );

        // Only strip things that actually look like a tag — a name, then optional
        // attributes. A bare '<' ("requires PHP <8.2, and …") must survive, which
        // a lazier `<[^>]+>` would swallow all the way to the next '>'.
        return trim((string) preg_replace('#</?[a-z][a-z0-9]*(\s[^<>]*)?/?>#i', '', (string) $normalized));
    }

    /**
     * Wrap text to $width columns, preserving existing line breaks.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        if ($width < 1) {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\R/u', self::utf8($text)) ?: [] as $paragraph) {
            if (trim($paragraph) === '') {
                $lines[] = '';
                continue;
            }

            $current = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;

                if (self::valueWidth($candidate) <= $width) {
                    $current = $candidate;
                    continue;
                }

                if ($current !== '') {
                    $lines[] = $current;
                }

                // A single word longer than the viewport still has to fit.
                while (self::valueWidth($word) > $width) {
                    $head = self::hardSplit($word, $width);
                    $lines[] = $head;
                    $word = mb_substr($word, mb_strlen($head));
                }

                $current = $word;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines;
    }

    /**
     * The leading chunk of a word that fits in $width columns.
     *
     * The first character is taken unconditionally, even when it is wider than
     * the viewport on its own — a double-width glyph (emoji, CJK) at $width = 1.
     * Returning nothing would leave {@see wrap()} with a word it never shortens,
     * and the caller spinning forever on a frame it cannot draw.
     */
    private static function hardSplit(string $word, int $width): string
    {
        $head = '';
        foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($head !== '' && self::valueWidth($head . $character) > $width) {
                break;
            }
            $head .= $character;
        }

        return $head;
    }

    /**
     * Drop whatever is not valid UTF-8, once, at the door.
     *
     * Every `/u` pattern here returns null or false on the first invalid byte,
     * and the `(string)` casts and `?: []` fallbacks turned that into an empty
     * string: pasting a latin-1 byte into the wizard printed a blank recap line
     * while the value the user typed was still written to disk. Losing the byte
     * is a rendering detail; losing the value is a lie about what happened.
     *
     * Public because the door is not only here: `ValueResult::human()` writes
     * raw bytes straight to the terminal, which is the one output path that
     * does not otherwise go through this class.
     */
    public static function utf8(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
