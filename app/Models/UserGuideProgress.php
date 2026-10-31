<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserGuideProgress extends Model
{
    use HasFactory;

    protected $table = 'user_guide_progress';

    protected $guarded = [];

    protected $attributes = ['replay_count' => 0];

    protected function casts(): array
    {
        return ['first_started_at' => 'datetime', 'last_started_at' => 'datetime', 'first_seen_at' => 'datetime', 'completed_at' => 'datetime', 'dismissed_at' => 'datetime', 'last_finished_at' => 'datetime', 'replay_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
