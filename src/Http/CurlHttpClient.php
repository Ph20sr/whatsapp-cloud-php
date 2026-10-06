<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Http;

use Ph20sr\WhatsApp\ConnectionException;

final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $responseHeaders = [];
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => "{$name}: {$value}",
                array_keys($headers),
                $headers,
            ),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);
        if ($result === false) {
            $error = curl_error($handle);
            throw new ConnectionException("Falha de conexão com a API do WhatsApp: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new Response($status, (string) $result, $responseHeaders);
    }
}
