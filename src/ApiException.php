<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp;

use Ph20sr\WhatsApp\Http\Response;

/** Erro devolvido pela Graph API ({"error": {"code", "message", ...}}). */
final class ApiException extends WhatsAppException
{
    /** Fora da janela de 24h: só é permitido enviar template. */
    public const OUTSIDE_24H_WINDOW = 131047;
    /** Número não tem WhatsApp ou não pode receber a mensagem. */
    public const UNDELIVERABLE = 131026;
    /** Limite de envio da conta atingido. */
    public const RATE_LIMIT = 130429;
    /** Token de acesso expirado ou inválido. */
    public const INVALID_TOKEN = 190;

    public function __construct(
        string $message,
        public readonly int $status,
        public readonly int $apiCode,
        public readonly ?int $subcode = null,
        public readonly ?string $traceId = null,
        public readonly ?Response $response = null,
    ) {
        parent::__construct($message, $apiCode);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json()['error'] ?? [];
        $details = $error['error_data']['details'] ?? null;
        $message = (string) ($error['message'] ?? "Graph API respondeu HTTP {$response->status}");
        if (is_string($details) && $details !== '') {
            $message .= " ({$details})";
        }

        return new self(
            $message,
            $response->status,
            (int) ($error['code'] ?? 0),
            isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null,
            $response,
        );
    }

    public function isOutside24hWindow(): bool
    {
        return $this->apiCode === self::OUTSIDE_24H_WINDOW;
    }

    public function isRateLimit(): bool
    {
        return $this->apiCode === self::RATE_LIMIT || $this->status === 429;
    }
}
