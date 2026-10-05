<?php

namespace WpContent\Cli\Tui;

use WpContent\Cli\Env;

/**
 * The accent colour of the interactive rendering, in one place.
 *
 * Only one thing is branded: what the cursor is on — the highlighted row, the
 * active tab, the insertion point. It defaults to the dashboard's button colour
 * (`--mat-sys-primary`, #4433db) so a selection reads the same in the terminal
 * as in the app, and can be repointed with WPC_ACCENT_COLOR.
 *
 * Everything else stays on the terminal's own palette: the chrome is drawn in
 * `gray`, and semantic states keep Symfony's <info>/<comment>/<error> tags. A
 * CLI sits inside someone else's colour scheme — painting backgrounds over it
 * fights the theme the user chose.
 */
final class Theme
{
    /** Button colour — the dashboard's `--mat-sys-primary`. */
    public const BRAND_ACCENT = '#4433db';

    public const ACCENT_ENV = 'WPC_ACCENT_COLOR';

    public const MUTED = 'gray';

    /**
     * The accent colour: WPC_ACCENT_COLOR when it holds something usable, the
     * brand colour otherwise. An unusable value is ignored rather than fatal —
     * a typo in an env var must not take the CLI down.
     *
     * Resolved on every call rather than memoised: the answer is a `getenv()`
     * and a `preg_match`, and the cache it used to keep had to be punctured with
     * a public `reset()` that existed for no reason other than the tests.
     */
    public static function accent(): string
    {
        $configured = Env::get(self::ACCENT_ENV);

        return $configured !== null && self::isUsableColor($configured)
            ? strtolower(trim($configured))
            : self::BRAND_ACCENT;
    }

    /** A full-width selection band: the row the cursor sits on. */
    public static function selected(string $text): string
    {
        return sprintf('<fg=%s;bg=%s>%s</>', self::onAccent(), self::accent(), $text);
    }

    /**
     * The insertion point: a solid accent block.
     *
     * A background, never a foreground. The accent is a *button* colour — dark
     * by design, with white on top — so drawing text or a `█` glyph in it lands
     * dark-on-dark in any dark terminal and disappears. Filled with a space and
     * lit from behind, it shows up whatever theme the user runs.
     *
     * Which is also why nothing the user types is ever coloured: that is
     * content, and only the terminal's own foreground is readable by
     * construction.
     */
    public static function caret(): string
    {
        return self::selected(' ');
    }

    /** Secondary text: hints, footers, column headers. */
    public static function muted(string $text): string
    {
        return sprintf('<fg=%s>%s</>', self::MUTED, $text);
    }

    /**
     * Black or white on top of the accent, whichever stays readable. Picked from
     * the accent's luminance so a light custom accent does not end up with white
     * text on it.
     */
    public static function onAccent(): string
    {
        $accent = self::accent();

        if ($accent[0] !== '#') {
            // A named colour carries no brightness to measure, so the light ones
            // are listed. Answering `white` for all of them — as it used to —
            // made WPC_ACCENT_COLOR=white a white-on-white band, and the row the
            // cursor was on simply disappeared.
            return match ($accent) {
                'white', 'bright-white', 'yellow', 'bright-yellow',
                'bright-green', 'bright-cyan', 'gray' => 'black',
                // The terminal's own background: its own foreground goes on it.
                'default' => 'default',
                default => 'white',
            };
        }

        return self::luminance($accent) > 0.55 ? 'black' : 'white';
    }

    /**
     * Perceived luminance in the 0..1 range, weighted per channel the way the
     * eye responds (green counts far more than blue).
     */
    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $red = (int) hexdec(substr($hex, 0, 2));
        $green = (int) hexdec(substr($hex, 2, 2));
        $blue = (int) hexdec(substr($hex, 4, 2));

        return (0.2126 * $red + 0.7152 * $green + 0.0722 * $blue) / 255;
    }

    /** Whether a configured value is a hex colour or a name Symfony knows. */
    private static function isUsableColor(string $color): bool
    {
        $color = strtolower(trim($color));

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $color)) {
            return true;
        }

        return in_array($color, [
            'black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white', 'default', 'gray',
            'bright-red', 'bright-green', 'bright-yellow', 'bright-blue',
            'bright-magenta', 'bright-cyan', 'bright-white',
        ], true);
    }
}
