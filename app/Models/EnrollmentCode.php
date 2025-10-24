<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Schema;

class EnrollmentCode extends Model
{
    use HasFactory;

    protected $table = 'enrollment_codes';

    protected $fillable = [
        'customer_id',
        'code',
        'expires_at',   // optional
        'revoked_at',   // optional
        'max_uses',     // optional
        'used_count',   // optional
        'meta',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta'       => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Scope: valid codes only (column-aware).
     * It will only add filters for columns that actually exist in your table.
     */
    public function scopeValid($q)
    {
        // not revoked
        if (Schema::hasColumn($this->getTable(), 'revoked_at')) {
            $q->whereNull('revoked_at');
        }

        // not expired (or no expiry)
        if (Schema::hasColumn($this->getTable(), 'expires_at')) {
            $q->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
        }

        // not exhausted (or unlimited uses)
        $hasMax = Schema::hasColumn($this->getTable(), 'max_uses');
        $hasUsed = Schema::hasColumn($this->getTable(), 'used_count');
        if ($hasMax && $hasUsed) {
            $q->where(function ($q) {
                $q->whereNull('max_uses')
                  ->orWhereColumn('used_count', '<', 'max_uses');
            });
        }

        return $q;
    }

    /** Optional helpers if you add usage limits later */
    public function markUsed(): void
    {
        if (Schema::hasColumn($this->getTable(), 'used_count')) {
            $this->increment('used_count');
        }
    }
}
