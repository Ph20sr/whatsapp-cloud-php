<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Http;

final class Response
{
    /** @param array<string, string> $headers nomes em minúsculas */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
