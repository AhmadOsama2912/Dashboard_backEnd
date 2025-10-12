<?php
// app/Http/Controllers/Admin/AdminScreenContentController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Screen;
use Illuminate\Http\Request;

class AdminScreenContentController extends Controller
{
    public function setPlaylist(Request $request, Screen $screen)
    {
        $data = $request->validate([
            'playlist_id' => ['nullable','integer','exists:playlists,id'],
        ]);

        if (!empty($data['playlist_id'])) {
            $pl = Playlist::findOrFail($data['playlist_id']);
            // Safety: enforce tenant boundary
            if ($pl->customer_id !== $screen->customer_id) {
                return response()->json(['message'=>'Playlist/customer mismatch'], 422);
            }
            $screen->playlist_id = $pl->id;
        } else {
            $screen->playlist_id = null; // follow company default
        }
        $screen->save();

        event(new \App\Events\ScreenConfigUpdated($screen->customer_id, (int)$screen->id, $screen->playlist?->content_version ?? ''));
        return response()->json(['message'=>'Screen playlist updated','screen_id'=>$screen->id,'playlist_id'=>$screen->playlist_id]);
    }

    public function refreshScreen(Screen $screen)
    {
        event(new \App\Events\ScreenConfigUpdated($screen->customer_id, (int)$screen->id, ''));
        return response()->json(['message'=>'Refresh signal sent']);
    }
}
