<?php
// app/Http/Controllers/Admin/AdminPlaylistItemController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminPlaylistItemController extends Controller
{
    /** POST /admin/v1/playlists/{playlist}/items  (multipart) */
    public function store(Request $request, Playlist $playlist)
    {
        $data = $request->validate([
            'type'     => ['required', Rule::in(['image','video'])],
            'file'     => ['required','file','mimes:jpg,jpeg,png,webp,mp4,mov,m4v,webm','max:409600'], // 400MB
            'duration' => ['nullable','integer','min:1','max:3600'], // required for image (enforced below)
            'loops'    => ['nullable','integer','min:1','max:100'],   // stored in meta if column exists
        ]);

        if ($data['type'] === 'image' && empty($data['duration'])) {
            return response()->json(['message'=>'Duration is required for images'], 422);
        }

        $folder = 'media/customer_'.$playlist->customer_id;
        $path   = $request->file('file')->store($folder, 'public');
        $url    = Storage::disk('public')->url($path);
        // dd($path, $url);
        $hash   = 'md5:'.md5_file(Storage::disk('public')->path($path));

        $item = new PlaylistItem();
        $item->playlist_id = $playlist->id;
        $item->type        = $data['type'];
        $item->src         = $path;
        $item->duration    = $data['type']==='image' ? (int) $data['duration'] : null; // videos use natural length
        $item->checksum    = $hash;
        $item->sort        = (int) (($playlist->items()->max('sort') ?? 0) + 1);
        // If playlist_items has a meta JSON column, keep loop count there
        if ($item->isFillable('meta')) {
            $meta = $item->meta ?? [];
            if (!empty($data['loops'])) $meta['loops'] = (int) $data['loops'];
            $item->meta = $meta;
        }
        $item->save();

        // bump version
        $playlist->refreshVersion();

        // notify screens of this customer
        event(new \App\Events\ScreenConfigUpdated($playlist->customer_id, 0, $playlist->content_version));

        return response()->json(['message'=>'Item added','item'=>$item], 201);
    }

    /** PATCH /admin/v1/playlists/{playlist}/items/{item}  */
    public function update(Request $request, Playlist $playlist, PlaylistItem $item)
    {
        if ($item->playlist_id !== $playlist->id) {
            abort(404);
        }

        $data = $request->validate([
            'duration' => ['nullable','integer','min:1','max:3600'],
            'loops'    => ['nullable','integer','min:1','max:100'],
            'replace'  => ['nullable','file','mimes:jpg,jpeg,png,webp,mp4,mov,m4v,webm','max:409600'],
        ]);

        // Replace media file (optional)
        if ($request->hasFile('replace')) {
            $folder = 'media/customer_'.$playlist->customer_id;
            $path   = $request->file('replace')->store($folder, 'public');
            $item->src      = Storage::disk('public')->url($path);
            $item->checksum = 'md5:'.md5_file(Storage::disk('public')->path($path));
            if ($item->type === 'image' && empty($data['duration']) && empty($item->duration)) {
                return response()->json(['message'=>'Duration is required for images'], 422);
            }
        }

        if ($item->type === 'image' && isset($data['duration'])) {
            $item->duration = (int) $data['duration'];
        }

        if ($item->isFillable('meta')) {
            $meta = $item->meta ?? [];
            if (array_key_exists('loops', $data)) $meta['loops'] = $data['loops'];
            $item->meta = $meta;
        }

        $item->save();
        $playlist->refreshVersion();
        event(new \App\Events\ScreenConfigUpdated($playlist->customer_id, 0, $playlist->content_version));

        return response()->json(['message'=>'Item updated','item'=>$item]);
    }

    /** DELETE /admin/v1/playlists/{playlist}/items/{item} */
    public function destroy(Playlist $playlist, PlaylistItem $item)
    {
        if ($item->playlist_id !== $playlist->id) abort(404);

        $item->delete();
        $playlist->refreshVersion();
        event(new \App\Events\ScreenConfigUpdated($playlist->customer_id, 0, $playlist->content_version));

        return response()->json(['message'=>'Item deleted']);
    }

    /** PATCH /admin/v1/playlists/{playlist}/items/reorder
     * body: { items: [ {id: X, sort: 1}, ... ] }  OR { ids: [id1,id2,...] }
     */
    public function reorder(Request $request, Playlist $playlist)
    {
        $data = $request->validate([
            'ids'   => ['nullable','array'],
            'ids.*' => ['integer'],
            'items'   => ['nullable','array'],
            'items.*.id'   => ['required','integer'],
            'items.*.sort' => ['required','integer'],
        ]);

        if (!empty($data['items'])) {
            foreach ($data['items'] as $row) {
                PlaylistItem::where('playlist_id',$playlist->id)
                    ->where('id',$row['id'])
                    ->update(['sort'=>$row['sort']]);
            }
        } elseif (!empty($data['ids'])) {
            $n = 1;
            foreach ($data['ids'] as $id) {
                PlaylistItem::where('playlist_id',$playlist->id)
                    ->where('id',$id)
                    ->update(['sort'=>$n++]);
            }
        }

        $playlist->refreshVersion();
        event(new \App\Events\ScreenConfigUpdated($playlist->customer_id, 0, $playlist->content_version));

        return response()->json(['message'=>'Order updated']);
    }
}
