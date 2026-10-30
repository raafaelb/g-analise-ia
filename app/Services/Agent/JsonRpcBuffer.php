<?php

namespace App\Services\Agent;

use RuntimeException;

class JsonRpcBuffer
{
    private string $buffer = '';

    public function receive(string $chunk): array
    {
        $this->buffer .= $chunk;
        if (strlen($this->buffer) > 2_000_000) {
            throw new RuntimeException('Resposta do agente excedeu o limite do protocolo.');
        }
        $messages = [];
        while (($position = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + 1);
            if (trim($line) === '') {
                continue;
            }$decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Mensagem do agente inválida.');
            }$messages[] = $decoded;
        }

        return $messages;
    }
}
