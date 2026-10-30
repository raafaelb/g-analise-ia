<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'approvals';

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
