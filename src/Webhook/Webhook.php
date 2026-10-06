<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Webhook;

/**
 * Recebimento de webhooks da WhatsApp Cloud API.
 *
 * GET  → verificação da URL no painel da Meta (hub.challenge)
 * POST → eventos, assinados com X-Hub-Signature-256 (HMAC-SHA256 do corpo
 *        bruto com o App Secret)
 */
final class Webhook
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $verifyToken,
        #[\SensitiveParameter] private readonly string $appSecret,
    ) {
    }

    /**
     * Responde à verificação do painel. Devolve o challenge a ecoar, ou null
     * se o token não confere. Aceita tanto "hub.mode" quanto "hub_mode",
     * porque o PHP troca pontos por "_" em $_GET.
     *
     * @param array<string, mixed> $query
     */
    public function verifyChallenge(array $query): ?string
    {
        $get = static fn (string $key) => $query["hub.{$key}"] ?? $query["hub_{$key}"] ?? null;
        if ($get('mode') !== 'subscribe' || !is_string($get('verify_token')) || !is_string($get('challenge'))) {
            return null;
        }
        return hash_equals($this->verifyToken, $get('verify_token')) ? $get('challenge') : null;
    }

    /** Confere a assinatura do corpo bruto (nunca use o JSON decodificado). */
    public function verifySignature(string $rawBody, ?string $signatureHeader): bool
    {
        if ($signatureHeader === null || !str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->appSecret);
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Extrai mensagens recebidas e atualizações de status de um payload.
     *
     * @return array{messages: list<IncomingMessage>, statuses: list<StatusUpdate>}
     */
    public function parse(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        $result = ['messages' => [], 'statuses' => []];
        if (!is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return $result;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }
                $value = $change['value'] ?? [];
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $names = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    $names[(string) ($contact['wa_id'] ?? '')] = $contact['profile']['name'] ?? null;
                }

                foreach ($value['messages'] ?? [] as $m) {
                    $from = (string) ($m['from'] ?? '');
                    $result['messages'][] = new IncomingMessage(
                        (string) ($m['id'] ?? ''),
                        $from,
                        $names[$from] ?? null,
                        (string) ($m['type'] ?? 'unknown'),
                        self::time($m['timestamp'] ?? null),
                        $phoneNumberId,
                        $m,
                    );
                }

                foreach ($value['statuses'] ?? [] as $s) {
                    $result['statuses'][] = new StatusUpdate(
                        (string) ($s['id'] ?? ''),
                        (string) ($s['status'] ?? ''),
                        (string) ($s['recipient_id'] ?? ''),
                        self::time($s['timestamp'] ?? null),
                        array_map(
                            static fn (array $e): array => ['code' => (int) ($e['code'] ?? 0), 'title' => (string) ($e['title'] ?? '')],
                            array_values(array_filter($s['errors'] ?? [], 'is_array')),
                        ),
                    );
                }
            }
        }
        return $result;
    }

    /**
     * Valida a assinatura e interpreta. Lança InvalidSignatureException se
     * a requisição não veio da Meta.
     *
     * @return array{messages: list<IncomingMessage>, statuses: list<StatusUpdate>}
     */
    public function handle(string $rawBody, ?string $signatureHeader): array
    {
        if (!$this->verifySignature($rawBody, $signatureHeader)) {
            throw new InvalidSignatureException('Assinatura X-Hub-Signature-256 inválida');
        }
        return $this->parse($rawBody);
    }

    private static function time(mixed $unix): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . (int) $unix))->setTimezone(new \DateTimeZone('UTC'));
    }
}
