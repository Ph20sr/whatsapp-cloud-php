<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Webhook;

/** Mudança de status de uma mensagem enviada: sent, delivered, read ou failed. */
final class StatusUpdate
{
    /**
     * @param list<array{code: int, title: string}> $errors
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $status,
        public readonly string $recipient,
        public readonly \DateTimeImmutable $timestamp,
        public readonly array $errors = [],
    ) {
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
