<?php

namespace App\Services;

use RuntimeException;

class BiBusy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Outra operação do BI está em andamento nesta conta. Aguarde a conclusão e tente novamente.');
    }
}
