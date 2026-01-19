<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;


class CompanyPlaylistItemController extends Controller
{
    // Add item to a company playlist
	public function store(Request $request, Playlist $playlist)
	{
		$u = $request->user();

		if ($u->role !== 'manager') {
			return response()->json(['message' => 'Only managers'], 403);
		}

		if ((int) $u->customer_id !== (int) $playlist->customer_id) {
			return response()->json(['message' => 'Out of scope'], 403);
		}

		$type = $request->input('type');

		// Validate type first
		$request->validate([
			'type' => ['required', Rule::in(['image', 'video', 'web'])],
		]);

		// WEB: URL only
		if ($type === 'web') {
			$data = $request->validate([
				'src'      => ['required', 'string', 'max:2048'],
				'duration' => ['required', 'integer', 'min:1', 'max:36000'],
				'sort'     => ['nullable', 'integer', 'min:0'],
				'checksum' => ['nullable', 'string', 'max:100'],
				'meta'     => ['nullable', 'array'],
			]);

			$item = $playlist->items()->create([
				'type'     => 'web',
				'src'      => $data['src'],
				'duration' => (int) $data['duration'],
				'sort'     => $data['sort'] ?? (int) (($playlist->items()->max('sort') ?? 0) + 1),
				'checksum' => $data['checksum'] ?? null,
				'meta'     => $data['meta'] ?? null,
			]);

			$playlist->refreshVersion();
			event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

			return response()->json(['message' => 'Item created', 'item' => $item], 201);
		}

		// IMAGE/VIDEO: upload via "file"
		$data = $request->validate([
			'type'     => ['required', Rule::in(['image','video'])],
			'file'     => ['required', 'file', 'mimes:jpg,jpeg,png,webp,mp4,mov,m4v,webm', 'max:409600'], // 400MB (KB)
			'duration' => ['nullable', 'integer', 'min:0', 'max:3600'], // Option A: allow 0
			'loops'    => ['nullable', 'integer', 'min:1', 'max:100'],
			'sort'     => ['nullable', 'integer', 'min:0'],
			'meta'     => ['nullable', 'array'],
		]);

		// Extra safety (prevents random 500 if upload fails at PHP level)
		if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
			return response()->json(['message' => 'Upload failed'], 422);
		}

		// Duration required for images only
		if ($data['type'] === 'image' && empty($data['duration'])) {
			return response()->json(['message' => 'Duration is required for images'], 422);
		}

		$folder = 'media/customer_' . $playlist->customer_id;
		$path   = $request->file('file')->store($folder, 'public');

		$hash   = 'md5:' . md5_file(Storage::disk('public')->path($path));

		$meta = $data['meta'] ?? [];
		if (!empty($data['loops'])) {
			$meta['loops'] = (int) $data['loops'];
		}

		$item = $playlist->items()->create([
			'type'     => $data['type'],
			'src'      => $path,
			// Option A: store 0 for videos instead of null (avoids NOT NULL DB errors)
			'duration' => $data['type'] === 'image' ? (int) $data['duration'] : 0,
			'checksum' => $hash,
			'sort'     => $data['sort'] ?? (int) (($playlist->items()->max('sort') ?? 0) + 1),
			'meta'     => !empty($meta) ? $meta : null,
		]);

		$playlist->refreshVersion();
		event(new \App\Events\ScreenConfigUpdated($u->customer_id, null, $playlist->content_version ?? ''));

		return response()->json(['message' => 'Item added', 'item' => $item], 201);
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
