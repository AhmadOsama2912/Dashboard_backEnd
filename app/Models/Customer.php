<?php

// app/Models/Customer.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'note', 'package_id', 'logo', 'meta'
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function screens(): HasMany   { return $this->hasMany(Screen::class); }
    public function enrollmentCodes(): HasMany { return $this->hasMany(EnrollmentCode::class); }
    public function package(): BelongsTo { return $this->belongsTo(Package::class, 'package_id'); }

    public function users(): HasMany     { return $this->hasMany(User::class, 'customer_id'); }
    public function managers(): HasMany  { return $this->users()->where('role', 'manager'); }
    public function supervisors(): HasMany { return $this->users()->where('role', 'supervisor'); }
}
