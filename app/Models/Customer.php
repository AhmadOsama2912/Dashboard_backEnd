<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'package_id', 'notes',
    ];

    /* ---------- Relationships ---------- */

    /** Screens that belong to this customer */
    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class, 'customer_id');
    }

    /** Enrollment (claim) codes issued for this customer */
    public function enrollmentCodes(): HasMany
    {
        return $this->hasMany(EnrollmentCode::class, 'customer_id');
    }

    /** Users (manager/supervisor) for this customer */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'customer_id');
    }

    /** Selected package (if stored on the customer row) */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    /**
     * Company playlists (ONLY if you have playlists.customer_id)
     * Safe to keep even if you don’t use it; controller guards before calling.
     */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class, 'customer_id');
    }

    /** Convenience: online screens (heartbeat within N minutes) */
    public function onlineScreens(): HasMany
    {
        $minutes = (int) config('screens.online_grace_minutes', 5);

        return $this->hasMany(Screen::class, 'customer_id')
            ->where('last_check_in_at', '>=', now()->subMinutes($minutes));
    }
}
