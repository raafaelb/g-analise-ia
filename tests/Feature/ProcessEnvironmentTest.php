<?php

namespace Tests\Feature;

use App\Services\ProcessEnvironment;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProcessEnvironmentTest extends TestCase
{
    public function test_child_process_receives_only_explicit_settings_instead_of_os_and_dotenv_values(): void
    {
        $native = 'GANALISE_SYNTHETIC_NATIVE_SECRET';
        $dotenv = 'GANALISE_SYNTHETIC_DOTENV_SECRET';
        $originalNative = getenv($native);
        $originalDotenv = $_ENV[$dotenv] ?? null;
        putenv($native.'=synthetic-native-only');
        $_ENV[$dotenv] = 'synthetic-dotenv-only';
        try {
            $process = new Process([PHP_BINARY, '-r', 'echo json_encode([getenv("GANALISE_SYNTHETIC_NATIVE_SECRET"), getenv("GANALISE_SYNTHETIC_DOTENV_SECRET"), getenv("GANALISE_SYNTHETIC_ALLOWED")]);'], null, [...ProcessEnvironment::isolated(), 'GANALISE_SYNTHETIC_ALLOWED' => 'explicit-value']);
            $process->mustRun();
            $this->assertSame([false, false, 'explicit-value'], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            putenv($originalNative === false ? $native : $native.'='.$originalNative);
            if ($originalDotenv === null) {
                unset($_ENV[$dotenv]);
            } else {
                $_ENV[$dotenv] = $originalDotenv;
            }
        }
    }
}
