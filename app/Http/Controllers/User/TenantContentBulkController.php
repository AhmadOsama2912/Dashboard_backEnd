<?php
// app/Http/Controllers/User/TenantContentBulkController.php

namespace App\Http\Controllers\User;

use App\Events\ScreenConfigUpdated;
use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantContentBulkController extends Controller
{
    /**
     * Manager only: assign playlist to ALL company screens.
     * Stores playlist_id inside screens.meta as meta.playlist_id (NO screens.playlist_id column).
     */
    public function assignPlaylistToCompanyScreens(Request $request): JsonResponse
    {
        /** @var User $u */
        $u = $request->user();

        if (!$u || $u->role !== 'manager') {
            return response()->json(['message' => 'Only managers'], 403);
        }

        $data = $request->validate([
            'playlist_id' => ['nullable', 'integer', 'exists:playlists,id'],
        ]);

        [$playlistId, $contentVersion] = $this->validatePlaylistScope($u, $data['playlist_id'] ?? null);

        $count = $this->applyMetaPlaylistToScope(
            Screen::query()->where('customer_id', (int) $u->customer_id),
            $playlistId
        );

        // simple way
        event(new ScreenConfigUpdated((int) $u->customer_id, null, $contentVersion));

        return response()->json([
            'message'     => 'Assigned to all company screens (meta.playlist_id)',
            'count'       => $count,
            'playlist_id' => $playlistId,
        ]);
    }

    /**
     * Manager: any company screens
     * Supervisor: ONLY assigned screens
     *
     * Body:
     *  - { "all": true, "playlist_id": 12 } OR
     *  - { "screen_ids": [1,2,3], "playlist_id": 12 } OR
     *  - playlist_id can be null to unset meta.playlist_id (follow company default)
     */
    public function assignPlaylistToScreens(Request $request): JsonResponse
    {
        /** @var User $u */
        $u = $request->user();

        if (!$u) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'all'          => ['sometimes', 'boolean'],
            'screen_ids'   => ['sometimes', 'array', 'min:1'],
            'screen_ids.*' => ['integer', 'exists:screens,id'],
            'playlist_id'  => ['nullable', 'integer', 'exists:playlists,id'],
        ]);

        // Abilities checks (only if your User model has hasAbility())
        if ($u->role === 'supervisor' && method_exists($u, 'hasAbility')) {
            if (!$u->hasAbility('content:change')) {
                return response()->json(['message' => 'Missing ability: content:change'], 403);
            }

            $isBulk = !empty($data['all']) || (isset($data['screen_ids']) && count($data['screen_ids']) > 1);
            if ($isBulk && !$u->hasAbility('bulk:send')) {
                return response()->json(['message' => 'Missing ability: bulk:send'], 403);
            }
        }

        [$playlistId, $contentVersion] = $this->validatePlaylistScope($u, $data['playlist_id'] ?? null);

        // Base scope (company) + supervisor limitation
        $base = Screen::query()->where('customer_id', (int) $u->customer_id);
        if ($u->role === 'supervisor') {
            $base->where('assigned_user_id', (int) $u->id);
        }

        // all=true mode
        if (!empty($data['all'])) {
            $count = $this->applyMetaPlaylistToScope($base, $playlistId);

            // simple way: one broadcast for company (front-end can refresh)
            event(new ScreenConfigUpdated((int) $u->customer_id, null, $contentVersion));

            return response()->json([
                'message'     => 'Assigned to ALL screens in scope (meta.playlist_id)',
                'count'       => $count,
                'playlist_id' => $playlistId,
            ]);
        }

        // screen_ids mode
        if (empty($data['screen_ids'])) {
            return response()->json(['message' => 'Provide screen_ids[] or set all=true'], 422);
        }

        $ids = collect($data['screen_ids'])
            ->map(fn ($x) => (int) $x)
            ->unique()
            ->values();

        // IMPORTANT: fetch only screens IN SCOPE
        $screens = (clone $base)->whereIn('id', $ids)->get(['id', 'meta']);

        if ($screens->count() !== $ids->count()) {
            return response()->json(['message' => 'One or more screens are out of scope'], 403);
        }

        $count = 0;
        foreach ($screens as $s) {
            $this->applyMetaPlaylistToScreen($s, $playlistId);
            $count++;

            // simple way: per-screen notify (optional; keep it if your WS relies on screen_id)
            event(new ScreenConfigUpdated((int) $u->customer_id, (int) $s->id, $contentVersion));
        }

        return response()->json([
            'message'     => 'Assigned to selected screens (meta.playlist_id)',
            'count'       => $count,
            'playlist_id' => $playlistId,
        ]);
    }

    /**
     * Manager only: broadcast to company
     */
    public function broadcastCompanyConfig(Request $request): JsonResponse
    {
        /** @var User $u */
        $u = $request->user();

        if (!$u || $u->role !== 'manager') {
            return response()->json(['message' => 'Only managers'], 403);
        }

        event(new ScreenConfigUpdated((int) $u->customer_id, null, ''));

        return response()->json(['message' => 'Broadcast sent']);
    }

    /**
     * Manager/Supervisor: broadcast to selected or all-in-scope
     */
    public function broadcastScreensConfig(Request $request): JsonResponse
    {
        /** @var User $u */
        $u = $request->user();

        if (!$u) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'all'          => ['sometimes', 'boolean'],
            'screen_ids'   => ['sometimes', 'array', 'min:1'],
            'screen_ids.*' => ['integer', 'exists:screens,id'],
        ]);

        // Abilities checks (optional)
        if ($u->role === 'supervisor' && method_exists($u, 'hasAbility')) {
            $isBulk = !empty($data['all']) || (isset($data['screen_ids']) && count($data['screen_ids']) > 1);
            if ($isBulk && !$u->hasAbility('bulk:send')) {
                return response()->json(['message' => 'Missing ability: bulk:send'], 403);
            }
        }

        $base = Screen::query()->where('customer_id', (int) $u->customer_id);
        if ($u->role === 'supervisor') {
            $base->where('assigned_user_id', (int) $u->id);
        }

        // all=true -> one company broadcast
        if (!empty($data['all'])) {
            event(new ScreenConfigUpdated((int) $u->customer_id, null, ''));
            return response()->json(['message' => 'Broadcast sent', 'count' => null]);
        }

        if (empty($data['screen_ids'])) {
            return response()->json(['message' => 'Provide screen_ids[] or set all=true'], 422);
        }

        $ids = collect($data['screen_ids'])->map(fn ($x) => (int) $x)->unique()->values();
        $screens = (clone $base)->whereIn('id', $ids)->pluck('id');

        if ($screens->count() !== $ids->count()) {
            return response()->json(['message' => 'One or more screens are out of scope'], 403);
        }

        foreach ($screens as $sid) {
            event(new ScreenConfigUpdated((int) $u->customer_id, (int) $sid, ''));
        }

        return response()->json(['message' => 'Broadcast sent', 'count' => $screens->count()]);
    }

    /* ========================= Helpers ========================= */

    /**
     * Validates playlist belongs to user company.
     * Returns: [playlistId|null, contentVersion]
     */
    private function validatePlaylistScope(User $u, ?int $playlistId): array
    {
        if (empty($playlistId)) {
            return [null, ''];
        }

        $pl = Playlist::query()->findOrFail((int) $playlistId);

        if ((int) $pl->customer_id !== (int) $u->customer_id) {
            abort(response()->json(['message' => 'Playlist not in your company'], 422));
        }

        return [(int) $pl->id, (string) ($pl->content_version ?? '')];
    }

    /**
     * Apply meta.playlist_id to a query scope (chunked).
     * Returns affected count (approx exact, using increment).
     */
    private function applyMetaPlaylistToScope($query, ?int $playlistId): int
    {
        $count = 0;

        $query->select(['id', 'meta'])
            ->orderBy('id')
            ->chunkById(200, function ($screens) use ($playlistId, &$count) {
                foreach ($screens as $s) {
                    $this->applyMetaPlaylistToScreen($s, $playlistId);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Apply meta.playlist_id to a single screen model and save.
     */
    private function applyMetaPlaylistToScreen(Screen $screen, ?int $playlistId): void
    {
        $meta = is_array($screen->meta) ? $screen->meta : [];

        if (!empty($playlistId)) {
            $meta['playlist_id'] = (int) $playlistId;
        } else {
            unset($meta['playlist_id']);
        }

        $screen->meta = $meta;
        $screen->save();
    }
}
