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

        if ((int) $u->customer_id !== (int) $screen->customer_id) {
            return response()->json(['message' => 'Out of scope'], 403);
        }

        // Supervisor can only change assigned screens (and must have ability)
        if ($u->role === 'supervisor') {
            if ((int) $screen->assigned_user_id !== (int) $u->id) {
                return response()->json(['message' => 'Only assigned screens allowed'], 403);
            }
            if (method_exists($u, 'hasAbility') && !$u->hasAbility('devices:assign')) {
                return response()->json(['message' => 'Missing ability: devices:assign'], 403);
            }
        }

        $data = $request->validate([
            'playlist_id' => ['nullable', 'integer', 'exists:playlists,id'],
        ]);

        $meta = is_array($screen->meta) ? $screen->meta : [];

        if (!empty($data['playlist_id'])) {
            $pl = Playlist::query()->findOrFail((int) $data['playlist_id']);

            if ((int) $pl->customer_id !== (int) $u->customer_id) {
                return response()->json(['message' => 'Playlist not in your company'], 422);
            }

            // Option B: store inside meta JSON
            $meta['playlist_id'] = (int) $pl->id;
        } else {
            // follow company default
            unset($meta['playlist_id']);
        }

        $screen->meta = $meta;
        $screen->save();

        // If you have a WS bump service, you can call it here (optional)
        try {
            app(\App\Services\ScreenPushService::class)->bumpScreens([(int) $screen->id], $pl->content_version ?? null);
        } catch (\Throwable $e) {
            \Log::warning('WS push failed (setPlaylist)', ['screen_id' => $screen->id, 'error' => $e->getMessage()]);
        }

        event(new ScreenConfigUpdated(
            (int) $u->customer_id,
            (int) $screen->id,
            $pl->content_version ?? ''
        ));

        return response()->json([
            'message'     => 'Screen playlist updated',
            'screen_id'   => (int) $screen->id,
            'playlist_id' => $meta['playlist_id'] ?? null,
        ]);
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
