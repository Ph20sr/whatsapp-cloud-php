<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp;

use Ph20sr\WhatsApp\Http\CurlHttpClient;
use Ph20sr\WhatsApp\Http\HttpClient;

/**
 * Cliente da WhatsApp Cloud API.
 *
 *     $wa = new WhatsApp(getenv('WA_TOKEN'), getenv('WA_PHONE_NUMBER_ID'));
 *     $wa->sendText('+55 11 98765-4321', 'Seu pedido saiu para entrega!');
 */
final class WhatsApp
{
    public const DEFAULT_VERSION = 'v23.0';
    private const BASE_URL = 'https://graph.facebook.com';
    private const MAX_BUTTONS = 3;
    private const MAX_BUTTON_TITLE = 20;

    private readonly HttpClient $http;

    public function __construct(
        #[\SensitiveParameter] private readonly string $accessToken,
        private readonly string $phoneNumberId,
        ?HttpClient $http = null,
        private readonly string $apiVersion = self::DEFAULT_VERSION,
    ) {
        if (trim($accessToken) === '' || !ctype_digit($phoneNumberId)) {
            throw new \InvalidArgumentException('Informe o token de acesso e o Phone Number ID (numérico)');
        }
        $this->http = $http ?? new CurlHttpClient();
    }

    /**
     * Normaliza o destinatário para o formato da API (só dígitos, com DDI).
     * Sem "+" e com 10 ou 11 dígitos, assume número brasileiro e acrescenta 55;
     * com "+", o DDI já veio no número e é mantido como está.
     */
    public static function normalizeRecipient(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $hasCountryCode = str_starts_with(ltrim($phone), '+');
        if (!$hasCountryCode && (strlen($digits) === 10 || strlen($digits) === 11)) {
            $digits = '55' . $digits;
        }
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw new \InvalidArgumentException("Telefone inválido: {$phone}");
        }
        return $digits;
    }

    /** @return string id da mensagem (wamid...) */
    public function sendText(string $to, string $body, bool $previewUrl = false): string
    {
        if (trim($body) === '' || mb_strlen($body) > 4096) {
            throw new \InvalidArgumentException('O texto deve ter de 1 a 4096 caracteres');
        }
        return $this->send($to, 'text', ['body' => $body, 'preview_url' => $previewUrl]);
    }

    /**
     * Envia um template aprovado: a única forma de iniciar conversa fora da
     * janela de 24h após a última mensagem do cliente.
     *
     * @param list<string> $bodyParams valores de {{1}}, {{2}}... do corpo
     * @param list<array<string, mixed>> $extraComponents header, botões etc. no formato da API
     */
    public function sendTemplate(string $to, string $name, string $language = 'pt_BR', array $bodyParams = [], array $extraComponents = []): string
    {
        $components = $extraComponents;
        if ($bodyParams !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(static fn ($v): array => ['type' => 'text', 'text' => (string) $v], array_values($bodyParams)),
            ];
        }
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($components !== []) {
            $template['components'] = $components;
        }
        return $this->send($to, 'template', $template);
    }

    public function sendImage(string $to, string $url, ?string $caption = null): string
    {
        return $this->send($to, 'image', array_filter(['link' => $url, 'caption' => $caption], static fn ($v) => $v !== null));
    }

    public function sendDocument(string $to, string $url, string $filename, ?string $caption = null): string
    {
        return $this->send($to, 'document', array_filter(
            ['link' => $url, 'filename' => $filename, 'caption' => $caption],
            static fn ($v) => $v !== null,
        ));
    }

    /**
     * Mensagem com até 3 botões de resposta rápida.
     *
     * @param array<string, string> $buttons id => título (até 20 caracteres)
     */
    public function sendButtons(string $to, string $body, array $buttons, ?string $footer = null): string
    {
        if ($buttons === [] || count($buttons) > self::MAX_BUTTONS) {
            throw new \InvalidArgumentException('Use de 1 a 3 botões');
        }
        $list = [];
        foreach ($buttons as $id => $title) {
            if (mb_strlen($title) > self::MAX_BUTTON_TITLE) {
                throw new \InvalidArgumentException("Título do botão excede 20 caracteres: {$title}");
            }
            $list[] = ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => $title]];
        }
        $interactive = ['type' => 'button', 'body' => ['text' => $body], 'action' => ['buttons' => $list]];
        if ($footer !== null) {
            $interactive['footer'] = ['text' => $footer];
        }
        return $this->send($to, 'interactive', $interactive);
    }

    /** Marca como lida (os dois tiques azuis para o cliente). */
    public function markAsRead(string $messageId): void
    {
        $this->post([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function send(string $to, string $type, array $payload): string
    {
        $json = $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => self::normalizeRecipient($to),
            'type' => $type,
            $type => $payload,
        ]);
        return (string) ($json['messages'][0]['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        $url = sprintf('%s/%s/%s/messages', self::BASE_URL, $this->apiVersion, $this->phoneNumberId);
        $response = $this->http->send('POST', $url, [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!$response->isSuccessful()) {
            throw ApiException::fromResponse($response);
        }
        return $response->json();
    }
}
