<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedBiResource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['receipt' => 'array'];
    }
}
