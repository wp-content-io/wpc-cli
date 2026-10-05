<?php

namespace WpContent\Cli\Tests\Update;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WpContent\Cli\Tests\TempDirTrait;
use WpContent\Cli\Update\UpdateChecker;

final class UpdateCheckerTest extends TestCase
{
    use TempDirTrait;

    private const MANIFEST_URL = 'https://downloads.example/manifest.json';

    public function testReturnsLatestWhenNewer(): void
    {
        $checker = $this->checker('1.1.0', [$this->manifest('1.2.0')]);

        self::assertSame('1.2.0', $checker->latestIfNewer());
    }

    public function testReturnsNullWhenUpToDateOrAhead(): void
    {
        self::assertNull($this->checker('1.2.0', [$this->manifest('1.2.0')])->latestIfNewer());
        self::assertNull($this->checker('1.3.0', [$this->manifest('1.2.0')])->latestIfNewer());
    }

    /**
     * A `v` on either side is tolerated, and never shown; a `git describe`
     * build counts as the tag it was built on.
     */
    public function testAVPrefixOnEitherSideIsTolerated(): void
    {
        self::assertSame('2.1.0', $this->checker('v2.0.0', [$this->manifest('2.1.0')])->latestIfNewer());
        self::assertSame('2.1.0', $this->checker('2.0.0', [$this->manifest('v2.1.0')])->latestIfNewer());
        self::assertNull($this->checker('v2.1.0', [$this->manifest('2.1.0')])->latestIfNewer());
        self::assertNull($this->checker('2.1.0', [$this->manifest('v2.1.0')])->latestIfNewer());
        self::assertNull($this->checker('v2.1.0-3-gabc1234', [$this->manifest('2.1.0')])->latestIfNewer());
        self::assertSame('2.1.1', $this->checker('v2.1.0-3-gabc1234', [$this->manifest('2.1.1')])->latestIfNewer());
    }

    public function testNetworkFailureIsSwallowed(): void
    {
        $checker = $this->checker('1.1.0', [new Response(500)]);

        self::assertNull($checker->latestIfNewer());
    }

    public function testSecondCallWithinTtlUsesCacheAndDoesNotHitTheNetwork(): void
    {
        $cache = $this->makeTempDir() . '/update-check.json';

        $first = new UpdateChecker(self::MANIFEST_URL, '1.1.0', $cache, $this->client([$this->manifest('1.2.0')]), now: 1000);
        self::assertSame('1.2.0', $first->latestIfNewer());

        // No responses queued: if it hit the network the mock handler would throw.
        $second = new UpdateChecker(self::MANIFEST_URL, '1.1.0', $cache, $this->client([]), now: 1000 + 3600);
        self::assertSame('1.2.0', $second->latestIfNewer());
    }

    public function testCacheExpiryTriggersARefresh(): void
    {
        $cache = $this->makeTempDir() . '/update-check.json';

        $first = new UpdateChecker(self::MANIFEST_URL, '1.1.0', $cache, $this->client([$this->manifest('1.2.0')]), now: 1000);
        self::assertSame('1.2.0', $first->latestIfNewer());

        $refreshed = new UpdateChecker(self::MANIFEST_URL, '1.1.0', $cache, $this->client([$this->manifest('1.5.0')]), now: 1000 + 86401);
        self::assertSame('1.5.0', $refreshed->latestIfNewer());
    }

    private function manifest(string $version): Response
    {
        return new Response(200, [], (string) json_encode(['version' => $version]));
    }

    /**
     * @param list<Response> $responses
     */
    private function checker(string $current, array $responses): UpdateChecker
    {
        return new UpdateChecker(
            self::MANIFEST_URL,
            $current,
            $this->makeTempDir() . '/update-check.json',
            $this->client($responses),
            now: 1000,
        );
    }

    /**
     * @param list<Response> $responses
     */
    private function client(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }
}
