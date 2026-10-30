<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'audit_events';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
