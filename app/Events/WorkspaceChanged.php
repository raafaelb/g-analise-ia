<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class WorkspaceChanged implements ShouldBroadcastNow
{
    public function __construct(public int $userId, public ?string $runId = null) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'workspace.changed';
    }

    public function broadcastWith(): array
    {
        return ['run_id' => $this->runId];
    }
}
