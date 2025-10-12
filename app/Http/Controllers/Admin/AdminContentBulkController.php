<?php
// app/Http/Controllers/Admin/AdminContentBulkController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Screen;
use Illuminate\Http\Request;

class AdminContentBulkController extends Controller
{
    // ALL screens across server → set to default OR assign playlist company's screens
    public function assignPlaylistToAllScreens(Request $request)
    {
        $data = $request->validate([
            'playlist_id' => ['nullable','integer','exists:playlists,id'],
        ]);

        if (empty($data['playlist_id'])) {
            Screen::query()->update(['playlist_id'=>null]);
            return response()->json(['message'=>'All screens set to company default']);
        }

        $pl = Playlist::findOrFail($data['playlist_id']);
        Screen::where('customer_id',$pl->customer_id)->update(['playlist_id'=>$pl->id]);
        event(new \App\Events\ScreenConfigUpdated($pl->customer_id, null, $pl->content_version ?? ''));
        return response()->json(['message'=>'Assigned to all screens of that company','customer_id'=>$pl->customer_id]);
    }

    // Company-only bulk
    public function assignPlaylistToCompanyScreens(Request $request, int $customer)
    {
        $data = $request->validate([
            'playlist_id' => ['nullable','integer','exists:playlists,id'],
        ]);

        $pl = null;
        if (!empty($data['playlist_id'])) {
            $pl = Playlist::findOrFail($data['playlist_id']);
            if ($pl->customer_id !== $customer) {
                return response()->json(['message'=>'Playlist/customer mismatch'], 422);
            }
        }

        Screen::where('customer_id',$customer)->update(['playlist_id'=>$data['playlist_id'] ?? null]);
        event(new \App\Events\ScreenConfigUpdated($customer, null, $pl->content_version ?? ''));
        return response()->json(['message'=>'Assigned to all company screens']);
    }

    // Selected screens (any companies)
    public function assignPlaylistToScreens(Request $request)
    {
        $data = $request->validate([
            'screen_ids'   => ['required','array','min:1'],
            'screen_ids.*' => ['integer','exists:screens,id'],
            'playlist_id'  => ['nullable','integer','exists:playlists,id'],
        ]);

        $pl = null;
        if (!empty($data['playlist_id'])) $pl = Playlist::findOrFail($data['playlist_id']);

        $screens = Screen::whereIn('id',$data['screen_ids'])->get();
        foreach ($screens as $s) {
            if ($pl && $pl->customer_id !== $s->customer_id) {
                return response()->json(['message'=>"Playlist/customer mismatch for screen {$s->id}"], 422);
            }
        }

        Screen::whereIn('id',$screens->pluck('id'))->update(['playlist_id'=>$data['playlist_id'] ?? null]);

        foreach ($screens as $s) {
            event(new \App\Events\ScreenConfigUpdated($s->customer_id, (int)$s->id, $pl->content_version ?? ''));
        }
        return response()->json(['message'=>'Assigned to selected screens','count'=>$screens->count()]);
    }

    public function broadcastCustomerConfig(int $customer)
    {
        event(new \App\Events\ScreenConfigUpdated($customer, null, ''));
        return response()->json(['message'=>'Broadcast sent to company screens']);
    }

    public function broadcastScreensConfig(Request $request)
    {
        $data = $request->validate([
            'screen_ids'   => ['required','array','min:1'],
            'screen_ids.*' => ['integer','exists:screens,id'],
        ]);

        foreach ($data['screen_ids'] as $sid) {
            $scr = Screen::find($sid);
            if ($scr) event(new \App\Events\ScreenConfigUpdated($scr->customer_id, (int)$sid, ''));
        }
        return response()->json(['message'=>'Broadcast sent','count'=>count($data['screen_ids'])]);
    }
}
