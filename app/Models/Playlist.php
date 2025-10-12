<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Playlist extends Model
{
    protected $fillable = [
        'customer_id','name','is_default','published_at','content_version','meta'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'meta'         => 'array',
    ];

    public function customer(){ return $this->belongsTo(Customer::class); }
    public function items(){ return $this->hasMany(PlaylistItem::class)->orderBy('sort'); }
    public function screens(){ return $this->hasMany(Screen::class); }

    public function refreshVersion(): string
    {
        $payload = $this->items()->get(['type','src','duration','checksum','sort'])->toArray();
        $this->content_version = 'sha256:'.hash('sha256', json_encode($payload));
        $this->save();
        return $this->content_version;
    }
}
