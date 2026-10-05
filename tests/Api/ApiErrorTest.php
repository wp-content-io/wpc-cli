<?php

namespace WpContent\Cli\Tests\Api;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use WpContent\Cli\Api\ApiError;

final class ApiErrorTest extends TestCase
{
    public function testJsonPayloadKeepsItsShape(): void
    {
        // The machine-readable shape is a contract: code, message, errors.
        $error = $this->failure(400, ['version' => 'Version must increase', 'requires_php' => 'Invalid version']);

        self::assertSame(
            [
                'code' => 400,
                'message' => 'Bad Request',
                'errors' => ['version' => 'Version must increase', 'requires_php' => 'Invalid version'],
            ],
            $this->payload($error)
        );
    }

    /**
     * CodeIgniter's `fail()` puts the sentence under `messages.error`; it used
     * to be dropped for the bare status text, and then repeated as a "detail".
     */
    public function testTheRegistrysOwnSentenceIsTheHeadline(): void
    {
        self::assertSame(
            ['code' => 404, 'message' => 'Plugin not found.', 'errors' => []],
            $this->payload($this->failure(404, ['error' => 'Plugin not found.']))
        );
    }

    /**
     * A single validation message is the headline, and its field stays in
     * `errors` — which field was refused is what a script needs.
     */
    public function testASingleValidationMessageIsTheHeadline(): void
    {
        self::assertSame(
            ['code' => 400, 'message' => 'Version must increase', 'errors' => ['version' => 'Version must increase']],
            $this->payload($this->failure(400, ['version' => 'Version must increase']))
        );
    }

    /** Only an uncaught exception carries a top-level `message`, and it wins. */
    public function testAnUncaughtExceptionsMessageIsTheHeadline(): void
    {
        $error = new ApiError(500, new Response(500, [], (string) json_encode([
            'title' => 'ErrorException',
            'type' => 'ErrorException',
            'code' => 500,
            'message' => 'Undefined array key "slug"',
        ])));

        self::assertSame('Undefined array key "slug"', $error->getMessage());
    }

    public function testABodyThatIsNotJsonKeepsTheStatusText(): void
    {
        $error = new ApiError(502, new Response(502, [], '<html>Bad Gateway</html>'), 'Bad Gateway');

        self::assertSame(['code' => 502, 'message' => 'Bad Gateway', 'errors' => []], $this->payload($error));
    }

    /**
     * A missing or unknown key comes back as a 404 naming the organization.
     * Sending the user to check the slug was sending them the wrong way.
     */
    public function testAnUnknownKeyPointsAtTheKeyNotTheSlug(): void
    {
        $rendered = $this->human($this->failure(404, ['error' => 'Organization not found.']));

        self::assertStringContainsString('Organization not found.', $rendered);
        self::assertStringContainsString('API key', $rendered);
        self::assertStringNotContainsString('slug', $rendered);

        self::assertStringContainsString('slug', $this->human($this->failure(404, ['error' => 'Plugin not found.'])));
    }

    public function testAPlanLimitPointsAtTheSubscription(): void
    {
        $error = $this->failure(402, ['error' => 'You have reached the maximum number of plugins for your plan.']);

        self::assertSame('You have reached the maximum number of plugins for your plan.', $error->getMessage());
        self::assertStringContainsString('subscription', $this->human($error));
        self::assertSame('Payment Required', (new ApiError(402))->getMessage());
    }

    /**
     * A transport failure carries no HTTP status, and `"code": 0` reads as a
     * success to anything checking the field rather than the exit status — the
     * README promises a non-zero value there.
     */
    public function testAFailureWithNoHttpStatusStillReportsANonZeroCode(): void
    {
        foreach ([
            ApiError::unreachable('http://registry.test', 'cURL error 7'),
            new ApiError(0, null, 'The registry did not answer with valid JSON.'),
        ] as $error) {
            $output = new BufferedOutput();
            $error->json($output);

            $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($payload);
            self::assertNotSame(0, $payload['code'], $error->getMessage());
        }
    }

    public function testHumanRenderingListsValidationErrors(): void
    {
        $rendered = $this->human($this->failure(400, ['version' => 'Version must increase', 'tested' => 'Invalid version']));

        self::assertStringContainsString('Bad Request', $rendered);
        self::assertStringContainsString('version', $rendered);
        self::assertStringContainsString('Version must increase', $rendered);
        self::assertStringContainsString('tested', $rendered);
    }

    public function testAuthenticationFailureSuggestsCheckingTheKey(): void
    {
        $output = new BufferedOutput();
        (new ApiError(403))->human($output);

        self::assertStringContainsString('API key', $output->fetch());
    }

    public function testMessageContainingMarkupIsNotSwallowed(): void
    {
        $error = $this->failure(400, ['error' => 'Requires PHP <8.2, and a recent MySQL']);

        self::assertStringContainsString('Requires PHP <8.2, and a recent MySQL', $this->human($error));
    }

    /**
     * What CodeIgniter 4's `fail()` actually sends.
     *
     * @param array<string, string> $messages
     */
    private function failure(int $status, array $messages): ApiError
    {
        return new ApiError($status, new Response($status, [], (string) json_encode([
            'status' => $status,
            'error' => $status,
            'messages' => $messages,
        ])));
    }

    /**
     * @return array<mixed>
     */
    private function payload(ApiError $error): array
    {
        $output = new BufferedOutput();
        $error->json($output);

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function human(ApiError $error): string
    {
        $output = new BufferedOutput();
        $error->human($output);

        return $output->fetch();
    }
}
