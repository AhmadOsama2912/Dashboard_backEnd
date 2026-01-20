<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminPlaylistItemController extends Controller
{
    private const MAX_UPLOAD_KB = 409600; // 400 MB (Laravel "max" for files is KB)

    /** POST /admin/v1/playlists/{playlist}/items (multipart) */
    public function store(Request $request, Playlist $playlist)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['image', 'video'])],
            'file' => array_merge(
                ['required', 'file', 'max:' . self::MAX_UPLOAD_KB],
                $this->fileRulesForType($request->input('type'))
            ),
            // required for images only
            'duration' => ['nullable', 'integer', 'min:1', 'max:3600', 'required_if:type,image'],
            'loops'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $disk   = Storage::disk('public');
        $folder = "media/customer_{$playlist->customer_id}";

        $file     = $request->file('file');
        $filename = Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension());

        // Upload first (so we can clean up if DB fails)
        $path = $disk->putFileAs($folder, $file, $filename);

        try {
            $item = DB::transaction(function () use ($playlist, $validated, $disk, $path) {
                // Prevent sort collisions when multiple uploads happen simultaneously
                $nextSort = (int) (($playlist->items()->lockForUpdate()->max('sort') ?? 0) + 1);

                $absolutePath = $disk->path($path);
                $checksum     = $this->safeMd5($absolutePath); // null if md5 fails (no 500)

                $item = new PlaylistItem();
                $item->playlist_id = $playlist->id;
                $item->type        = $validated['type'];
                $item->src         = $path;        // Always store PATH, not URL
                $item->sort        = $nextSort;
                $item->checksum    = $checksum;    // Keep as raw 32-char MD5 (or null)

                // Important: avoid 500 if DB column duration is NOT NULL by storing 0 for videos
                $item->duration = $validated['type'] === 'image'
                    ? (int) $validated['duration']
                    : (isset($validated['duration']) ? (int) $validated['duration'] : 0);

                // meta (optional column)
                if ($item->isFillable('meta')) {
                    $meta = is_array($item->meta) ? $item->meta : (array) ($item->meta ?? []);
                    if (!empty($validated['loops'])) {
                        $meta['loops'] = (int) $validated['loops'];
                    }
                    $item->meta = $meta;
                }

                $item->save();

                // bump playlist version inside same transaction
                $playlist->refreshVersion();

                return $item->fresh();
            });

            // Notify screens after commit (event/listeners should not break DB transaction)
            DB::afterCommit(function () use ($playlist) {
                $this->notifyCustomer($playlist);
            });

            return response()->json([
                'message' => 'Item added',
                'item'    => $item,
                'url'     => $disk->url($item->src),
            ], 201);

        } catch (\Throwable $e) {
            report($e);

            // Remove orphan file if DB step failed
            if (!empty($path) && $disk->exists($path)) {
                $disk->delete($path);
            }

            return response()->json([
                'message' => 'Failed to add item',
                'error'   => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /** PATCH /admin/v1/playlists/{playlist}/items/{item} */
    public function update(Request $request, Playlist $playlist, PlaylistItem $item)
    {
        if ($item->playlist_id !== $playlist->id) {
            abort(404);
        }

        $validated = $request->validate([
            'duration' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'loops'    => ['nullable', 'integer', 'min:1', 'max:100'],

            // "replace" must match existing item type
            'replace'  => array_merge(
                ['nullable', 'file', 'max:' . self::MAX_UPLOAD_KB],
                $this->fileRulesForType($item->type)
            ),
        ]);

        $disk = Storage::disk('public');

        $oldSrc = $item->src; // keep old path for cleanup if replaced
        $newSrc = null;

        try {
            $updatedItem = DB::transaction(function () use ($request, $playlist, $item, $validated, $disk, &$newSrc) {
                // Replace media file (optional)
                if ($request->hasFile('replace')) {
                    $folder   = "media/customer_{$playlist->customer_id}";
                    $file     = $request->file('replace');
                    $filename = Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension());

                    $newSrc = $disk->putFileAs($folder, $file, $filename);

                    $item->src      = $newSrc; // PATH only
                    $item->checksum = $this->safeMd5($disk->path($newSrc));

                    // If image and duration missing (both new request and existing), block
                    if ($item->type === 'image' && empty($validated['duration']) && empty($item->duration)) {
                        abort(response()->json(['message' => 'Duration is required for images'], 422));
                    }
                }

                // Duration updates
                if ($item->type === 'image') {
                    if (array_key_exists('duration', $validated)) {
                        $item->duration = (int) $validated['duration'];
                    }
                } else {
                    // Optional: allow setting video duration manually (stores 0 if null)
                    if (array_key_exists('duration', $validated)) {
                        $item->duration = $validated['duration'] !== null ? (int) $validated['duration'] : 0;
                    }
                }

                // meta loops
                if ($item->isFillable('meta') && array_key_exists('loops', $validated)) {
                    $meta = is_array($item->meta) ? $item->meta : (array) ($item->meta ?? []);
                    $meta['loops'] = $validated['loops'] !== null ? (int) $validated['loops'] : null;
                    $item->meta = $meta;
                }

                $item->save();

                $playlist->refreshVersion();

                return $item->fresh();
            });

            DB::afterCommit(function () use ($playlist) {
                $this->notifyCustomer($playlist);
            });

            // Cleanup old file only after successful save
            if ($newSrc && $oldSrc && $oldSrc !== $newSrc && $disk->exists($oldSrc)) {
                $disk->delete($oldSrc);
            }

            return response()->json([
                'message' => 'Item updated',
                'item'    => $updatedItem,
                'url'     => $disk->url($updatedItem->src),
            ]);

        } catch (\Throwable $e) {
            report($e);

            // If new file uploaded but DB failed, delete new file to avoid orphans
            if (!empty($newSrc) && $disk->exists($newSrc)) {
                $disk->delete($newSrc);
            }

            return response()->json([
                'message' => 'Failed to update item',
                'error'   => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /** DELETE /admin/v1/playlists/{playlist}/items/{item} */
    public function destroy(Playlist $playlist, PlaylistItem $item)
    {
        if ($item->playlist_id !== $playlist->id) {
            abort(404);
        }

        $disk = Storage::disk('public');
        $src  = $item->src;

        try {
            DB::transaction(function () use ($playlist, $item) {
                $item->delete();
                $playlist->refreshVersion();
            });

            DB::afterCommit(function () use ($playlist) {
                $this->notifyCustomer($playlist);
            });

            // Cleanup media after successful delete
            if (!empty($src) && $disk->exists($src)) {
                $disk->delete($src);
            }

            return response()->json(['message' => 'Item deleted']);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to delete item',
                'error'   => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * PATCH /admin/v1/playlists/{playlist}/items/reorder
     * body: { items: [ {id: X, sort: 1}, ... ] } OR { ids: [id1,id2,...] }
     */
    public function reorder(Request $request, Playlist $playlist)
    {
        $validated = $request->validate([
            'ids'      => ['nullable', 'array'],
            'ids.*'    => ['integer'],
            'items'    => ['nullable', 'array'],
            'items.*.id'   => ['required_with:items', 'integer'],
            'items.*.sort' => ['required_with:items', 'integer', 'min:1'],
        ]);

        try {
            DB::transaction(function () use ($playlist, $validated) {
                if (!empty($validated['items'])) {
                    foreach ($validated['items'] as $row) {
                        PlaylistItem::where('playlist_id', $playlist->id)
                            ->where('id', $row['id'])
                            ->update(['sort' => (int) $row['sort']]);
                    }
                } elseif (!empty($validated['ids'])) {
                    $sort = 1;
                    foreach ($validated['ids'] as $id) {
                        PlaylistItem::where('playlist_id', $playlist->id)
                            ->where('id', $id)
                            ->update(['sort' => $sort++]);
                    }
                }

                $playlist->refreshVersion();
            });

            DB::afterCommit(function () use ($playlist) {
                $this->notifyCustomer($playlist);
            });

            return response()->json(['message' => 'Order updated']);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to reorder items',
                'error'   => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /** File validation rules depending on type */
    private function fileRulesForType(?string $type): array
    {
        $type = $type ?: 'video';

        if ($type === 'image') {
            return [
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp,application/octet-stream',
            ];
        }

        return [
            'mimes:mp4,mov,m4v,webm',
            'mimetypes:video/mp4,video/quicktime,video/x-m4v,video/webm,application/octet-stream',
        ];
    }

    /** Safe MD5 that never throws and never triggers a 500 by itself */
    private function safeMd5(string $absolutePath): ?string
    {
        // Suppress warnings and return null if hashing fails
        $hash = @hash_file('md5', $absolutePath);
        return $hash !== false ? $hash : null;
    }

    /** Centralized customer notify */
    private function notifyCustomer(Playlist $playlist): void
    {
        event(new \App\Events\ScreenConfigUpdated(
            $playlist->customer_id,
            0,
            $playlist->content_version
        ));
    }
}
