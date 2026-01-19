<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenLicense extends Model
{
    protected $fillable = [
        'screen_id','enrollment_code_id','starts_at','expires_at','status'
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function enrollmentCode(): BelongsTo
    {
        return $this->belongsTo(EnrollmentCode::class);
    }
}
