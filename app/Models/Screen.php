<?php

// app/Models/Screen.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Screen extends Model
{
    protected $fillable = [
        'customer_id','serial_number','access_scope','assigned_user_id',
        'device_model','os_version','app_version',
        'activated_at','last_check_in_at','api_token','meta',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'last_check_in_at' => 'datetime',
        'meta' => 'array',
    ];


    public function playlist() { return $this->belongsTo(\App\Models\Playlist::class); }

    public function customer(){ return $this->belongsTo(Customer::class); }
    public function assignee(){ return $this->belongsTo(User::class, 'assigned_user_id'); }

    // status: not_activated / active / not_active
    public function getStatusAttribute(): string {
        if (!$this->activated_at) return 'not_activated';
        if ($this->last_check_in_at && $this->last_check_in_at->gt(now()->subMinutes(5))) return 'active';
        return 'not_active';
    }

    public function scopeOnline($q, $minutes = null) {
    $m = (int) ($minutes ?? config('screens.online_grace_minutes', 5));
    return $q->where('last_check_in_at', '>=', now()->subMinutes($m));
    }
    
    public function scopeOffline($q, $minutes = null) {
        $m = (int) ($minutes ?? config('screens.online_grace_minutes', 5));
        return $q->where(function($qq) use ($m) {
            $qq->whereNull('last_check_in_at')
            ->orWhere('last_check_in_at', '<', now()->subMinutes($m));
        });
    }

    public function licenses()
    {
        return $this->hasMany(\App\Models\ScreenLicense::class);
    }
    public function activeLicenses()
    {
        return $this->hasMany(\App\Models\ScreenLicense::class)
            ->where('status', 'active')
            ->where('expires_at', '>=', now());
    }

    public function latestLicense()
    {
        // Laravel 9+: return $this->hasOne(ScreenLicense::class)->latestOfMany('expires_at');
        return $this->hasOne(\App\Models\ScreenLicense::class)->latest('expires_at');
    }

    


}
