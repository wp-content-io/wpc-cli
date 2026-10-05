<?php

namespace WpContent\Cli\Update;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Resolves the latest published wpc version for the passive "new version
 * available" notice. The remote manifest is fetched at most once per 24h
 * (cached on disk) and every failure is swallowed so it can never break or
 * slow down the command being run.
 */
final class UpdateChecker
{
    private const CACHE_TTL = 86400;

    private ?ClientInterface $client;

    private ?int $now;

    public function __construct(
        private readonly string $manifestUrl,
        private readonly string $currentVersion,
        private readonly string $cacheFile,
        ?ClientInterface $client = null,
        ?int $now = null,
    ) {
        $this->client = $client;
        $this->now = $now;
    }

    /**
     * The latest version if it is strictly newer than the current one, else
     * null — without a `v` either way ({@see Version::display()}).
     *
     * Both sides are compared in their release form, so a `git describe` build
     * (`v2.1.0-3-gabc`) counts as the tag it was built on rather than as a
     * pre-release of it, the same reading `self-update` makes.
     */
    public function latestIfNewer(): ?string
    {
        $latest = $this->latestVersion();
        if ($latest === null) {
            return null;
        }

        return version_compare($this->comparable($latest), $this->comparable($this->currentVersion), '>')
            ? Version::display($latest)
            : null;
    }

    private function latestVersion(): ?string
    {
        $cache = $this->readCache();
        if ($cache !== null && ($this->clock() - $cache['checked_at']) < self::CACHE_TTL) {
            return $cache['version'];
        }

        $version = $this->fetchVersion();
        $this->writeCache($version);

        return $version;
    }

    private function fetchVersion(): ?string
    {
        try {
            $client = $this->client ?? new Client(['timeout' => 3, 'connect_timeout' => 2]);
            $body = (string) $client->request('GET', $this->manifestUrl)->getBody();
            $data = json_decode($body, true);
            $version = is_array($data) ? ($data['version'] ?? null) : null;

            return is_string($version) && $version !== '' ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{checked_at: int, version: ?string}|null
     */
    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($this->cacheFile), true);
        if (!is_array($data) || !isset($data['checked_at'])) {
            return null;
        }

        $version = $data['version'] ?? null;

        return [
            'checked_at' => (int) $data['checked_at'],
            'version' => is_string($version) ? $version : null,
        ];
    }

    private function writeCache(?string $version): void
    {
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @file_put_contents(
            $this->cacheFile,
            (string) json_encode(['checked_at' => $this->clock(), 'version' => $version])
        );
    }

    private function comparable(string $version): string
    {
        return Version::release($version) ?? Version::display($version);
    }

    private function clock(): int
    {
        return $this->now ?? time();
    }
}
