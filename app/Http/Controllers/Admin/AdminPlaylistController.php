<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use Illuminate\Http\Request;

class AdminPlaylistController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'q'           => ['nullable','string','max:190'],
            'per_page'    => ['nullable','integer','min:1','max:100'],
        ]);

        $q = Playlist::withCount('items')->latest();
        if (!empty($data['customer_id'])) $q->where('customer_id', $data['customer_id']);
        if (!empty($data['q']))           $q->where('name', 'like', '%'.$data['q'].'%');

        return response()->json($q->paginate($data['per_page'] ?? 15));
    }

    public function show(Playlist $playlist)
    {
        return response()->json($playlist->load(['items' => fn($q) => $q->orderBy('sort')]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id'  => ['required','integer','exists:customers,id'],
            'name'         => ['required','string','max:190'],
            'is_default'   => ['sometimes','boolean'],
            'published_at' => ['sometimes','nullable','date'],
            'meta'         => ['nullable','array'],
        ]);

        $playlist = Playlist::create($data);

        // keep a single default per customer
        if (!empty($data['is_default'])) {
            Playlist::where('customer_id', $playlist->customer_id)
                ->where('id', '!=', $playlist->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['message' => 'Playlist created', 'playlist' => $playlist], 201);
    }

    public function update(Request $request, Playlist $playlist)
    {
        $data = $request->validate([
            'name'         => ['nullable','string','max:190'],
            'is_default'   => ['sometimes','boolean'],
            'published_at' => ['sometimes','nullable','date'],
            'meta'         => ['nullable','array'],
        ]);

        $playlist->fill($data)->save();

        if (array_key_exists('is_default', $data) && $data['is_default']) {
            Playlist::where('customer_id', $playlist->customer_id)
                ->where('id', '!=', $playlist->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['message' => 'Playlist updated', 'playlist' => $playlist->fresh()]);
    }

    public function destroy(Playlist $playlist)
    {
        $playlist->delete();
        return response()->json(['message' => 'Playlist deleted']);
    }

    public function publish(Playlist $playlist)
    {
        $playlist->published_at = now();
        $playlist->refreshVersion();                            // method on the model
        $this->broadcastForPlaylist($playlist);

        return response()->json([
            'message' => 'Playlist published',
            'playlist' => $playlist->fresh()
        ]);
    }

    public function setDefault(Playlist $playlist)
    {
        Playlist::where('customer_id', $playlist->customer_id)->update(['is_default' => false]);
        $playlist->is_default = true;
        $playlist->save();

        event(new \App\Events\ScreenConfigUpdated($playlist->customer_id, null, $playlist->content_version ?? ''));
        return response()->json(['message' => 'Set as default', 'playlist' => $playlist->fresh()]);
    }

    public function refreshVersion(Playlist $playlist)
    {
        $playlist->refreshVersion();
        $this->broadcastForPlaylist($playlist);

        return response()->json([
            'message' => 'Version refreshed',
            'content_version' => $playlist->content_version
        ]);
    }

    protected function broadcastForPlaylist(Playlist $playlist): void
    {
        foreach ($playlist->screens()->pluck('id') as $sid) {
            event(new \App\Events\ScreenConfigUpdated(
                $playlist->customer_id,
                (int)$sid,
                $playlist->content_version ?? ''
            ));
        }
        if ($playlist->is_default) {
            event(new \App\Events\ScreenConfigUpdated($playlist->customer_id, null, $playlist->content_version ?? ''));
        }
    }
}
