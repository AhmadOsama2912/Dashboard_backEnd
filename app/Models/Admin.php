<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes, Notifiable;

    protected $table = 'admins';

    protected $fillable = [
        'name','username','email','password','phone','avatar_path',
        'last_login_at','last_login_ip','meta','email_verified_at','is_super_admin',
    ];

    protected $hidden = ['password','remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'meta'              => 'array',
        'password'          => 'hashed',
        'is_super_admin'    => 'boolean',
    ];
}
