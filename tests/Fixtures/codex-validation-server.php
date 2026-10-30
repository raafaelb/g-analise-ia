#!/usr/bin/env php
<?php

$send = static function (array $message): void {
    fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
};
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $method = $message['method'] ?? null;
    if ($method === 'initialize') {
        $send(['id' => 1, 'result' => []]);
    } elseif (in_array($method, ['thread/start', 'thread/resume'], true)) {
        $send(['id' => 2, 'result' => ['thread' => ['id' => 'synthetic-validation-thread']]]);
    } elseif ($method === 'turn/start') {
        $send(['id' => 3, 'result' => []]);
        $send(['id' => 20, 'method' => 'item/tool/call', 'params' => ['tool' => 'bi_data', 'arguments' => ['operation' => 'query', 'arguments' => ['fields' => [['ref' => 'Vendas.Valor', 'agg' => 'sum']], 'limit' => 501]]]]);
    } elseif (($message['id'] ?? null) === 20) {
        $text = $message['result']['contentItems'][0]['text'] ?? '';
        $data = json_decode($text, true);
        if (($message['result']['success'] ?? null) !== false || ($data['status'] ?? '') !== 'invalid_arguments' || ! isset($data['errors']['limit'])) {
            exit(2);
        }
        $send(['id' => 21, 'method' => 'item/tool/call', 'params' => ['tool' => 'bi_data', 'arguments' => ['operation' => 'get_app', 'arguments' => (object) []]]]);
    } elseif (($message['id'] ?? null) === 21) {
        if (($message['result']['success'] ?? false) !== true) {
            exit(3);
        }
        $send(['method' => 'item/agentMessage/delta', 'params' => ['itemId' => 'validated-reply', 'delta' => 'Consulta corrigida e concluída.']]);
        $send(['method' => 'turn/completed', 'params' => ['turn' => ['status' => 'completed']]]);
    }
}
