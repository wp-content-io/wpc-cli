<?php

namespace WpContent\Cli;

/**
 * The one place the CLI reads its environment.
 *
 * There used to be two: {@see Config} read `$_ENV`/`$_SERVER` while the TUI and
 * the theme read `getenv()`. The two disagree — `putenv()` is invisible to the
 * superglobals, and the superglobals are only populated according to PHP's
 * `variables_order`, which an image with a custom php.ini may well not include.
 * So `WPC_NO_TUI` was honoured where `WPC_API_KEY` was not, depending on how the
 * variable had been set. All three sources are consulted here, once.
 */
final class Env
{
    /** The value of an environment variable, or null when it is not set at all. */
    public static function get(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        if ($value === null) {
            $fromGetenv = getenv($name);
            $value = $fromGetenv === false ? null : $fromGetenv;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Whether a variable is set to a value meaning "on". An unset variable or an
     * explicit falsy value ("", "0", "false", "off", "no") counts as off, so
     * e.g. WPC_NO_UPDATE_CHECK=0 behaves like the variable being unset (PHP
     * would otherwise read the string "0" as falsy in a bare boolean test).
     */
    public static function isTruthy(string $name): bool
    {
        $value = self::get($name);

        if ($value === null) {
            return false;
        }

        return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
    }
}
