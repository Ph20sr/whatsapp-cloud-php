<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Tests;

use Ph20sr\WhatsApp\Webhook\InvalidSignatureException;
use Ph20sr\WhatsApp\Webhook\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'app-secret-123';
    private Webhook $webhook;

    protected function setUp(): void
    {
        $this->webhook = new Webhook('meu-verify-token', self::SECRET);
    }

    private function payload(array $value): string
    {
        return json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'WABA_ID', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '5511999990000', 'phone_number_id' => '1234567890'],
                ...$value,
            ]]]]],
        ]);
    }

    private function sign(string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
    }

    public function testVerifyChallenge(): void
    {
        // Como o PHP entrega em $_GET (pontos viram "_")
        $this->assertSame('987', $this->webhook->verifyChallenge(['hub_mode' => 'subscribe', 'hub_verify_token' => 'meu-verify-token', 'hub_challenge' => '987']));
        // Como vem na URL bruta
        $this->assertSame('987', $this->webhook->verifyChallenge(['hub.mode' => 'subscribe', 'hub.verify_token' => 'meu-verify-token', 'hub.challenge' => '987']));
        $this->assertNull($this->webhook->verifyChallenge(['hub_mode' => 'subscribe', 'hub_verify_token' => 'errado', 'hub_challenge' => '987']));
        $this->assertNull($this->webhook->verifyChallenge([]));
    }

    public function testSignature(): void
    {
        $body = $this->payload([]);
        $this->assertTrue($this->webhook->verifySignature($body, $this->sign($body)));
        $this->assertFalse($this->webhook->verifySignature($body . ' ', $this->sign($body)), 'corpo alterado');
        $this->assertFalse($this->webhook->verifySignature($body, null));
        $this->assertFalse($this->webhook->verifySignature($body, 'md5=abc'));

        $this->expectException(InvalidSignatureException::class);
        $this->webhook->handle($body, 'sha256=00');
    }

    public function testParsesTextAndButtonReplies(): void
    {
        $body = $this->payload([
            'contacts' => [['profile' => ['name' => 'Maria Souza'], 'wa_id' => '5511987654321']],
            'messages' => [
                ['from' => '5511987654321', 'id' => 'wamid.1', 'timestamp' => '1791295200', 'type' => 'text', 'text' => ['body' => 'Quero um orçamento']],
                ['from' => '5511987654321', 'id' => 'wamid.2', 'timestamp' => '1791295260', 'type' => 'interactive',
                    'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'sim', 'title' => 'Confirmar']]],
                ['from' => '5511987654321', 'id' => 'wamid.3', 'timestamp' => '1791295300', 'type' => 'image',
                    'image' => ['id' => 'MEDIA_1', 'mime_type' => 'image/jpeg', 'caption' => 'comprovante']],
            ],
        ]);

        ['messages' => $messages, 'statuses' => $statuses] = $this->webhook->handle($body, $this->sign($body));

        $this->assertCount(3, $messages);
        $this->assertCount(0, $statuses);
        $this->assertSame('Maria Souza', $messages[0]->contactName);
        $this->assertSame('Quero um orçamento', $messages[0]->text());
        $this->assertSame('1234567890', $messages[0]->phoneNumberId);
        $this->assertSame('2026-10-06T14:00:00+00:00', $messages[0]->timestamp->format(DATE_ATOM));
        $this->assertSame('sim', $messages[1]->replyId());
        $this->assertSame('Confirmar', $messages[1]->text());
        $this->assertSame('MEDIA_1', $messages[2]->mediaId());
        $this->assertSame('comprovante', $messages[2]->text());
    }

    public function testParsesStatusesIncludingFailures(): void
    {
        $body = $this->payload(['statuses' => [
            ['id' => 'wamid.A', 'status' => 'read', 'timestamp' => '1791295200', 'recipient_id' => '5511987654321'],
            ['id' => 'wamid.B', 'status' => 'failed', 'timestamp' => '1791295200', 'recipient_id' => '5511987654321',
                'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]],
        ]]);

        $statuses = $this->webhook->parse($body)['statuses'];
        $this->assertSame('read', $statuses[0]->status);
        $this->assertFalse($statuses[0]->failed());
        $this->assertTrue($statuses[1]->failed());
        $this->assertSame([['code' => 131026, 'title' => 'Message undeliverable']], $statuses[1]->errors);
    }

    public function testIgnoresOtherObjectsAndFields(): void
    {
        $this->assertSame(['messages' => [], 'statuses' => []], $this->webhook->parse('{"object":"page"}'));
        $this->assertSame(['messages' => [], 'statuses' => []], $this->webhook->parse('nao é json'));
    }
}
