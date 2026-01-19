<?php

namespace App\Services;

use App\Models\Screen;
use App\Models\Playlist;
use Illuminate\Support\Facades\Cache;

class PlaylistResolver
{
    /**
     * Priority: explicit screen->playlist_id > company default > null
     */
    public function resolveForScreen(Screen $screen): ?Playlist
    {
        if ($screen->playlist_id) {
            return Playlist::with(['items' => fn($q) => $q->orderBy('sort')])
                ->find($screen->playlist_id);
        }

        return $this->resolveCompanyDefault($screen->customer_id);
    }

    /**
     * Cached company default playlist (with items)
     */
    public function resolveCompanyDefault(?int $customerId): ?Playlist
    {
        if (!$customerId) return null;

        $key = "customer:{$customerId}:default_playlist";

        return Cache::remember($key, 60, function () use ($customerId) {
            return Playlist::with(['items' => fn($q) => $q->orderBy('sort')])
                ->where('customer_id', $customerId)
                ->where('is_default', true)
                ->first();
        });
    }

    /**
     * Forget cached default for a company (call after default changes).
     */
    public function forgetCompanyDefault(int $customerId): void
    {
        Cache::forget("customer:{$customerId}:default_playlist");
    }
}
