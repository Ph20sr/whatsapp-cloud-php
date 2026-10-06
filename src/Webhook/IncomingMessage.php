<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Webhook;

/** Mensagem recebida de um cliente. */
final class IncomingMessage
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly ?string $contactName,
        public readonly string $type,
        public readonly \DateTimeImmutable $timestamp,
        public readonly string $phoneNumberId,
        public readonly array $raw,
    ) {
    }

    /** Texto digitado, ou o título do botão/item escolhido, ou a legenda da mídia. */
    public function text(): ?string
    {
        return match ($this->type) {
            'text' => $this->raw['text']['body'] ?? null,
            'interactive' => $this->raw['interactive']['button_reply']['title']
                ?? $this->raw['interactive']['list_reply']['title'] ?? null,
            'button' => $this->raw['button']['text'] ?? null,
            'image', 'video', 'document' => $this->raw[$this->type]['caption'] ?? null,
            default => null,
        };
    }

    /** Id do botão ou item de lista escolhido (o que você definiu no envio). */
    public function replyId(): ?string
    {
        return $this->raw['interactive']['button_reply']['id']
            ?? $this->raw['interactive']['list_reply']['id']
            ?? $this->raw['button']['payload']
            ?? null;
    }

    /** Id da mídia para baixar pela API (imagem, áudio, documento...). */
    public function mediaId(): ?string
    {
        return $this->raw[$this->type]['id'] ?? null;
    }
}
