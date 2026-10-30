<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RefreshSchedule extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'refresh_schedules';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'metadata' => 'array', 'last_success_at' => 'datetime'];
    }
}
