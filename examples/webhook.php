<?php

/**
 * Endpoint de webhook da WhatsApp Cloud API: um bot de confirmação de horário.
 * No painel da Meta: WhatsApp > Configuração > Webhook
 *   URL: https://seu-dominio/webhook.php   Token: o mesmo de WA_VERIFY_TOKEN
 *   Campo assinado: messages
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ph20sr\WhatsApp\Webhook\InvalidSignatureException;
use Ph20sr\WhatsApp\Webhook\Webhook;
use Ph20sr\WhatsApp\WhatsApp;

$webhook = new Webhook((string) getenv('WA_VERIFY_TOKEN'), (string) getenv('WA_APP_SECRET'));

// 1) Verificação da URL no painel
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challenge = $webhook->verifyChallenge($_GET);
    http_response_code($challenge === null ? 403 : 200);
    echo $challenge ?? 'forbidden';
    exit;
}

// 2) Eventos: valide a assinatura com o corpo BRUTO
$raw = (string) file_get_contents('php://input');
try {
    $events = $webhook->handle($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null);
} catch (InvalidSignatureException) {
    http_response_code(401);
    exit;
}

// Responda 200 rápido; a Meta reenvia se demorar ou falhar.
// Em produção, enfileire o processamento (ex.: webhook-relay) em vez de fazê-lo aqui.
http_response_code(200);

$wa = new WhatsApp((string) getenv('WA_TOKEN'), (string) getenv('WA_PHONE_NUMBER_ID'));

foreach ($events['messages'] as $message) {
    $wa->markAsRead($message->id);

    match ($message->replyId()) {
        'confirmar' => $wa->sendText($message->from, 'Perfeito, horário confirmado! Até amanhã. ✅'),
        'remarcar' => $wa->sendText($message->from, 'Sem problema! Qual dia e horário ficam melhor para você?'),
        default => $wa->sendButtons(
            $message->from,
            sprintf('Olá%s! Confirma sua consulta amanhã às 14h?', $message->contactName ? ", {$message->contactName}" : ''),
            ['confirmar' => 'Confirmar', 'remarcar' => 'Remarcar'],
            'Clínica Exemplo',
        ),
    };
}

foreach ($events['statuses'] as $status) {
    if ($status->failed()) {
        error_log(sprintf('WhatsApp: mensagem %s falhou: %s', $status->messageId, json_encode($status->errors)));
    }
}
