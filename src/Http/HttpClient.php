<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Http;

/**
 * Transporte HTTP. Implemente esta interface para usar Guzzle, Symfony
 * HttpClient ou um fake nos testes.
 */
interface HttpClient
{
    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): Response;
}
