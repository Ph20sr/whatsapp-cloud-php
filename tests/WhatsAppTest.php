<?php

declare(strict_types=1);

namespace Ph20sr\WhatsApp\Tests;

use Ph20sr\WhatsApp\ApiException;
use Ph20sr\WhatsApp\WhatsApp;
use PHPUnit\Framework\TestCase;

final class WhatsAppTest extends TestCase
{
    private FakeHttpClient $http;
    private WhatsApp $wa;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->wa = new WhatsApp('EAAG-token', '1234567890', $this->http);
    }

    private function ok(): void
    {
        $this->http->push(200, ['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.ABC']]]);
    }

    public function testNormalizesRecipients(): void
    {
        $this->assertSame('5511987654321', WhatsApp::normalizeRecipient('(11) 98765-4321'));
        $this->assertSame('5511987654321', WhatsApp::normalizeRecipient('+55 11 98765-4321'));
        $this->assertSame('14155552671', WhatsApp::normalizeRecipient('+1 415 555 2671'));
        $this->expectException(\InvalidArgumentException::class);
        WhatsApp::normalizeRecipient('123');
    }

    public function testSendsTextWithAuthAndEndpoint(): void
    {
        $this->ok();
        $id = $this->wa->sendText('11987654321', 'Seu pedido saiu para entrega 🚚');

        $request = $this->http->requests[0];
        $this->assertSame('wamid.ABC', $id);
        $this->assertSame('https://graph.facebook.com/v23.0/1234567890/messages', $request['url']);
        $this->assertSame('Bearer EAAG-token', $request['headers']['Authorization']);
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '5511987654321',
            'type' => 'text',
            'text' => ['body' => 'Seu pedido saiu para entrega 🚚', 'preview_url' => false],
        ], $this->http->lastBody());
    }

    public function testSendsTemplateWithBodyParameters(): void
    {
        $this->ok();
        $this->wa->sendTemplate('11987654321', 'cobranca_vencendo', 'pt_BR', ['Maria', 'R$ 149,90', 15]);

        $template = $this->http->lastBody()['template'];
        $this->assertSame('cobranca_vencendo', $template['name']);
        $this->assertSame(['code' => 'pt_BR'], $template['language']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Maria'],
            ['type' => 'text', 'text' => 'R$ 149,90'],
            ['type' => 'text', 'text' => '15'],
        ], $template['components'][0]['parameters']);
    }

    public function testTemplateWithoutParamsHasNoComponents(): void
    {
        $this->ok();
        $this->wa->sendTemplate('11987654321', 'hello_world', 'en_US');
        $this->assertArrayNotHasKeyCompat('components', $this->http->lastBody()['template']);
    }

    public function testSendsButtonsAndValidatesLimits(): void
    {
        $this->ok();
        $this->wa->sendButtons('11987654321', 'Confirma o horário de amanhã às 14h?', ['sim' => 'Confirmar', 'nao' => 'Remarcar'], 'Clínica Sorriso');

        $interactive = $this->http->lastBody()['interactive'];
        $this->assertSame('button', $interactive['type']);
        $this->assertSame(['type' => 'reply', 'reply' => ['id' => 'sim', 'title' => 'Confirmar']], $interactive['action']['buttons'][0]);
        $this->assertSame(['text' => 'Clínica Sorriso'], $interactive['footer']);

        try {
            $this->wa->sendButtons('11987654321', 'x', ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']);
            $this->fail('Mais de 3 botões deveria falhar');
        } catch (\InvalidArgumentException) {
            $this->assertCount(1, $this->http->requests);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->wa->sendButtons('11987654321', 'x', ['a' => 'Um título longo demais aqui']);
    }

    public function testSendsMediaAndMarksAsRead(): void
    {
        $this->ok();
        $this->wa->sendDocument('11987654321', 'https://exemplo.com/boleto.pdf', 'boleto-outubro.pdf', 'Seu boleto');
        $this->assertSame(
            ['link' => 'https://exemplo.com/boleto.pdf', 'filename' => 'boleto-outubro.pdf', 'caption' => 'Seu boleto'],
            $this->http->lastBody()['document'],
        );

        $this->ok();
        $this->wa->sendImage('11987654321', 'https://exemplo.com/cardapio.jpg');
        $this->assertSame(['link' => 'https://exemplo.com/cardapio.jpg'], $this->http->lastBody()['image']);

        $this->http->push(200, ['success' => true]);
        $this->wa->markAsRead('wamid.XYZ');
        $this->assertSame(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.XYZ'], $this->http->lastBody());
    }

    public function testMapsGraphApiErrors(): void
    {
        $this->http->push(400, ['error' => [
            'message' => 'Re-engagement message', 'type' => 'OAuthException', 'code' => 131047,
            'error_data' => ['details' => 'More than 24 hours have passed'], 'fbtrace_id' => 'Abc123',
        ]]);

        try {
            $this->wa->sendText('11987654321', 'oi');
            $this->fail('Deveria lançar ApiException');
        } catch (ApiException $e) {
            $this->assertTrue($e->isOutside24hWindow());
            $this->assertFalse($e->isRateLimit());
            $this->assertSame(400, $e->status);
            $this->assertSame('Abc123', $e->traceId);
            $this->assertStringContainsString('24 hours', $e->getMessage());
        }
    }

    public function testRejectsInvalidConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WhatsApp('token', 'abc', $this->http);
    }

    private function assertArrayNotHasKeyCompat(string $key, array $array): void
    {
        $this->assertFalse(array_key_exists($key, $array), "Chave inesperada: {$key}");
    }
}
