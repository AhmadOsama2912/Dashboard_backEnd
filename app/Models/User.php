<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    // Roles: manager, supervisor
    use HasApiTokens, HasFactory, SoftDeletes, Notifiable;

    protected $fillable = [
        'customer_id',
        'username',
        'email',
        'password',
        'role',
        'phone',
        'last_login_at',
        'last_login_ip',
        'meta',
        'abilities',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password'      => 'hashed',
        'last_login_at' => 'datetime',
        'meta'          => 'array',
        'abilities'     => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // helpers
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    /**
     * Ability check:
     * - manager: always true (full access)
     * - supervisor: controlled by DB column `abilities` (JSON array)
     */
    public function hasAbility(string $ability): bool
    {
        if ($this->isManager()) {
            return true;
        }

        $abilities = $this->abilities ?? [];
        return in_array($ability, $abilities, true);
    }

    /**
     * Token abilities for Sanctum (use in login).
     * Managers get '*' to pass tokenCan(...) checks everywhere.
     */
    public function tokenAbilities(): array
    {
        return $this->isManager() ? ['*'] : ($this->abilities ?? []);
    }
}
