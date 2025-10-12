<?php
// app/Http/Controllers/User/TenantContentBulkController.php
namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Screen;
use Illuminate\Http\Request;

class TenantContentBulkController extends Controller
{
    // Manager only: all company screens
    public function assignPlaylistToCompanyScreens(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        $data = $request->validate([
            'playlist_id' => ['nullable','integer','exists:playlists,id'],
        ]);

        $pl = null;
        if (!empty($data['playlist_id'])) {
            $pl = Playlist::findOrFail($data['playlist_id']);
            if ($pl->customer_id !== $u->customer_id) return response()->json(['message'=>'Playlist not in your company'], 422);
        }

        Screen::where('customer_id',$u->customer_id)->update(['playlist_id'=>$data['playlist_id'] ?? null]);
        event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, $pl->content_version ?? ''));
        return response()->json(['message'=>'Assigned to all company screens']);
    }

    // Manager: any company screens; Supervisor: only assigned screens
    // Body: { "all": true } OR { "screen_ids": [..] } + playlist_id (or null)
    public function assignPlaylistToScreens(Request $request)
    {
        $u = $request->user();
        $data = $request->validate([
            'all'          => ['sometimes','boolean'],
            'screen_ids'   => ['sometimes','array','min:1'],
            'screen_ids.*' => ['integer','exists:screens,id'],
            'playlist_id'  => ['nullable','integer','exists:playlists,id'],
        ]);

        if (!empty($data['all'])) {
            $scope = Screen::query()->where('customer_id',$u->customer_id);
            if ($u->role === 'supervisor') $scope->where('assigned_user_id',$u->id);
            $screens = $scope->get();
        } else {
            if (empty($data['screen_ids'])) {
                return response()->json(['message'=>'Provide screen_ids[] or set all=true'], 422);
            }
            $screens = Screen::whereIn('id',$data['screen_ids'])->get();
            foreach ($screens as $s) {
                if ($s->customer_id !== $u->customer_id) return response()->json(['message'=>"Screen {$s->id} out of company"], 403);
                if ($u->role === 'supervisor' && $s->assigned_user_id !== $u->id) return response()->json(['message'=>"Screen {$s->id} not assigned to you"], 403);
            }
        }

        $pl = null;
        if (!empty($data['playlist_id'])) {
            $pl = Playlist::findOrFail($data['playlist_id']);
            if ($pl->customer_id !== $u->customer_id) return response()->json(['message'=>'Playlist not in your company'], 422);
        }

        Screen::whereIn('id',$screens->pluck('id'))->update(['playlist_id'=>$data['playlist_id'] ?? null]);
        foreach ($screens as $s) {
            event(new \App\Events\ScreenConfigUpdated($u->customer_id, (int)$s->id, $pl->content_version ?? ''));
        }
        return response()->json(['message'=>'Assigned to selected screens','count'=>$screens->count()]);
    }

    // Manager only: broadcast to all company screens
    public function broadcastCompanyConfig(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);
        event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, ''));
        return response()->json(['message'=>'Broadcast sent']);
    }

    // Manager/Supervisor: broadcast to selected or all-in-scope screens
    public function broadcastScreensConfig(Request $request)
    {
        $u = $request->user();
        $data = $request->validate([
            'all'          => ['sometimes','boolean'],
            'screen_ids'   => ['sometimes','array','min:1'],
            'screen_ids.*' => ['integer','exists:screens,id'],
        ]);

        if (!empty($data['all'])) {
            $scope = Screen::query()->where('customer_id',$u->customer_id);
            if ($u->role === 'supervisor') $scope->where('assigned_user_id',$u->id);
            $ids = $scope->pluck('id');
        } else {
            if (empty($data['screen_ids'])) return response()->json(['message'=>'Provide screen_ids[] or set all=true'], 422);
            $ids = collect($data['screen_ids']);
            foreach (Screen::whereIn('id',$ids)->get() as $s) {
                if ($s->customer_id !== $u->customer_id) return response()->json(['message'=>"Screen {$s->id} out of company"], 403);
                if ($u->role === 'supervisor' && $s->assigned_user_id !== $u->id) return response()->json(['message'=>"Screen {$s->id} not assigned to you"], 403);
            }
        }

        foreach ($ids as $sid) event(new \App\Events\ScreenConfigUpdated($u->customer_id, (int)$sid, ''));
        return response()->json(['message'=>'Broadcast sent','count'=>$ids->count()]);
    }
}
