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

    public function customer(){ return $this->belongsTo(Customer::class); }
    public function assignee(){ return $this->belongsTo(User::class, 'assigned_user_id'); }

    // status: not_activated / active / not_active
    public function getStatusAttribute(): string {
        if (!$this->activated_at) return 'not_activated';
        if ($this->last_check_in_at && $this->last_check_in_at->gt(now()->subMinutes(5))) return 'active';
        return 'not_active';
    }
}
