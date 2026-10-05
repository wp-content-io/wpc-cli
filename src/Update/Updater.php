<?php

namespace WpContent\Cli\Update;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use RuntimeException;

/**
 * Minimal, dependency-free phar self-updater. Downloads the latest phar over
 * HTTPS and installs it only if its SHA-256 matches the published checksum,
 * keeping a backup for rollback.
 *
 * What gets downloaded, and against which sum, comes from the release manifest
 * when it pins both: its `url` names the *versioned* phar, which CI never
 * overwrites, and its `sha256` sits in the same document — one immutable object
 * and its sum, read in one request. The mutable `latest/wpc.phar` and
 * `latest/wpc.phar.sha256` are two objects CI cannot replace atomically, so
 * reading them as a pair always had a window where they described different
 * builds and a perfectly good release failed the integrity check. They remain
 * the fallback — a manifest that is missing, unreadable or pins nothing usable
 * — and are still published for the clients that predate the manifest.
 *
 * Whether there is anything to install is decided on versions when both sides
 * have one ({@see Version}), and on checksums otherwise. Checksums alone only
 * say "different": a phar newer than the published one — a pre-release, a
 * build from a branch — read as out of date and was "updated" down to latest.
 */
final class Updater
{
    /**
     * The one place outside the manifest's own host a pinned `url` may point
     * to: an asset of a tagged release of the public repository, such as
     * `…/releases/download/v2.1.0/wpc.phar`.
     *
     * The sum the manifest carries is what guarantees *what* is installed;
     * this list only limits *where* it is fetched from, so that a manifest
     * nobody meant to write cannot send every client to a host nobody
     * publishes to. It is matched as a literal prefix on the raw URL — so no
     * other host, organisation or repository — and the rest must be exactly
     * `v<release>/<file name>` ({@see isTrustedUrl()} for the rest).
     *
     * Clients up to 2.0.0 only trust the manifest's own host: they read a
     * GitHub URL as "no usable pin" and fall back to the `latest/` pair, which
     * is why CI keeps publishing it.
     */
    public const GITHUB_RELEASES = 'https://github.com/wp-content-io/wpc-cli/releases/download/';

    /** What has to follow {@see GITHUB_RELEASES}: `v<release>/<file name>`. */
    private const GITHUB_ASSET = '#^v\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?/[A-Za-z0-9][A-Za-z0-9._-]*$#';

    /**
     * What every download is sent with. GitHub answers a release asset with a
     * 302 to its storage host, so redirects have to be followed — but only to
     * HTTPS, a handful of times, and without telling the next host where the
     * request came from. The sum is checked on the bytes the *last* hop
     * returned, wherever the chain ended.
     */
    private const REQUEST_OPTIONS = [
        'allow_redirects' => [
            'max' => 5,
            'strict' => true,
            'referer' => false,
            'protocols' => ['https'],
            'track_redirects' => true,
        ],
    ];

    private ClientInterface $client;

    private ?string $cachedRemoteSha = null;

    /**
     * The manifest as read once per run — null until fetched. Each field is
     * null when the manifest does not carry it in a usable shape.
     *
     * @var array{version: ?string, sha256: ?string, url: ?string}|null
     */
    private ?array $cachedManifest = null;

    public function __construct(
        private readonly string $localPhar,
        private readonly string $pharUrl,
        private readonly string $shaUrl,
        ?ClientInterface $client = null,
        private readonly ?string $manifestUrl = null,
        private readonly ?string $localVersion = null,
    ) {
        $this->client = $client ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    public function hasUpdate(): bool
    {
        $local = Version::release($this->localVersion);
        $published = $this->publishedVersion();

        // Never down, and never sideways: a build at or past the published
        // release has nothing to gain from it, whatever its checksum says.
        if ($local !== null && $published !== null && version_compare($local, $published, '>=')) {
            return false;
        }

        return !hash_equals($this->localSha(), $this->remoteSha());
    }

    /**
     * The published version, when the update would cross a major version
     * (2.x → 3.0) — or null when it would not, or when either side's version
     * is unknown. A major release may rename what a script relies on, so
     * crossing one is never done without being asked for.
     */
    public function majorUpgrade(): ?string
    {
        $local = Version::release($this->localVersion);
        $published = $this->publishedVersion();

        if ($local === null || $published === null) {
            return null;
        }

        return Version::major($published) > Version::major($local) ? $published : null;
    }

    /**
     * The version the release manifest announces, or null when it cannot be
     * read — unpublished, unreachable, malformed or not release-shaped. That
     * is not an error: it only means the decision falls back to checksums.
     */
    public function publishedVersion(): ?string
    {
        return $this->manifest()['version'];
    }

    /**
     * Where the phar to install is downloaded from: the versioned URL the
     * manifest pins, or the mutable `latest/` alias when it pins none.
     */
    public function downloadUrl(): string
    {
        return $this->manifest()['url'] ?? $this->pharUrl;
    }

    /**
     * Download and install the latest phar. No-op if already up to date.
     *
     * @param bool $allowMajor whether crossing a major version was asked for ({@see majorUpgrade()})
     *
     * @throws RuntimeException on checksum mismatch or when the phar cannot be replaced
     */
    public function update(bool $allowMajor = false): void
    {
        if (!$this->hasUpdate()) {
            return;
        }

        // The command asks first and says it better; this is the backstop.
        if (!$allowMajor && ($major = $this->majorUpgrade()) !== null) {
            throw new UpdateError(sprintf('wpc %s is a new major version; refusing to install it without being asked to.', $major));
        }

        $expected = $this->remoteSha();
        $payload = $this->fetch($this->downloadUrl());
        if (!hash_equals($expected, hash('sha256', $payload))) {
            throw new RuntimeException('Checksum mismatch: refusing to install the downloaded file.');
        }

        $directory = dirname($this->localPhar);
        if (!is_writable($this->localPhar) || !is_writable($directory)) {
            throw new RuntimeException(sprintf('%s is not writable. Retry with elevated privileges (sudo).', $this->localPhar));
        }

        // Capture the current mode before anything is replaced so both the backup
        // and the incoming phar keep the executable bit.
        $permissions = $this->currentPermissions();

        // Back up first and abort if it fails, otherwise a later --rollback would
        // have nothing (or a stale version) to restore even though update() "succeeded".
        if (!@copy($this->localPhar, $this->backupPath())) {
            throw new RuntimeException('Could not back up the current version; aborting the update.');
        }
        @chmod($this->backupPath(), $permissions);

        $temp = $directory . '/.' . basename($this->localPhar) . '.' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, $payload) === false) {
            throw new RuntimeException('Could not write the downloaded file.');
        }
        @chmod($temp, $permissions);

        if (!@rename($temp, $this->localPhar)) {
            @unlink($temp);

            throw new RuntimeException(sprintf('Could not replace %s.', $this->localPhar));
        }
    }

    /**
     * @throws RuntimeException when there is no backup or it cannot be restored
     */
    public function rollback(): void
    {
        $backup = $this->backupPath();
        if (!is_file($backup)) {
            throw new RuntimeException('No previous version found to roll back to.');
        }

        // Read the mode of the phar being replaced so the restored file stays
        // executable even if the backup itself lost its permission bits.
        $permissions = is_file($this->localPhar) ? $this->currentPermissions() : 0755;

        if (!@rename($backup, $this->localPhar)) {
            throw new RuntimeException(sprintf('Could not restore %s.', $this->localPhar));
        }
        @chmod($this->localPhar, $permissions);
    }

    public function backupPath(): string
    {
        return $this->localPhar . '.bak';
    }

    public function localSha(): string
    {
        return (string) hash_file('sha256', $this->localPhar);
    }

    public function remoteSha(): string
    {
        // The published checksum does not change within a single command run, so
        // fetch it once ('self-update' calls hasUpdate() then update()).
        if ($this->cachedRemoteSha !== null) {
            return $this->cachedRemoteSha;
        }

        // The sum that travels with the URL being downloaded, when there is one.
        $pinned = $this->manifest()['sha256'];
        if ($pinned !== null) {
            return $this->cachedRemoteSha = $pinned;
        }

        $sha = trim($this->fetch($this->shaUrl));
        if (!preg_match('/^[a-f0-9]{64}$/i', $sha)) {
            throw new RuntimeException('The published checksum is invalid.');
        }

        return $this->cachedRemoteSha = strtolower($sha);
    }

    /**
     * Read the release manifest once per run: `self-update` asks for the
     * version, the sum and the URL in turn, and they must all come from the
     * same document — two reads could straddle a publication.
     *
     * `sha256` and `url` are kept only *together*, and only when the URL is
     * one {@see isTrustedUrl()} accepts: a sum without its URL would be checked
     * against `latest/wpc.phar`, which is exactly the pairing this avoids, and
     * a manifest pointing elsewhere is not followed — the update is installed
     * on the manifest's word alone, so it must not be able to send the client
     * to a host nobody publishes to. Anything less falls back to `latest/`.
     *
     * @return array{version: ?string, sha256: ?string, url: ?string}
     */
    private function manifest(): array
    {
        if ($this->cachedManifest !== null) {
            return $this->cachedManifest;
        }

        $data = null;
        if ($this->manifestUrl !== null) {
            try {
                $data = json_decode($this->fetch($this->manifestUrl), true);
            } catch (UpdateError) {
                $data = null;
            }
        }
        $data = is_array($data) ? $data : [];

        $version = is_string($data['version'] ?? null) ? Version::release($data['version']) : null;
        $sha = $data['sha256'] ?? null;
        $url = $data['url'] ?? null;

        $pinned = is_string($sha) && preg_match('/^[a-f0-9]{64}$/i', $sha) === 1
            && is_string($url) && $this->isTrustedUrl($url);

        return $this->cachedManifest = [
            'version' => $version,
            'sha256' => $pinned ? strtolower($sha) : null,
            'url' => $pinned ? $url : null,
        ];
    }

    /**
     * Whether a manifest may pin $url: HTTPS on the manifest's own host, or a
     * release asset of the public repository ({@see GITHUB_RELEASES}).
     *
     * Either way the URL has to be plain: no userinfo (`https://own.host@else/`
     * is a request to `else`), no explicit port, no query or fragment, and no
     * dot segment, spelled out or percent-encoded — a path that climbs out of
     * the release directory is not one CI ever writes.
     */
    private function isTrustedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
            || str_contains($url, '..') || stripos($url, '%2e') !== false
            || !is_string($parts['host'] ?? null)) {
            return false;
        }

        if (str_starts_with($url, self::GITHUB_RELEASES)) {
            return preg_match(self::GITHUB_ASSET, substr($url, strlen(self::GITHUB_RELEASES))) === 1;
        }

        $expected = parse_url((string) $this->manifestUrl, PHP_URL_HOST);

        return is_string($expected) && strcasecmp($parts['host'], $expected) === 0;
    }

    /**
     * GET $url, turning what the download host answers into a sentence.
     *
     * Guzzle's own message quotes the start of the response body, and the
     * bucket answers a missing object with an S3 XML document: a sum not
     * published yet came out as half a screen of `<Error><Code>AccessDenied…`.
     * The bucket answers 403 rather than 404 for an object that does not
     * exist, so both read as "not published".
     *
     * A redirect Guzzle refused to follow (one leaving HTTPS) surfaces as a
     * 3xx "bad response"; it is named for what it is rather than as a status
     * the host never meant as an error.
     *
     * @throws UpdateError
     */
    private function fetch(string $url): string
    {
        try {
            return (string) $this->client->request('GET', $url, self::REQUEST_OPTIONS)->getBody();
        } catch (BadResponseException $e) {
            $status = $e->getResponse()->getStatusCode();

            if ($status >= 300 && $status < 400) {
                throw new UpdateError(sprintf(
                    'Refusing to follow the redirect from %s to %s: downloads are only made over HTTPS.',
                    $e->getRequest()->getUri(),
                    $e->getResponse()->getHeaderLine('Location')
                ));
            }

            throw new UpdateError(in_array($status, [403, 404], true)
                ? sprintf('No release is published at %s yet (HTTP %d).', $url, $status)
                : sprintf('The download host answered HTTP %d for %s.', $status, $url));
        } catch (TooManyRedirectsException) {
            throw new UpdateError(sprintf('Too many redirects while downloading %s.', $url));
        } catch (GuzzleException) {
            throw new UpdateError(sprintf('Could not reach %s.', $url));
        }
    }

    private function currentPermissions(): int
    {
        $perms = @fileperms($this->localPhar);

        return $perms !== false ? ($perms & 0777) : 0755;
    }
}
