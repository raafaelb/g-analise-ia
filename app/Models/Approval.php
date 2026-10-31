<?php

namespace App\Models;

use App\Services\BiScheduleTiming;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'approvals';

    protected $appends = ['schedule_timing'];

    protected function scheduleTiming(): Attribute
    {
        return Attribute::get(function (): ?array {
            $triggers = $this->payload['revision']['schedule']['triggers'] ?? null;
            if (! is_array($triggers) || ! array_is_list($triggers)) {
                return null;
            }
            $timing = app(BiScheduleTiming::class);

            return ['frequency' => $timing->frequency($triggers), 'daily_or_more_frequent' => $timing->daily($triggers), 'triggers' => array_map(fn (mixed $trigger): string => $timing->frequency([$trigger]), array_slice($triggers, 0, 100)), 'previous_frequency' => is_array($this->payload['revision']['schedule_edit']['previous']['triggers'] ?? null) ? $timing->frequency($this->payload['revision']['schedule_edit']['previous']['triggers']) : null];
        });
    }

    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            $version = (int) (User::find($approval->user_id)?->access_version ?? 0);
            $approval->payload = [...($approval->payload ?? []), 'portal_access' => ['version' => $version, 'signature' => $approval->accessSignature($version)]];
        });
    }

    public function permitsUserAccess(User $user): bool
    {
        if ((int) $this->user_id !== $user->id) {
            return false;
        }
        $stamp = $this->payload['portal_access'] ?? null;
        if ($stamp === null) {
            return $user->access_version === 0;
        }

        return is_array($stamp) && is_int($stamp['version'] ?? null) && is_string($stamp['signature'] ?? null) && $stamp['version'] === $user->access_version && hash_equals($this->accessSignature($stamp['version']), $stamp['signature']);
    }

    private function accessSignature(int $version): string
    {
        return hash_hmac('sha256', 'approval-user-access:v1|'.$this->user_id.'|'.$this->id.'|'.$version, (string) config('app.key'));
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
