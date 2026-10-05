<?php

namespace WpContent\Cli\Api;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use WpContent\Cli\Runtime;

/**
 * The registry over HTTP.
 *
 * The Guzzle client is built on the first call rather than in the constructor:
 * the commands receive this object when the application is wired, which is
 * before the input — and therefore the repository URL and the API key — is known.
 */
final class GuzzleRegistryClient implements RegistryClient
{
    /**
     * Seconds to wait for the connection itself. Deliberately not a total
     * timeout: an upload may legitimately take minutes, but dialling a host
     * that is not there should never take more than this.
     */
    private const CONNECT_TIMEOUT = 10;

    /** Total budget for the optional lookups that hold a prompt up. */
    private const OPTIONAL_TIMEOUT = 5;

    /**
     * Total budget for a read.
     *
     * A host that accepts the connection and then says nothing would otherwise
     * hang forever — and the reads the TUI makes (a page turn, a record) happen
     * inside the raw-mode loop, where `-isig` has turned Ctrl+C into a byte
     * nobody is left to read. Only `get()` gets it: an upload may legitimately
     * take minutes.
     */
    private const READ_TIMEOUT = 30;

    private ?ClientInterface $client = null;

    /**
     * @param ClientInterface|null $client a pre-built client, for the tests that
     *                                     exercise this class itself
     */
    public function __construct(
        private readonly Runtime $runtime,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client;
    }

    public function get(string $endpoint): mixed
    {
        return $this->request('GET', $endpoint, [RequestOptions::TIMEOUT => self::READ_TIMEOUT]);
    }

    public function upload(string $endpoint, array $files = [], array $fields = [], ?callable $onProgress = null): mixed
    {
        $handles = [];
        $uploadSize = 0;
        $multipart = [];

        foreach ($files as $name => $path) {
            $handle = @fopen($path, 'r');
            if ($handle === false) {
                throw new ApiError(0, null, sprintf('Could not read %s.', $path));
            }

            $handles[] = $handle;
            $multipart[] = [
                'name' => $name,
                'contents' => $handle,
                'filename' => basename($path),
            ];
            $uploadSize += (int) filesize($path);
        }

        foreach ($fields as $name => $value) {
            $multipart[] = [
                'name' => $name,
                'contents' => $value,
            ];
        }

        $options = [RequestOptions::MULTIPART => $multipart];

        if ($onProgress !== null) {
            $options[RequestOptions::PROGRESS] = static function ($downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes) use ($uploadSize, $onProgress): void {
                // Prefer the transfer's own total: the uploaded byte count covers
                // the whole multipart envelope, which is larger than the files
                // alone and would otherwise report more than 100%.
                $total = (int) $uploadTotal > 0 ? (int) $uploadTotal : $uploadSize;

                $onProgress(min((int) $uploadedBytes, $total), $total);
            };
        }

        try {
            return $this->request('POST', $endpoint, $options);
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    public function author(): array
    {
        try {
            $response = $this->client()->request('GET', '/author', [
                RequestOptions::HEADERS => $this->headers(),
                RequestOptions::CONNECT_TIMEOUT => self::OPTIONAL_TIMEOUT,
                RequestOptions::TIMEOUT => self::OPTIONAL_TIMEOUT,
            ]);

            return (array) $this->json($response);
        } catch (Exception) {
            return [];
        }
    }

    /**
     * @param array<mixed> $options
     *
     * @throws ApiError
     */
    private function request(string $method, string $endpoint, array $options = []): mixed
    {
        try {
            return $this->json($this->client()->request($method, $endpoint, [RequestOptions::HEADERS => $this->headers()] + $options));
        } catch (ConnectException $e) {
            // No response at all: a refused connection, an unknown host, a TLS
            // handshake that failed. Reported apart from the HTTP failures so it
            // can name the address instead of inventing a server error.
            throw ApiError::unreachable($this->runtime->repository(), $e->getMessage());
        } catch (GuzzleException $e) {
            throw new ApiError($e->getCode(), $e instanceof RequestException ? $e->getResponse() : null);
        } catch (JsonException $e) {
            throw new ApiError(0, null, sprintf('The registry did not answer with valid JSON (%s).', $e->getMessage()));
        }
    }

    /**
     * @throws JsonException
     */
    private function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Sent with every request rather than baked into the client, so a client
     * handed in from outside — the tests' — still carries them.
     *
     * `Accept: application/json` because every answer is decoded as JSON:
     * without it, CodeIgniter negotiates its error pages from the request, and
     * a failure could come back as HTML that no message can be read from.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return array_filter([
            'Accept' => 'application/json',
            'x-api-key' => $this->runtime->apiKey(),
        ], static fn (?string $value): bool => $value !== null);
    }

    private function client(): ClientInterface
    {
        return $this->client ??= new Client([
            'base_uri' => $this->runtime->repository(),
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT,
        ]);
    }
}
