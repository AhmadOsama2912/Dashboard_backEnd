<?php
// app/Models/EnrollmentCode.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EnrollmentCode extends Model
{
    protected $fillable = ['customer_id','code','max_uses','used_count','expires_at'];
    protected $casts = ['expires_at'=>'datetime'];
    public function customer(){ return $this->belongsTo(Customer::class); }
}
