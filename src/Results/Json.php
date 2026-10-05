<?php

namespace WpContent\Cli\Results;

use JsonException;

/**
 * The one encoder every payload goes through.
 *
 * `json_encode()` returns `false` rather than throwing, and `(string) false` is
 * an empty string — so a single non-UTF-8 byte anywhere in a value turned the
 * whole answer into zero bytes on stdout, with an exit status of 0. A plugin
 * main file saved in ISO-8859-1 was enough (`Author: Jos\xE9 Garc\xEDa`), and a
 * pipeline reading `--output=json` got either a parse error or, worse, a
 * default it then tagged a release with.
 *
 * Bad bytes are substituted rather than refused, the same call `Tui\Text::utf8()`
 * makes on the rendering side: the answer a caller asked for is worth more than
 * the byte that was already unreadable.
 */
final class Json
{
    /**
     * Flags every payload shares. `INVALID_UTF8_SUBSTITUTE` is the one that
     * matters; `UNESCAPED_SLASHES` keeps paths and URLs readable, which only
     * one of the six call sites used to ask for.
     */
    private const FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value, int $flags = 0): string
    {
        try {
            return json_encode($value, self::FLAGS | $flags);
        } catch (JsonException $e) {
            // Only reachable for what substitution cannot fix — a recursive
            // structure, INF/NAN. Still an object on stdout, because the
            // contract says a failure is an answer too, and a caller parsing
            // this stream has no other way to be told.
            return json_encode([
                'code' => 1,
                'message' => 'Could not encode the answer: ' . $e->getMessage(),
                'errors' => [],
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES)
                ?: '{"code":1,"message":"Could not encode the answer.","errors":[]}';
        }
    }
}
