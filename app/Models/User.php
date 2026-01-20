<?php

namespace App\Models;

use App\Support\UserAbilities;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes, Notifiable;

    protected $fillable = [
        'customer_id','username','email','password','role','phone',
        'last_login_at','last_login_ip','meta','abilities'
    ];

    protected $hidden = ['password','remember_token'];

    protected $casts = [
        'password'      => 'hashed',
        'last_login_at' => 'datetime',
        'meta'          => 'array',
        'abilities'     => 'array',
    ];

    public function customer(){ return $this->belongsTo(Customer::class); }

    public function isManager(): bool    { return $this->role === 'manager'; }
    public function isSupervisor(): bool { return $this->role === 'supervisor'; }

    /**
     * Backward compatibility:
     * Your OLD DB abilities -> Canonical route/token abilities
     */
    private const LEGACY_MAP = [
        'devices:assign'  => UserAbilities::SCREENS_ASSIGN,
        'bulk:send'       => UserAbilities::SCREENS_BROADCAST,
        'content:add'     => UserAbilities::PLAYLIST_WRITE,
        'content:change'  => UserAbilities::PLAYLIST_WRITE,
    ];

    private function norm(string $ability): string
    {
        return strtolower(trim($ability));
    }

    public function roleDefaultAbilities(): array
    {
        return match ($this->role) {
            'manager'    => UserAbilities::managerDefaults(),
            'supervisor' => UserAbilities::supervisorDefaults(),
            default      => [],
        };
    }

    /**
     * Final abilities = role defaults + DB abilities (normalized + mapped)
     */
    public function effectiveAbilities(): array
    {
        $out = [];

        $push = function (string $a) use (&$out) {
            $a = $this->norm($a);
            if ($a !== '') $out[$a] = true;
        };

        // role defaults
        foreach ($this->roleDefaultAbilities() as $a) {
            $push($a);
        }

        // DB abilities
        $db = $this->abilities ?? [];
        $db = is_array($db) ? $db : [];

        foreach ($db as $a) {
            $a = $this->norm((string) $a);
            if ($a === '') continue;

            $push($a);

            if (isset(self::LEGACY_MAP[$a])) {
                $push(self::LEGACY_MAP[$a]);
            }
        }

        return array_keys($out);
    }

    public function hasAbility(string $ability): bool
    {
        $ability = $this->norm($ability);
        return in_array($ability, $this->effectiveAbilities(), true);
    }
}
