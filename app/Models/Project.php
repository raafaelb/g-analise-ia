<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'projects';

    protected function casts(): array
    {
        return ['is_example' => 'boolean', 'preview_changed_at' => 'datetime'];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }
}
