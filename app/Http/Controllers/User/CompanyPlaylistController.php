<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use Illuminate\Http\Request;

class CompanyPlaylistController extends Controller
{
    // List company playlists (manager scope)
    public function index(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'manager') return response()->json(['message' => 'Only managers'], 403);

        $data = $request->validate([
            'q'        => ['nullable','string','max:190'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $q = Playlist::withCount('items')->where('customer_id', $u->customer_id)->latest();
        if (!empty($data['q'])) $q->where('name', 'like', '%'.$data['q'].'%');

        return response()->json($q->paginate($data['per_page'] ?? 15));
    }

    // Show a playlist (must be in same company)
    public function show(Request $request, Playlist $playlist)
    {
        $u = $request->user();
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);

        return response()->json($playlist->load(['items' => fn($q) => $q->orderBy('sort')]));
    }

    // Create a playlist for the manager's company
    public function store(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        $data = $request->validate([
            'name'         => ['required','string','max:190'],
            'is_default'   => ['sometimes','boolean'],
            'published_at' => ['sometimes','nullable','date'],
            'meta'         => ['nullable','array'],
        ]);

        $playlist = Playlist::create([
            'customer_id'  => $u->customer_id,
            'name'         => $data['name'],
            'is_default'   => (bool)($data['is_default'] ?? false),
            'published_at' => $data['published_at'] ?? null,
            'meta'         => $data['meta'] ?? null,
        ]);

        if ($playlist->is_default) {
            Playlist::where('customer_id', $u->customer_id)
                ->where('id', '!=', $playlist->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['message'=>'Playlist created','playlist'=>$playlist], 201);
    }

    // Update a playlist (name / default / publish time / meta)
    public function update(Request $request, Playlist $playlist)
    {
        $u = $request->user();
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        $data = $request->validate([
            'name'         => ['nullable','string','max:190'],
            'is_default'   => ['sometimes','boolean'],
            'published_at' => ['sometimes','nullable','date'],
            'meta'         => ['nullable','array'],
        ]);

        $playlist->fill($data)->save();

        if (array_key_exists('is_default', $data) && $data['is_default']) {
            Playlist::where('customer_id', $u->customer_id)
                ->where('id', '!=', $playlist->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['message'=>'Playlist updated','playlist'=>$playlist->fresh()]);
    }

    // Delete a playlist
    public function destroy(Request $request, Playlist $playlist)
    {
        $u = $request->user();
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        $playlist->delete();
        return response()->json(['message'=>'Playlist deleted']);
    }

    // Publish: stamp published_at, refresh version, broadcast
    public function publish(Request $request, Playlist $playlist)
    {
        $u = $request->user();
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        $playlist->published_at = now();
        $playlist->refreshVersion();
        $this->broadcastForPlaylist($playlist);

        return response()->json(['message'=>'Playlist published','playlist'=>$playlist->fresh()]);
    }

    // Set as default for company
    public function setDefault(Request $request, Playlist $playlist)
    {
        $u = $request->user();
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        Playlist::where('customer_id', $u->customer_id)->update(['is_default'=>false]);
        $playlist->is_default = true;
        $playlist->save();

        event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));
        return response()->json(['message'=>'Set as default','playlist'=>$playlist->fresh()]);
    }

    // Recompute version & broadcast
    public function refreshVersion(Request $request, Playlist $playlist)
    {
        $u = $request->user();
        if ($u->customer_id !== $playlist->customer_id) return response()->json(['message'=>'Out of scope'], 403);
        if ($u->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        $playlist->refreshVersion();
        $this->broadcastForPlaylist($playlist);

        return response()->json(['message'=>'Version refreshed','content_version'=>$playlist->content_version]);
    }

    protected function broadcastForPlaylist(Playlist $playlist): void
    {
        foreach ($playlist->screens()->pluck('id') as $sid) {
            event(new \App\Events\ScreenConfigUpdated(
                $playlist->customer_id, (int)$sid, $playlist->content_version ?? ''
            ));
        }
        if ($playlist->is_default) {
            event(new \App\Events\ScreenConfigUpdated($playlist->customer_id, null, $playlist->content_version ?? ''));
        }
    }
}
