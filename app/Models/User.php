<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['role' => 'editor', 'active' => true, 'local_login_enabled' => true, 'must_change_password' => false, 'access_version' => 0, 'access_revision' => 0];

    public function canAdministerUsers(): bool
    {
        return $this->active && in_array($this->role, ['owner', 'admin'], true);
    }

    public function canStartWork(): bool
    {
        return $this->active && ! $this->must_change_password;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'active' => 'boolean',
            'local_login_enabled' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'access_version' => 'integer',
            'access_revision' => 'integer',
            'password' => 'hashed',
        ];
    }
}
