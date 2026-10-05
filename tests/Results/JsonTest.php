<?php

namespace WpContent\Cli\Tests\Results;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use WpContent\Cli\Results\CommandFailure;
use WpContent\Cli\Results\Json;
use WpContent\Cli\Results\StatusResult;
use WpContent\Cli\Results\TableResult;
use WpContent\Cli\Results\ValueResult;

/**
 * A payload is never empty, whatever it carries.
 *
 * `json_encode()` returns `false` on malformed UTF-8 rather than throwing, and
 * `(string) false` is an empty string — so one byte off a plugin main file
 * saved in ISO-8859-1 turned the whole answer into zero bytes on stdout, with
 * an exit status of 0. A pipeline reading it got a parse error, or a default it
 * then tagged a release with.
 */
final class JsonTest extends TestCase
{
    /** `Jos<e9> Garc<ed>a` — a name as ISO-8859-1 writes it. */
    private const LATIN1 = "Jos\xe9 Garc\xeda";

    public function testInvalidBytesAreSubstitutedRatherThanSwallowed(): void
    {
        $encoded = Json::encode(['author' => self::LATIN1]);

        self::assertNotSame('', $encoded);

        $payload = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['author'], array_keys($payload));
        self::assertStringContainsString("\u{FFFD}", $payload['author']);
    }

    public function testSlashesAreLeftAloneEverywhere(): void
    {
        $payload = Json::encode(['url' => 'https://registry.wp-content.io/plugins']);

        // Only one of the six call sites used to ask for this, so the same path
        // came back escaped or not depending on which result carried it.
        self::assertStringContainsString('https://registry.wp-content.io/plugins', $payload);
    }

    public function testWhatSubstitutionCannotFixIsStillAnObject(): void
    {
        $payload = json_decode(Json::encode(['ratio' => \NAN]), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['code']);
        self::assertStringContainsString('Could not encode', $payload['message']);
    }

    /**
     * Every result that writes a payload, through the same door.
     */
    public function testNoResultAnswersWithZeroBytes(): void
    {
        $results = [
            'table' => new TableResult([['author' => self::LATIN1]], ['author']),
            'value' => new ValueResult('Author', self::LATIN1),
            'status' => new StatusResult(self::LATIN1, ['author' => self::LATIN1]),
            'failure' => new CommandFailure(self::LATIN1 . ' is broken', 2),
        ];

        foreach ($results as $name => $result) {
            $output = new BufferedOutput();
            $result->json($output);
            $written = $output->fetch();

            self::assertNotSame('', $written, "$name must write something");
            self::assertIsArray(
                json_decode($written, true, flags: JSON_THROW_ON_ERROR),
                "$name must write valid json"
            );
        }
    }

    /**
     * The human side of the one path that writes raw bytes: `manifest --get`
     * hands its value straight to the terminal, unstyled and unescaped, so it
     * is the only rendering that is not already behind `Text`.
     */
    public function testTheBareValueIsCleanedOnTheWayOutToo(): void
    {
        $output = new BufferedOutput();
        (new ValueResult('Author', self::LATIN1))->human($output);

        self::assertSame(1, preg_match('//u', $output->fetch()), 'the terminal is handed valid UTF-8');
    }
}
