<?php

namespace WpContent\Cli\Tests\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Input\StringInput;
use WpContent\Cli\Api\ApiError;
use WpContent\Cli\Api\GuzzleRegistryClient;
use WpContent\Cli\Config;
use WpContent\Cli\Runtime;
use WpContent\Cli\Tests\TempDirTrait;

/**
 * What actually goes over the wire, read off a Guzzle history middleware.
 */
final class GuzzleRegistryClientTest extends TestCase
{
    use TempDirTrait;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    public function testEveryRequestAsksForJsonAndCarriesTheKey(): void
    {
        $client = $this->client(new Response(200, [], '{"plugins":[]}'), new Response(200, [], '{"name":"Jane"}'));

        $client->get('/plugins?page=1');
        $client->author();

        self::assertCount(2, $this->history);
        foreach ($this->history as ['request' => $request]) {
            self::assertSame('application/json', $request->getHeaderLine('Accept'), (string) $request->getUri());
            self::assertSame('secret', $request->getHeaderLine('x-api-key'), (string) $request->getUri());
        }
    }

    public function testTheUploadGoesToTheSameAbsolutePathAsTheReads(): void
    {
        $artifact = $this->makeTempDir() . '/acme.zip';
        $this->writeFile($artifact, 'zip');

        $this->client(new Response(201, [], '{"slug":"acme"}'))->upload('/plugins', ['plugin' => $artifact]);

        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/plugins', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testARegistryFailureIsReadOffItsBody(): void
    {
        $client = $this->client(new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
            'status' => 404,
            'error' => 404,
            'messages' => ['error' => 'Organization not found.'],
        ])));

        try {
            $client->get('/plugins/acme');
            self::fail('a 404 must throw');
        } catch (ApiError $e) {
            self::assertSame(404, $e->getCode());
            self::assertSame('Organization not found.', $e->getMessage());
        }
    }

    private function client(Response ...$responses): GuzzleRegistryClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $runtime = new Runtime();
        $runtime->resolve(new Config(new StringInput('--repository=http://registry.test --api-key=secret')), false);

        return new GuzzleRegistryClient($runtime, new Client(['handler' => $stack, 'base_uri' => 'http://registry.test']));
    }
}
