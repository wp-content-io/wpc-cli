<?php

namespace WpContent\Cli\Api;

use Psr\Http\Message\ResponseInterface;
use WpContent\Cli\Results\CommandFailure;

/**
 * A call to the registry that did not go through.
 *
 * A `RuntimeException` (through {@see CommandFailure}), not an `\Error`: the
 * engine's error hierarchy is for things the program got wrong — a type, a
 * division — and inheriting from it put a perfectly ordinary "the server said
 * 404" outside the reach of every `catch (Exception)` in the process.
 */
class ApiError extends CommandFailure
{
    /**
     * Whether nothing answered at all, as opposed to answering badly.
     *
     * Not derived from the code: a transport failure and a malformed payload
     * both carry 0, and only one of them is a reason to go and check the URL.
     */
    private bool $unreachable = false;

    /**
     * What the registry answers when it cannot tie the `x-api-key` to an
     * organization — a key that is missing, mistyped or revoked. It comes back
     * as a 404, so on the code alone it reads as a wrong slug.
     *
     * Only registries from before the 2026 relaunch answer this way: the
     * current one says 401 ("Missing API key…", "Invalid API key."), which the
     * status-based hint already covers. Kept for as long as a CLI may talk to an
     * older deployment; safe to drop once none is left.
     */
    private const UNKNOWN_KEY_MESSAGE = 'Organization not found.';

    /** The headline the registry gave, before any default replaced it. */
    private ?string $serverMessage = null;

    /**
     * The registry is CodeIgniter 4, whose `fail()` answers
     * `{"status": 404, "error": 404, "messages": {"error": "…"}}` — or, for a
     * validation failure, one entry per field under `messages`. Only an
     * uncaught exception carries a top-level `message`. Reading that field
     * alone, as this used to, meant every deliberate failure was reported with
     * the generic status text and the registry's own sentence was lost.
     */
    public function __construct(int $code = 0, ?ResponseInterface $response = null, string $defaultMessage = 'Internal Server Error')
    {
        $message = match ($code) {
            403 => 'Forbidden : please check provided key',
            402 => 'Payment Required',
            401 => 'Unauthorized',
            404 => 'Not Found',
            400 => 'Bad Request',
            default => $defaultMessage,
        };

        $data = $response ? json_decode((string) $response->getBody(), true) : null;

        if (is_array($data)) {
            $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
            $this->serverMessage = $this->headline($data, $messages);
            $message = $this->serverMessage ?? $message;

            // `messages.error` is CI4's generic slot, not a field: once it is
            // the headline, repeating it underneath says the same thing twice.
            // A named field stays, even when it is the headline too — which
            // field was refused is what a script branches on.
            if ($this->serverMessage !== null && ($messages['error'] ?? null) === $this->serverMessage) {
                unset($messages['error']);
            }

            $this->validationErrors = $messages;
        }

        parent::__construct($message, $code);
    }

    /**
     * The sentence worth putting first: an uncaught exception's `message`, the
     * `messages.error` of a deliberate failure, or the only message there is.
     *
     * @param array<mixed>            $data
     * @param array<array-key, mixed> $messages
     */
    private function headline(array $data, array $messages): ?string
    {
        foreach ([$data['message'] ?? null, $messages['error'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        $only = count($messages) === 1 ? reset($messages) : null;

        return is_string($only) && trim($only) !== '' ? $only : null;
    }

    /**
     * Nothing answered at $repository.
     *
     * A refused connection, an unknown host or a TLS handshake that failed all
     * carry the code 0, which used to fall through to the default message — so a
     * typo in `--repository` was reported as "Internal Server Error" and read as
     * an outage at the other end. Name the address instead: it is the one thing
     * that tells the user where to look.
     */
    public static function unreachable(string $repository, string $reason = ''): self
    {
        $error = new self(0, null, sprintf('Could not reach the registry at %s', $repository));
        $error->unreachable = true;

        if ($reason !== '') {
            $error->validationErrors = ['cause' => $reason];
        }

        return $error;
    }

    protected function hint(): ?string
    {
        if ($this->unreachable) {
            return 'Check the repository URL (--repository or WPC_REPO_URL) and that the host is reachable.';
        }

        // A key the registry does not know comes back as a 404 naming the
        // organization; pointing at the slug sent people looking in the wrong place.
        if ($this->getCode() === 404 && $this->serverMessage === self::UNKNOWN_KEY_MESSAGE) {
            return 'The registry does not recognise this API key: check --api-key or WPC_API_KEY.';
        }

        return match ($this->getCode()) {
            401, 403 => 'Check your API key (--api-key or WPC_API_KEY).',
            402 => "Your plan's limit has been reached: see your organization's subscription in the dashboard.",
            404 => 'Check the slug and the repository URL (--repository or WPC_REPO_URL).',
            default => null,
        };
    }
}
