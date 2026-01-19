<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    // User has 2 Roles "Manager,Supervisor"
    use HasApiTokens, HasFactory, SoftDeletes, Notifiable;

    protected $fillable = [
        'customer_id','username','email','password','role','phone',
        'last_login_at','last_login_ip','meta'
    ];

    protected $hidden = ['password','remember_token'];

    protected $casts = [
        'password'=>'hashed',
        'last_login_at'=>'datetime',
        'meta'=>'array',
        'abilities' => 'array',
    ];

    public function customer(){ return $this->belongsTo(Customer::class); }

    // helpers
    public function isManager(): bool    { return $this->role === 'manager'; }
    public function isSupervisor(): bool { return $this->role === 'supervisor'; }

    public function hasAbility(string $ability): bool
    {
        $abilities = match ($this->role) {
            'manager' => [
                'user:screens:view_all',
                'user:playlists:manage',
                'user:content:manage',
            ],
            'supervisor' => [
                'user:screens:view_assigned',
            ],
            default => [],
        };
        return in_array($ability, $abilities);

    }
}
