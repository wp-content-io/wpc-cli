<?php

namespace WpContent\Cli\Tests\Update;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use WpContent\Cli\Tests\TempDirTrait;
use WpContent\Cli\Update\UpdateError;
use WpContent\Cli\Update\Updater;

final class UpdaterTest extends TestCase
{
    use TempDirTrait;

    private const PHAR_URL = 'https://downloads.example/wpc.phar';
    private const SHA_URL = 'https://downloads.example/wpc.phar.sha256';
    private const MANIFEST_URL = 'https://downloads.example/manifest.json';
    private const VERSIONED_URL = 'https://downloads.example/1.1.3/wpc.phar';
    private const GITHUB_URL = 'https://github.com/wp-content-io/wpc-cli/releases/download/v2.1.0/wpc.phar';
    private const STORAGE_URL = 'https://objects.githubusercontent.com/github-production-release-asset/123/wpc.phar?sig=abc';

    public function testHasUpdateComparesChecksums(): void
    {
        $phar = $this->pharFile('old');

        self::assertTrue($this->updater($phar, [new Response(200, [], hash('sha256', 'new'))])->hasUpdate());
        self::assertFalse($this->updater($phar, [new Response(200, [], hash('sha256', 'old'))])->hasUpdate());
    }

    public function testUpdateInstallsVerifiedPharAndKeepsBackup(): void
    {
        $phar = $this->pharFile('old');
        $newSha = hash('sha256', 'new');

        $updater = $this->updater($phar, [
            new Response(200, [], $newSha),   // remoteSha()
            new Response(200, [], 'new'),     // phar download
        ]);
        $updater->update();

        self::assertSame('new', file_get_contents($phar));
        self::assertSame('old', file_get_contents($updater->backupPath()));

        $updater->rollback();
        self::assertSame('old', file_get_contents($phar));
    }

    public function testRollbackRestoresExecutablePermissions(): void
    {
        $phar = $this->pharFile('old');
        chmod($phar, 0755);

        $updater = $this->updater($phar, [
            new Response(200, [], hash('sha256', 'new')),
            new Response(200, [], 'new'),
        ]);

        $updater->update();
        self::assertSame(0755, fileperms($phar) & 0777, 'the freshly installed phar keeps its executable bit');

        $updater->rollback();
        self::assertSame('old', file_get_contents($phar));
        self::assertSame(0755, fileperms($phar) & 0777, 'rollback must not drop the executable bit');
    }

    public function testRemoteChecksumIsFetchedOnlyOnce(): void
    {
        $phar = $this->pharFile('old');

        // Only one checksum response is queued: hasUpdate() fetches it and update()
        // must reuse the cached value. A second fetch would drain the mock handler.
        $updater = $this->updater($phar, [
            new Response(200, [], hash('sha256', 'new')),
            new Response(200, [], 'new'),
        ]);

        self::assertTrue($updater->hasUpdate());
        $updater->update();

        self::assertSame('new', file_get_contents($phar));
    }

    public function testUpdateRejectsChecksumMismatch(): void
    {
        $phar = $this->pharFile('old');
        $updater = $this->updater($phar, [
            new Response(200, [], hash('sha256', 'new')),
            new Response(200, [], 'tampered-payload'),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $updater->update();
        } finally {
            self::assertSame('old', file_get_contents($phar), 'the local phar must stay untouched');
        }
    }

    public function testInvalidPublishedChecksumIsRejected(): void
    {
        $phar = $this->pharFile('old');
        $updater = $this->updater($phar, [new Response(200, [], 'not-a-sha')]);

        $this->expectException(RuntimeException::class);
        $updater->hasUpdate();
    }

    public function testAMissingReleaseIsASentenceNotTheBucketsXml(): void
    {
        $phar = $this->pharFile('old');
        $updater = $this->updater($phar, [
            new Response(403, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><Error><Code>AccessDenied</Code></Error>'),
        ]);

        try {
            $updater->hasUpdate();
            self::fail('a missing checksum must fail');
        } catch (UpdateError $e) {
            self::assertSame('No release is published at ' . self::SHA_URL . ' yet (HTTP 403).', $e->getMessage());
        }
    }

    public function testAnUnreachableHostIsNamed(): void
    {
        $phar = $this->pharFile('old');
        $updater = $this->updater($phar, [
            new ConnectException('cURL error 6: Could not resolve host', new Request('GET', self::SHA_URL)),
        ]);

        $this->expectException(UpdateError::class);
        $this->expectExceptionMessage('Could not reach ' . self::SHA_URL . '.');
        $updater->hasUpdate();
    }

    /**
     * A build ahead of the published release — a pre-release, a branch build —
     * is never "updated" down to it, and the checksum is not even asked for:
     * only the manifest response is queued.
     */
    public function testANewerOrEqualLocalVersionIsUpToDate(): void
    {
        $phar = $this->pharFile('old');

        foreach (['2.0.0-beta' => '1.1.2', '1.1.2' => '1.1.2', '1.1.2-57-g3156e95' => '1.1.2', 'v1.2.0' => '1.1.2'] as $local => $published) {
            $updater = $this->updater($phar, [$this->manifest($published)], $local);

            self::assertFalse($updater->hasUpdate(), "$local against $published");
        }
    }

    /**
     * A Box-stamped `v2.1.0` and a manifest spelling its version either way
     * compare as the releases they are.
     */
    public function testAVPrefixIsToleratedOnBothSides(): void
    {
        $phar = $this->pharFile('old');

        self::assertFalse($this->updater($phar, [$this->manifest('2.1.0')], 'v2.1.0')->hasUpdate());
        self::assertFalse($this->updater($phar, [$this->manifest('v2.1.0')], '2.1.0')->hasUpdate());
        self::assertFalse($this->updater($phar, [$this->manifest('2.1.0')], 'v2.1.0-3-gabc1234')->hasUpdate());

        $updater = $this->updater($phar, [$this->manifest('v3.0.0'), new Response(200, [], hash('sha256', 'new'))], 'v2.1.0');
        self::assertTrue($updater->hasUpdate());
        self::assertSame('3.0.0', $updater->publishedVersion());
        self::assertSame('3.0.0', $updater->majorUpgrade());
    }

    public function testAnOlderLocalVersionStillChecksTheChecksum(): void
    {
        $phar = $this->pharFile('old');

        foreach (['1.1.1' => '1.1.2', '2.0.0-beta' => '2.0.0', '1.1.2-57-g3156e95' => '1.1.3'] as $local => $published) {
            $updater = $this->updater($phar, [$this->manifest($published), new Response(200, [], hash('sha256', 'new'))], $local);

            self::assertTrue($updater->hasUpdate(), "$local against $published");
            self::assertNull($updater->majorUpgrade(), "$local to $published is not a major upgrade");
        }
    }

    /**
     * No usable version on either side: the checksum decides, as it always did.
     */
    public function testAnUnknownVersionFallsBackToTheChecksum(): void
    {
        $phar = $this->pharFile('old');
        $newer = new Response(200, [], hash('sha256', 'new'));
        $same = new Response(200, [], hash('sha256', 'old'));

        self::assertTrue($this->updater($phar, [$this->manifest('1.0.0'), $newer], '@cli_version@')->hasUpdate());
        self::assertFalse($this->updater($phar, [$this->manifest('1.0.0'), $same], '@cli_version@')->hasUpdate());
        self::assertTrue($this->updater($phar, [new Response(404), $newer], '9.9.9')->hasUpdate(), 'an unpublished manifest');
        self::assertTrue($this->updater($phar, [new Response(200, [], 'not json'), $newer], '9.9.9')->hasUpdate(), 'an unreadable manifest');
    }

    public function testAMajorUpgradeIsOnlyInstalledWhenAllowed(): void
    {
        $phar = $this->pharFile('old');
        $responses = fn (): array => [$this->manifest('2.0.0'), new Response(200, [], hash('sha256', 'new')), new Response(200, [], 'new')];

        $updater = $this->updater($phar, $responses(), '1.1.2');
        self::assertSame('2.0.0', $updater->majorUpgrade());

        try {
            $updater->update();
            self::fail('a major upgrade must not be installed unasked');
        } catch (UpdateError) {
            self::assertSame('old', file_get_contents($phar));
        }

        $this->updater($phar, $responses(), '1.1.2')->update(allowMajor: true);
        self::assertSame('new', file_get_contents($phar));
    }

    /**
     * A manifest that pins the versioned phar and its sum is all the update
     * reads: the mutable latest/ pair is never fetched (no response is queued
     * for it), and the download is the versioned URL.
     */
    public function testAPinningManifestIsTheOnlySourceRead(): void
    {
        $phar = $this->pharFile('old');
        $history = [];
        $updater = $this->updater($phar, [
            $this->manifest('1.1.3', hash('sha256', 'new'), self::VERSIONED_URL),
            new Response(200, [], 'new'),
        ], '1.1.2', $history);

        self::assertSame(self::VERSIONED_URL, $updater->downloadUrl());
        self::assertTrue($updater->hasUpdate());
        $updater->update();

        self::assertSame('new', file_get_contents($phar));
        self::assertSame(
            [self::MANIFEST_URL, self::VERSIONED_URL],
            array_map(static fn (array $entry): string => (string) $entry['request']->getUri(), $history),
            'the manifest is read once, then the versioned phar',
        );
    }

    public function testAPinnedSumThatDoesNotMatchIsRefused(): void
    {
        $phar = $this->pharFile('old');
        $updater = $this->updater($phar, [
            $this->manifest('1.1.3', hash('sha256', 'new'), self::VERSIONED_URL),
            new Response(200, [], 'tampered-payload'),
        ], '1.1.2');

        try {
            $updater->update();
            self::fail('a payload that does not match the pinned sum must be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Checksum mismatch', $e->getMessage());
            self::assertSame('old', file_get_contents($phar), 'the local phar must stay untouched');
        }
    }

    /**
     * A manifest that pins nothing usable is ignored as a pin — its version
     * still counts — and the update falls back to the latest/ pair.
     */
    public function testAnUnusablePinFallsBackToTheLatestPair(): void
    {
        $sum = hash('sha256', 'new');
        $cases = [
            'a URL on another host' => $this->manifest('1.1.3', $sum, 'https://evil.example/wpc.phar'),
            'a URL hiding another host behind userinfo' => $this->manifest('1.1.3', $sum, 'https://downloads.example@evil.example/wpc.phar'),
            'a plain-HTTP URL' => $this->manifest('1.1.3', $sum, 'http://downloads.example/1.1.3/wpc.phar'),
            'a malformed sum' => $this->manifest('1.1.3', 'not-a-sha', self::VERSIONED_URL),
            'a sum without a URL' => $this->manifest('1.1.3', $sum),
            'an unreadable manifest' => new Response(200, [], 'not json'),
            'an unpublished manifest' => new Response(403),
        ];

        foreach ($cases as $case => $manifest) {
            $phar = $this->pharFile('old');
            $history = [];
            $updater = $this->updater($phar, [$manifest, new Response(200, [], $sum), new Response(200, [], 'new')], '1.1.2', $history);

            self::assertSame(self::PHAR_URL, $updater->downloadUrl(), $case);
            $updater->update();

            self::assertSame('new', file_get_contents($phar), $case);
            self::assertSame(
                [self::MANIFEST_URL, self::SHA_URL, self::PHAR_URL],
                array_map(static fn (array $entry): string => (string) $entry['request']->getUri(), $history),
                $case,
            );
        }
    }

    /**
     * A release asset of the public repository is a pin like the manifest's
     * own host — the download goes straight to it.
     */
    public function testAGithubReleaseAssetIsTrusted(): void
    {
        $phar = $this->pharFile('old');
        $history = [];
        $updater = $this->updater($phar, [
            $this->manifest('2.1.0', hash('sha256', 'new'), self::GITHUB_URL),
            new Response(200, [], 'new'),
        ], '2.0.0', $history);

        self::assertSame(self::GITHUB_URL, $updater->downloadUrl());
        $updater->update();

        self::assertSame('new', file_get_contents($phar));
        self::assertSame([self::MANIFEST_URL, self::GITHUB_URL], $this->uris($history));
    }

    /**
     * GitHub answers an asset with a 302 to its storage host: followed, and the
     * pinned sum checked on what the last hop returned.
     */
    public function testARedirectToTheStorageHostIsFollowed(): void
    {
        $phar = $this->pharFile('old');
        $history = [];
        $updater = $this->updater($phar, [
            $this->manifest('2.1.0', hash('sha256', 'new'), self::GITHUB_URL),
            new Response(302, ['Location' => self::STORAGE_URL]),
            new Response(200, [], 'new'),
        ], '2.0.0', $history);

        $updater->update();

        self::assertSame('new', file_get_contents($phar));
        self::assertSame([self::MANIFEST_URL, self::GITHUB_URL, self::STORAGE_URL], $this->uris($history));
        self::assertFalse($history[2]['request']->hasHeader('Referer'), 'the next host is not told where the request came from');
    }

    public function testARedirectLeavingHttpsIsRefused(): void
    {
        $phar = $this->pharFile('old');
        $updater = $this->updater($phar, [
            $this->manifest('2.1.0', hash('sha256', 'new'), self::GITHUB_URL),
            new Response(302, ['Location' => 'http://objects.githubusercontent.com/wpc.phar']),
            new Response(200, [], 'new'),
        ], '2.0.0');

        try {
            $updater->update();
            self::fail('a redirect to plain HTTP must not be followed');
        } catch (UpdateError $e) {
            self::assertSame(
                'Refusing to follow the redirect from ' . self::GITHUB_URL . ' to http://objects.githubusercontent.com/wpc.phar: downloads are only made over HTTPS.',
                $e->getMessage(),
            );
            self::assertSame('old', file_get_contents($phar));
        }
    }

    public function testAChecksumMismatchAfterARedirectIsRefused(): void
    {
        $phar = $this->pharFile('old');
        $updater = $this->updater($phar, [
            $this->manifest('2.1.0', hash('sha256', 'new'), self::GITHUB_URL),
            new Response(302, ['Location' => self::STORAGE_URL]),
            new Response(200, [], 'tampered-payload'),
        ], '2.0.0');

        try {
            $updater->update();
            self::fail('a payload that does not match the pinned sum must be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Checksum mismatch', $e->getMessage());
            self::assertSame('old', file_get_contents($phar), 'the local phar must stay untouched');
            self::assertFileDoesNotExist($phar . '.bak', 'nothing was backed up either');
        }
    }

    /**
     * Every shape below is close enough to a release asset to be worth
     * refusing on purpose; each falls back to the latest/ pair.
     */
    #[DataProvider('untrustedUrls')]
    public function testAnUntrustedUrlFallsBackToTheLatestPair(string $url): void
    {
        $sum = hash('sha256', 'new');
        $phar = $this->pharFile('old');
        $history = [];
        $updater = $this->updater($phar, [
            $this->manifest('2.1.0', $sum, $url),
            new Response(200, [], $sum),
            new Response(200, [], 'new'),
        ], '2.0.0', $history);

        self::assertSame(self::PHAR_URL, $updater->downloadUrl());
        $updater->update();

        self::assertSame('new', file_get_contents($phar));
        self::assertSame([self::MANIFEST_URL, self::SHA_URL, self::PHAR_URL], $this->uris($history));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function untrustedUrls(): array
    {
        $releases = 'https://github.com/wp-content-io/wpc-cli/releases/download/';

        return [
            'plain HTTP' => ['http://github.com/wp-content-io/wpc-cli/releases/download/v2.1.0/wpc.phar'],
            'userinfo' => ['https://github.com/wp-content-io/wpc-cli/releases/download/v2.1.0/wpc.phar@evil.example/wpc.phar'],
            'userinfo before the host' => ['https://github.com@evil.example/wp-content-io/wpc-cli/releases/download/v2.1.0/wpc.phar'],
            'an explicit port' => ['https://github.com:443/wp-content-io/wpc-cli/releases/download/v2.1.0/wpc.phar'],
            'another organisation' => ['https://github.com/someone-else/wpc-cli/releases/download/v2.1.0/wpc.phar'],
            'another repository' => ['https://github.com/wp-content-io/wpc-cli-fork/releases/download/v2.1.0/wpc.phar'],
            'a lookalike host' => ['https://github.com.evil.example/wp-content-io/wpc-cli/releases/download/v2.1.0/wpc.phar'],
            'a tag without v' => [$releases . '2.1.0/wpc.phar'],
            'no tag' => [$releases . 'wpc.phar'],
            'a nested path' => [$releases . 'v2.1.0/sub/wpc.phar'],
            'a dot segment' => [$releases . 'v2.1.0/../../../../evil/wpc.phar'],
            'a dot segment in the tag' => [$releases . 'v2.1.0-../wpc.phar'],
            'an encoded dot segment' => [$releases . 'v2.1.0/%2e%2e/wpc.phar'],
            'an upper-case encoded dot segment' => [$releases . 'v2.1.0/%2E%2E/wpc.phar'],
            'a query' => [$releases . 'v2.1.0/wpc.phar?redirect=https://evil.example'],
            'a fragment' => [$releases . 'v2.1.0/wpc.phar#@evil.example'],
            'a dot segment on the own host' => ['https://downloads.example/1.1.3/../../wpc.phar'],
            'a port on the own host' => ['https://downloads.example:8443/1.1.3/wpc.phar'],
        ];
    }

    /**
     * @param array<int, array{request: RequestInterface}> $history
     *
     * @return list<string>
     */
    private function uris(array $history): array
    {
        return array_values(array_map(static fn (array $entry): string => (string) $entry['request']->getUri(), $history));
    }

    private function manifest(string $version, ?string $sha256 = null, ?string $url = null): Response
    {
        $data = array_filter(['name' => 'wpc', 'version' => $version, 'sha256' => $sha256, 'url' => $url], static fn (?string $value): bool => $value !== null);

        return new Response(200, [], (string) json_encode($data));
    }

    private function pharFile(string $contents): string
    {
        $path = $this->makeTempDir() . '/wpc.phar';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param list<Response>                                  $responses
     * @param array<int, array{request: RequestInterface}>|null $history  filled with every request sent, when given
     */
    private function updater(string $phar, array $responses, ?string $version = null, ?array &$history = null): Updater
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }
        $client = new Client(['handler' => $stack]);

        // Without a local version the manifest is never read: the historical,
        // checksum-only behaviour the first tests pin.
        return $version === null
            ? new Updater($phar, self::PHAR_URL, self::SHA_URL, $client)
            : new Updater($phar, self::PHAR_URL, self::SHA_URL, $client, self::MANIFEST_URL, $version);
    }
}
