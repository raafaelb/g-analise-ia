<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Artifact extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'artifacts';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
