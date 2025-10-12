<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaylistItem extends Model
{
    protected $fillable = ['playlist_id','type','src','duration','sort','checksum','meta'];
    protected $casts = ['meta' => 'array'];

    public function playlist(){ return $this->belongsTo(Playlist::class); }
}
