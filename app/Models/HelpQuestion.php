<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpQuestion extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $attributes = ['status' => 'queued'];

    protected $hidden = ['connection_generation', 'request_hash', 'access_version'];

    public function scopeInterrupted(Builder $query): void
    {
        $query->where(function (Builder $states): void {
            $states->where(fn (Builder $running) => $running->where('status', 'running')->where(function (Builder $heartbeat): void {
                $heartbeat->where('heartbeat_at', '<', now()->subMinutes(4))->orWhere(fn (Builder $missing) => $missing->whereNull('heartbeat_at')->where('created_at', '<', now()->subMinutes(4)));
            }))->orWhere(fn (Builder $queued) => $queued->where('status', 'queued')->where('created_at', '<', now()->subMinutes(10)));
        });
    }

    protected function casts(): array
    {
        return ['access_version' => 'integer', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
