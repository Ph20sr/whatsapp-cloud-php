<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Tests;

use Ph20sr\WhatsApp\Http\HttpClient;
use Ph20sr\WhatsApp\Http\Response;

/** Devolve respostas enfileiradas e registra cada requisição enviada. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var list<Response> */
    private array $queue = [];

    public function push(int $status, array $body = []): self
    {
        $this->queue[] = new Response($status, json_encode($body));
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        return array_shift($this->queue) ?? throw new \LogicException("Requisição inesperada: {$method} {$url}");
    }

    /** @return array<string, mixed> corpo JSON da última requisição */
    public function lastBody(): array
    {
        return json_decode((string) $this->requests[array_key_last($this->requests)]['body'], true);
    }
}
