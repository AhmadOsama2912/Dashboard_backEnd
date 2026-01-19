<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Services\PlaylistResolver;
use App\Events\ScreenConfigUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class AdminPlaylistController extends Controller
{
    /**
     * GET /admin/v1/playlists
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'q'           => ['nullable','string','max:190'],
            'per_page'    => ['nullable','integer','min:1','max:100'],
        ]);

        $q = Playlist::withCount('items')->latest();

        if (!empty($data['customer_id'])) {
            $q->where('customer_id', $data['customer_id']);
        }
        if (!empty($data['q'])) {
            $q->where('name', 'like', '%'.$data['q'].'%');
        }

        return response()->json($q->paginate($data['per_page'] ?? 15));
    }

    /**
     * GET /admin/v1/playlists/{playlist}
     */
    public function show(Playlist $playlist)
    {
        return response()->json(
            $playlist->load(['items' => fn($q) => $q->orderBy('sort')])
        );
    }

    /**
     * POST /admin/v1/playlists
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id'  => ['required','integer','exists:customers,id'],
            'name'         => ['required','string','max:190'],
            'is_default'   => ['sometimes','boolean'],
            'published_at' => ['sometimes','nullable','date'],
            'meta'         => ['nullable','array'],
        ]);

        $playlist = Playlist::create([
            'customer_id'  => $data['customer_id'],
            'name'         => $data['name'],
            'is_default'   => (bool)($data['is_default'] ?? false),
            'published_at' => $data['published_at'] ?? null,
            'meta'         => $data['meta'] ?? null,
        ]);

        // Keep a single default per customer + clear default cache + broadcast
        if ($playlist->is_default) {
            Playlist::where('customer_id', $playlist->customer_id)
                ->where('id', '!=', $playlist->id)
                ->update(['is_default' => false]);

            App::make(PlaylistResolver::class)->forgetCompanyDefault($playlist->customer_id);
            event(new ScreenConfigUpdated($playlist->customer_id, null, $playlist->content_version ?? ''));
        }

        return response()->json(['message' => 'Playlist created', 'playlist' => $playlist], 201);
    }

    /**
     * PATCH /admin/v1/playlists/{playlist}
     */
    public function update(Request $request, Playlist $playlist)
    {
        $data = $request->validate([
            'name'         => ['nullable','string','max:190'],
            'is_default'   => ['sometimes','boolean'],
            'published_at' => ['sometimes','nullable','date'],
            'meta'         => ['nullable','array'],
        ]);

        $playlist->fill($data)->save();

        // If made default, enforce single default + clear cache + broadcast
        if (array_key_exists('is_default', $data) && $data['is_default']) {
            Playlist::where('customer_id', $playlist->customer_id)
                ->where('id', '!=', $playlist->id)
                ->update(['is_default' => false]);

            App::make(PlaylistResolver::class)->forgetCompanyDefault($playlist->customer_id);
            event(new ScreenConfigUpdated($playlist->customer_id, null, $playlist->content_version ?? ''));
        }

        return response()->json(['message' => 'Playlist updated', 'playlist' => $playlist->fresh()]);
    }

    /**
     * DELETE /admin/v1/playlists/{playlist}
     */
    public function destroy(Playlist $playlist)
    {
        $wasDefault = (bool) $playlist->is_default;
        $customerId = (int) $playlist->customer_id;

        $playlist->delete();

        if ($wasDefault) {
            // Clear cached default and broadcast so screens re-fetch config
            App::make(PlaylistResolver::class)->forgetCompanyDefault($customerId);
            event(new ScreenConfigUpdated($customerId, null, ''));
        }

        return response()->json(['message' => 'Playlist deleted']);
    }

    /**
     * POST /admin/v1/playlists/{playlist}/publish
     * Recompute content_version and broadcast.
     */
    public function publish(Request $request, Playlist $playlist)
    {
        $playlist->published_at = now();
        $playlist->refreshVersion(); // make sure content_version reflects items

        // Broadcast to all screens in the company (explicit + default consumers)
        event(new ScreenConfigUpdated($playlist->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message' => 'Playlist published', 'playlist' => $playlist->fresh()]);
    }

    /**
     * POST /admin/v1/playlists/{playlist}/default
     * Make this the only default for the company.
     */
    public function setDefault(Request $request, Playlist $playlist)
    {
        Playlist::where('customer_id', $playlist->customer_id)->update(['is_default' => false]);

        $playlist->is_default = true;
        $playlist->save();

        // Clear resolver cache + broadcast to all screens of this company
        App::make(PlaylistResolver::class)->forgetCompanyDefault($playlist->customer_id);
        event(new ScreenConfigUpdated($playlist->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message' => 'Default playlist set', 'playlist' => $playlist->fresh()]);
    }

    /**
     * POST /admin/v1/playlists/{playlist}/refresh
     * Recompute content_version and broadcast.
     */
    public function refreshVersion(Request $request, Playlist $playlist)
    {
        $playlist->refreshVersion();

        event(new ScreenConfigUpdated($playlist->customer_id, null, $playlist->content_version ?? ''));

        return response()->json([
            'message' => 'Version refreshed',
            'content_version' => $playlist->content_version
        ]);
    }
}
