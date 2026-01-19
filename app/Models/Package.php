<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name','screens_limit','managers_limit','supervisors_limit',
        'branches_limit','price','support_description',
    ];

    public function customers() { return $this->hasMany(Customer::class); }
}
