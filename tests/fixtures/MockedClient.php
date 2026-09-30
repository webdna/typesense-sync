<?php

namespace webdna\typesensesync\tests\fixtures;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use webdna\typesensesync\services\Client;

/**
 * The client service with its HTTP answered from a queue instead of a server: every Guzzle
 * client it creates shares one queue and one request history.
 */
class MockedClient extends Client
{
    public MockHandler $handler;

    /**
     * @var list<array{request: RequestInterface}>
     */
    public array $history = [];

    /**
     * The config each Guzzle client was created with.
     *
     * @var list<array<string, mixed>>
     */
    public array $guzzleConfigs = [];

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    public function __construct(array $queue = [])
    {
        parent::__construct();
        $this->handler = new MockHandler($queue);
    }

    /**
     * The path and API key of each request made, in order.
     *
     * @return list<string>
     */
    public function requests(): array
    {
        return array_map(
            fn(array $entry) => sprintf(
                '%s %s [%s]',
                $entry['request']->getMethod(),
                $entry['request']->getUri()->getPath(),
                $entry['request']->getHeaderLine('X-TYPESENSE-API-KEY'),
            ),
            $this->history,
        );
    }

    protected function createGuzzleClient(array $config): GuzzleClient
    {
        $this->guzzleConfigs[] = $config;
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));

        return new GuzzleClient($config + ['handler' => $stack]);
    }
}
