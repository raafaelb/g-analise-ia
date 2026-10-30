<?php

namespace App\Services;

use RuntimeException;

class BiValidationFailure extends RuntimeException
{
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct('A validação do recurso falhou antes de enviar alterações ao BI. A IA pode corrigir o arquivo com os detalhes desta validação.', 8);
    }
}
