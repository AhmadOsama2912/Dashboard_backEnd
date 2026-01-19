<?php
// app/Http/Controllers/Admin/AdminScreenContentController.php
namespace App\Http\Controllers\Admin;

use App\Events\ScreenConfigUpdated;
use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Screen;
use Illuminate\Http\Request;
use App\Services\ScreenPushService;

class AdminScreenContentController extends Controller
{
    /**
     * PATCH /admin/v1/screens/{screen}/playlist
     * body: { playlist_id: int|null }
     * Note: we DO NOT require screens.playlist_id column.
     * We store the assignment under screens.meta->playlist_id.
     */
    public function setPlaylist(Request $request, Screen $screen)
    {
        $data = $request->validate([
            'playlist_id' => ['nullable','integer','exists:playlists,id'],
        ]);

        $meta = $screen->meta ?? [];

        if (!empty($data['playlist_id'])) {
            $pl = Playlist::findOrFail($data['playlist_id']);
            if ($pl->customer_id !== $screen->customer_id) {
                return response()->json(['message' => 'Playlist/customer mismatch'], 422);
            }
            $meta['playlist_id'] = (int) $pl->id;
        } else {
            unset($meta['playlist_id']); // follow company default
        }

        $screen->meta = $meta;
        $screen->save();

        try {
        app(ScreenPushService::class)->bumpScreens([$screen->id], $playlist->content_version ?? null);
        } catch (\Throwable $e) {
            \Log::warning('WS push failed (setPlaylist)', [
                'screen_id' => $screen->id,
                'error' => $e->getMessage(),
            ]);
        }
        event(new ScreenConfigUpdated($screen->customer_id, (int) $screen->id, $screen->playlist?->content_version ?? ''));

        return response()->json([
            'message'      => 'Screen playlist updated',
            'screen_id'    => $screen->id,
            'playlist_id'  => $meta['playlist_id'] ?? null,
        ]);
    }

    /**
     * POST /admin/v1/screens/{screen}/refresh
     * Asks device to pull latest config immediately.
     */
    public function refreshScreen(\App\Models\Screen $screen)
    {
        try {
            app(ScreenPushService::class)->bumpScreens([$screen->id], 'force');
        } catch (\Throwable $e) {
            \Log::warning('WS push failed (refreshScreen)', [
                'screen_id' => $screen->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'pushed',
            'screen_id' => $screen->id,
        ]);
    }
}