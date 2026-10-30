<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'connections';

    public ?int $operationAccessVersion = null;

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'scopes' => 'array', 'metadata' => 'array', 'expires_at' => 'datetime'];
    }

    protected $hidden = ['credentials'];
}
