# whatsapp-cloud-php

[![CI](https://github.com/Ph20sr/whatsapp-cloud-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Ph20sr/whatsapp-cloud-php/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4)
![license](https://img.shields.io/badge/license-MIT-blue)

Cliente PHP da **WhatsApp Cloud API**, a API oficial da Meta. Serve para confirmar agendamentos, avisar sobre cobranças, enviar boleto em PDF e responder clientes a partir do seu sistema, sem depender de API não oficial (que pode levar o número ao bloqueio).

- **Envio**: texto, **templates** com parâmetros, imagem, documento, **botões de resposta** e marcação de lida
- **Webhook**: verificação da URL no painel, **assinatura `X-Hub-Signature-256`** conferida com `hash_equals`, e mensagens e status como objetos tipados
- **Erros com significado**: `isOutside24hWindow()` (precisa de template), `isRateLimit()`, `traceId` para o suporte da Meta
- Números brasileiros normalizados (`(11) 98765-4321` → `5511987654321`). Quem começa com `+` mantém o DDI informado.
- Versão da Graph API configurável (padrão `v23.0`) e transporte HTTP plugável. Só depende de `ext-curl`.

## Instalação

```bash
composer config repositories.whatsapp-cloud-php vcs https://github.com/Ph20sr/whatsapp-cloud-php
composer require ph20sr/whatsapp-cloud-php:^1.0
```

## Enviar

```php
use Ph20sr\WhatsApp\WhatsApp;
use Ph20sr\WhatsApp\ApiException;

$wa = new WhatsApp(getenv('WA_TOKEN'), getenv('WA_PHONE_NUMBER_ID'));

$wa->sendText('(11) 98765-4321', 'Seu pedido saiu para entrega 🚚');

// Fora da janela de 24h, só template aprovado
$wa->sendTemplate('11987654321', 'cobranca_vencendo', 'pt_BR', ['Maria', 'R$ 149,90', '15/10']);

$wa->sendDocument('11987654321', 'https://seusite.com/boletos/123.pdf', 'boleto-outubro.pdf', 'Segue seu boleto');

$wa->sendButtons('11987654321', 'Confirma sua consulta amanhã às 14h?', [
    'confirmar' => 'Confirmar',
    'remarcar'  => 'Remarcar',
], 'Clínica Exemplo');

try {
    $wa->sendText($numero, $texto);
} catch (ApiException $e) {
    if ($e->isOutside24hWindow()) {
        $wa->sendTemplate($numero, 'retomar_conversa');
    }
}
```

## Receber (webhook)

```php
use Ph20sr\WhatsApp\Webhook\Webhook;

$webhook = new Webhook(getenv('WA_VERIFY_TOKEN'), getenv('WA_APP_SECRET'));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {           // verificação no painel
    $challenge = $webhook->verifyChallenge($_GET);
    http_response_code($challenge === null ? 403 : 200);
    exit($challenge ?? '');
}

$events = $webhook->handle(file_get_contents('php://input'), $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null);

foreach ($events['messages'] as $msg) {
    $msg->from;          // '5511987654321'
    $msg->contactName;   // 'Maria Souza'
    $msg->text();        // texto digitado, título do botão ou legenda da mídia
    $msg->replyId();     // 'confirmar' (id do botão que você enviou)
    $msg->mediaId();     // para baixar imagem/áudio/documento
}

foreach ($events['statuses'] as $status) {
    $status->status;     // sent | delivered | read | failed
    $status->errors;     // [['code' => 131026, 'title' => 'Message undeliverable']]
}
```

O `verifyChallenge` aceita `hub.mode` e `hub_mode`: o PHP troca os pontos por `_` em `$_GET`, um detalhe que costuma quebrar a verificação.

Há um bot completo de confirmação de horário em [`examples/webhook.php`](examples/webhook.php).

## Testes

```bash
composer install
vendor/bin/phpunit
```

Os testes usam um transporte HTTP falso e payloads no formato real da Meta. O CI roda em PHP 8.1, 8.2 e 8.3.

## Licença

MIT
