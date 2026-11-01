<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'agent_runs';

    protected function casts(): array
    {
        return ['attachment_ids' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'heartbeat_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
