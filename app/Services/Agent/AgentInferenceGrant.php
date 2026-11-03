<?php

namespace App\Services\Agent;

use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class AgentInferenceGrant implements JsonSerializable
{
    private SensitiveParameterValue $secret;

    public function __construct(#[SensitiveParameter] string $token, public string $runId, public int $expiresAt)
    {
        $this->secret = new SensitiveParameterValue($token);
    }

    public function authorization(): string
    {
        return $this->secret->getValue();
    }

    public function jsonSerialize(): array
    {
        return ['run_id' => $this->runId, 'expires_at' => $this->expiresAt];
    }

    public function __debugInfo(): array
    {
        return [...$this->jsonSerialize(), 'token' => '[protected]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Temporary inference grants cannot be serialized into persistent storage.');
    }
}
