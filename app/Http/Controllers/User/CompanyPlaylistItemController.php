<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyPlaylistItemController extends Controller
{
    // Add item to a company playlist
    public function store(Request $request, Playlist $playlist)
    {
        $u = $request->user();
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);

        $data = $request->validate([
            'type'     => ['required','in:image,video,web'],
            'src'      => ['required','string','max:512'],
            'duration' => ['required','integer','min:1','max:36000'],
            'sort'     => ['nullable','integer','min:0'],
            'checksum' => ['nullable','string','max:100'],
            'meta'     => ['nullable','array'],
        ]);

        $item = $playlist->items()->create($data);

        $playlist->refreshVersion();
        event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message'=>'Item created','item'=>$item], 201);
    }

    // Update existing item
    public function update(Request $request, Playlist $playlist, PlaylistItem $item)
    {
        $u = $request->user();
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($item->playlist_id !== $playlist->id) return response()->json(['message'=>'Item not in this playlist'], 422);

        $data = $request->validate([
            'type'     => ['nullable','in:image,video,web'],
            'src'      => ['nullable','string','max:512'],
            'duration' => ['nullable','integer','min:1','max:36000'],
            'sort'     => ['nullable','integer','min:0'],
            'checksum' => ['nullable','string','max:100'],
            'meta'     => ['nullable','array'],
        ]);

        $item->fill($data)->save();

        $playlist->refreshVersion();
        event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message'=>'Item updated','item'=>$item->fresh()]);
    }

    // Delete item
    public function destroy(Request $request, Playlist $playlist, PlaylistItem $item)
    {
        $u = $request->user();
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($item->playlist_id !== $playlist->id) return response()->json(['message'=>'Item not in this playlist'], 422);

        $item->delete();

        $playlist->refreshVersion();
        event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message'=>'Item deleted']);
    }

    // Reorder items
    public function reorder(Request $request, Playlist $playlist)
    {
        $u = $request->user();
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);

        $data = $request->validate([
            'orders' => ['required','array','min:1'],
            'orders.*.id'   => ['required','integer','exists:playlist_items,id'],
            'orders.*.sort' => ['required','integer','min:0'],
        ]);

        DB::transaction(function () use ($playlist, $data) {
            foreach ($data['orders'] as $o) {
                // ensure each item belongs to this playlist
                PlaylistItem::where('playlist_id', $playlist->id)
                    ->where('id', $o['id'])
                    ->update(['sort' => $o['sort']]);
            }
        });

        $playlist->refreshVersion();
        event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message'=>'Items reordered']);
    }
}
