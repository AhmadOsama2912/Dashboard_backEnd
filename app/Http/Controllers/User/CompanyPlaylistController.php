<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Services\PlaylistResolver;
use App\Events\ScreenConfigUpdated;
use Illuminate\Http\Request;

class CompanyPlaylistController extends Controller
{
    public function __construct(private PlaylistResolver $resolver)
    {
        // Optionally also enforce ability middleware here if routes don't:
        // $this->middleware('abilities:user:playlist:write');
    }

    /**
     * GET /user/v1/playlists
     * Manager-only: list company playlists (paginated, searchable).
     */
    public function index(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'q'        => ['nullable', 'string', 'max:190'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = Playlist::withCount('items')
            ->where('customer_id', $u->customer_id)
            ->latest();

        if (!empty($data['q'])) {
            $q->where('name', 'like', '%' . $data['q'] . '%');
        }

        return response()->json($q->paginate($data['per_page'] ?? 15));
    }

    /**
     * GET /user/v1/playlists/{playlist}
     * Manager-only: show a playlist in same company (with ordered items).
     */
    public function show(Request $request, Playlist $playlist)
    {
        $u = $request->user();

        if ($u->customer_id !== $playlist->customer_id) {
            return response()->json(['message' => 'Out of scope'], 403);
        }

        return response()->json(
            $playlist->load(['items' => fn($q) => $q->orderBy('sort')])
        );
    }

    /**
     * POST /user/v1/playlists
     * Manager-only: create a playlist for the manager's company.
     */
    public function store(Request $request)
    {
        $u = $request->user();


        $data = $request->validate([
            'name'         => ['required', 'string', 'max:190'],
            'is_default'   => ['sometimes', 'boolean'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'meta'         => ['nullable', 'array'],
        ]);

        $playlist = Playlist::create([
            'customer_id'  => $u->customer_id,
            'name'         => $data['name'],
            'is_default'   => (bool)($data['is_default'] ?? false),
            'published_at' => $data['published_at'] ?? null,
            'meta'         => $data['meta'] ?? null,
        ]);

        // If marked default, clear other defaults and forget cache.
        if ($playlist->is_default) {
            Playlist::where('customer_id', $u->customer_id)
                ->where('id', '!=', $playlist->id)
                ->update(['is_default' => false]);

            $this->resolver->forgetCompanyDefault($u->customer_id);
            // Broadcast to all company screens (default consumers).
            event(new ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));
        }

        return response()->json(['message' => 'Playlist created', 'playlist' => $playlist], 201);
    }

    /**
     * PATCH /user/v1/playlists/{playlist}
     * Manager-only: update playlist fields (name/default/published_at/meta).
     */
    public function update(Request $request, Playlist $playlist)
    {
        $u = $request->user();

        if ($u->customer_id !== $playlist->customer_id) {
            return response()->json(['message' => 'Out of scope'], 403);
        }

        $data = $request->validate([
            'name'         => ['nullable', 'string', 'max:190'],
            'is_default'   => ['sometimes', 'boolean'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'meta'         => ['nullable', 'array'],
        ]);

        $playlist->fill($data)->save();

        // If toggled to default, make it the only default and clear cache + broadcast.
        if (array_key_exists('is_default', $data) && $data['is_default']) {
            Playlist::where('customer_id', $u->customer_id)
                ->where('id', '!=', $playlist->id)
                ->update(['is_default' => false]);

            $this->resolver->forgetCompanyDefault($u->customer_id);
            event(new ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));
        }

        return response()->json(['message' => 'Playlist updated', 'playlist' => $playlist->fresh()]);
    }

    /**
     * DELETE /user/v1/playlists/{playlist}
     * Manager-only: delete a playlist.
     */
    public function destroy(Request $request, Playlist $playlist)
    {
        $u = $request->user();

        if ($u->customer_id !== $playlist->customer_id) {
            return response()->json(['message' => 'Out of scope'], 403);
        }

        $wasDefault = (bool) $playlist->is_default;
        $customerId = (int) $playlist->customer_id;

        $playlist->delete();

        if ($wasDefault) {
            // Clear cached default so resolver won’t return a stale playlist.
            $this->resolver->forgetCompanyDefault($customerId);
            // Optional: broadcast to inform devices to fallback to waiting/other default.
            event(new ScreenConfigUpdated($customerId, null, ''));
        }

        return response()->json(['message' => 'Playlist deleted']);
    }

    /**
     * POST /user/v1/playlists/{playlist}/publish
     * Manager-only: set published_at=now, refresh content_version, broadcast.
     */
    public function publish(Request $request, Playlist $playlist)
    {
        $u = $request->user();

        if ($u->customer_id !== $playlist->customer_id) {
            return response()->json(['message' => 'Out of scope'], 403);
        }

        $playlist->published_at = now();
        $playlist->refreshVersion();

        // Broadcast to all screens in this company (explicit + default users).
        event(new ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message' => 'Playlist published', 'playlist' => $playlist->fresh()]);
    }

    /**
     * POST /user/v1/playlists/{playlist}/default
     * Manager-only: make this the default playlist for the company.
     */
    public function setDefault(Request $request, Playlist $playlist)
    {
        $u = $request->user();

        if ($u->customer_id !== $playlist->customer_id) {
            return response()->json(['message' => 'Out of scope'], 403);
        }

        Playlist::where('customer_id', $u->customer_id)->update(['is_default' => false]);
        $playlist->is_default = true;
        $playlist->save();

        // Forget resolver cache + broadcast to all company screens.
        $this->resolver->forgetCompanyDefault($u->customer_id);
        event(new ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message' => 'Set as default', 'playlist' => $playlist->fresh()]);
    }

    /**
     * POST /user/v1/playlists/{playlist}/refresh
     * Manager-only: recompute content_version and broadcast.
     */
    public function refreshVersion(Request $request, Playlist $playlist)
    {
        $u = $request->user();

        if ($u->customer_id !== $playlist->customer_id) {
            return response()->json(['message' => 'Out of scope'], 403);
        }

        $playlist->refreshVersion();

        // Broadcast to all company screens (explicit + default users).
        event(new ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

        return response()->json(['message' => 'Version refreshed', 'content_version' => $playlist->content_version]);
    }
}
