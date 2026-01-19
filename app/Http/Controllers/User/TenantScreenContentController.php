<?php
// app/Http/Controllers/User/TenantScreenContentController.php
namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Screen;
use Illuminate\Http\Request;

class TenantScreenContentController extends Controller
{
    // Manager: any screen in company
    // Supervisor: only screens assigned to them
    public function setPlaylist(Request $request, Screen $screen)
    {
        $u = $request->user();

        if ($u->customer_id !== $screen->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($u->role === 'supervisor' && $screen->assigned_user_id !== $u->id) {
            return response()->json(['message'=>'Only assigned screens allowed'], 403);
        }

        $data = $request->validate([
            'playlist_id' => ['nullable','integer','exists:playlists,id'],
        ]);

        if (!empty($data['playlist_id'])) {
            $pl = Playlist::findOrFail($data['playlist_id']);
            if ($pl->customer_id !== $u->customer_id) {
                return response()->json(['message'=>'Playlist not in your company'], 422);
            }
            $screen->playlist_id = $pl->id;
        } else {
            $screen->playlist_id = null;
        }
        $screen->save();

        event(new \App\Events\ScreenConfigUpdated($u->customer_id, (int)$screen->id, $screen->playlist?->content_version ?? ''));
        return response()->json(['message'=>'Screen playlist updated','screen_id'=>$screen->id,'playlist_id'=>$screen->playlist_id]);
    }

    public function refreshScreen(Request $request, Screen $screen)
    {
        $u = $request->user();
        if ($u->customer_id !== $screen->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($u->role === 'supervisor' && $screen->assigned_user_id !== $u->id) {
            return response()->json(['message'=>'Only assigned screens allowed'], 403);
        }

        event(new \App\Events\ScreenConfigUpdated($u->customer_id, (int)$screen->id, ''));
        return response()->json(['message'=>'Refresh signal sent']);
    }
}
