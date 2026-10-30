<?php

namespace App\Services;

class ProcessEnvironment
{
    /** @return array<string, false> */
    public static function isolated(): array
    {
        $names = array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]);

        return array_fill_keys($names, false);
    }
}
