<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPlaylistItemController extends Controller
{
    public function store(Request $request, Playlist $playlist)
    {
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

        return response()->json(['message' => 'Item created', 'item' => $item], 201);
    }

    public function update(Request $request, Playlist $playlist, PlaylistItem $item)
    {
        if ($item->playlist_id !== $playlist->id) {
            return response()->json(['message' => 'Item not in this playlist'], 422);
        }

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

        return response()->json(['message' => 'Item updated', 'item' => $item->fresh()]);
    }

    public function destroy(Playlist $playlist, PlaylistItem $item)
    {
        if ($item->playlist_id !== $playlist->id) {
            return response()->json(['message' => 'Item not in this playlist'], 422);
        }

        $item->delete();
        $playlist->refreshVersion();

        return response()->json(['message' => 'Item deleted']);
    }

    public function reorder(Request $request, Playlist $playlist)
    {
        $data = $request->validate([
            'orders' => ['required','array','min:1'],
            'orders.*.id'   => ['required','integer','exists:playlist_items,id'],
            'orders.*.sort' => ['required','integer','min:0'],
        ]);

        DB::transaction(function () use ($playlist, $data) {
            foreach ($data['orders'] as $o) {
                PlaylistItem::where('playlist_id', $playlist->id)
                    ->where('id', $o['id'])
                    ->update(['sort' => $o['sort']]);
            }
        });

        $playlist->refreshVersion();
        return response()->json(['message' => 'Items reordered']);
    }
}
