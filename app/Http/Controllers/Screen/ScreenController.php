<?php

namespace App\Http\Controllers\Screen;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentCode;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\ScreenLicense;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class ScreenController extends Controller
{
    /**
     * First-time registration
     * - Validates claim code
     * - Creates Screen + initial License
     * - Returns api_token + initial playlist snapshot
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:190'],
            'device_model'  => ['nullable', 'string', 'max:190'],
            'os_version'    => ['nullable', 'string', 'max:190'],
            'app_version'   => ['nullable', 'string', 'max:190'],
            'claim_code'    => ['required', 'string', 'max:64'],
        ]);

        // Resolve enrollment code
        $code = EnrollmentCode::where('code', $data['claim_code'])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$code) {
            return response()->json(['message' => 'Invalid or expired claim code'], 422);
        }

        // Usage limits
        $usedCount = (int) ($code->used_count ?? 0);
        $maxUses   = (int) ($code->max_uses ?? 1);
        if ($usedCount >= $maxUses) {
            return response()->json(['message' => 'Claim code usage limit reached'], 422);
        }

        // Uniqueness per customer (customer_id + serial_number)
        $customerId = (int) $code->customer_id;
        $serial     = $data['serial_number'];

        $exists = Screen::where('customer_id', $customerId)
            ->where('serial_number', $serial)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Serial number already exists for this customer'], 422);
        }

        // Create screen + license
        $now         = now();
        $licenseDays = (int) ($code->license_days ?? 30);
        $startsAt    = $now->copy();
        $expiresAt   = Carbon::now()->addDays($licenseDays);

        $screen = Screen::create([
            'customer_id'      => $customerId,
            'serial_number'    => $serial,
            'device_model'     => $data['device_model'] ?? null,
            'os_version'       => $data['os_version'] ?? null,
            'app_version'      => $data['app_version'] ?? null,
            'activated_at'     => $now,
            'last_check_in_at' => $now,
            'access_scope'     => 'company',
            'api_token'        => Str::random(64),
        ]);

        // Consume one use
        $code->used_count = $usedCount + 1;
        $code->save();

        // Initial license
        ScreenLicense::create([
            'screen_id'          => $screen->id,
            'enrollment_code_id' => $code->id,
            'starts_at'          => $startsAt,
            'expires_at'         => $expiresAt,
            'status'             => 'active',
        ]);

        // Build initial playlist snapshot
        [$contentVersion, $items] = $this->buildPlaylistForScreen($screen);

        return response()->json([
            'token'  => $screen->api_token,
            'screen' => [
                'id'                => $screen->id,
                'customer_id'       => $screen->customer_id,
                'serial_number'     => $screen->serial_number,
                'status'            => $screen->status,
                'access_scope'      => $screen->access_scope,
                'assigned_user_id'  => $screen->assigned_user_id,
                'activated_at'      => $screen->activated_at,
                'last_check_in_at'  => $screen->last_check_in_at,
                'last_heartbeat_at' => $screen->last_check_in_at,
            ],
            'license' => [
                'starts_at'  => $startsAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
                'days'       => $licenseDays,
            ],
            'playlist' => [
                'content_version' => $contentVersion,
                'updated_at'      => now()->toIso8601String(),
                'items'           => $items,
            ],
        ], 201);
    }

    /**
     * Heartbeat: update last_check_in_at and return license snapshot
     */
    public function heartbeat(Request $request)
    {
        /** @var Screen $screen */
        $screen = $request->attributes->get('screen');

        $now = now();
        $screen->last_check_in_at = $now;

        if (is_null($screen->activated_at)) {
            $screen->activated_at = $now;
        }

        $screen->save();

        $lic = $screen->licenses()->orderByDesc('expires_at')->first();

        $licensePayload = null;
        if ($lic) {
            $licensePayload = [
                'starts_at'  => optional($lic->starts_at)->toIso8601String(),
                'expires_at' => optional($lic->expires_at)->toIso8601String(),
                'status'     => ($lic->expires_at && $lic->expires_at->isPast())
                    ? 'expired'
                    : ($lic->status ?? 'active'),
                'days_left'  => $lic->expires_at ? $now->diffInDays($lic->expires_at, false) : null,
            ];
        }

        return response()->json([
            'message'     => 'ok',
            'status'      => $screen->status,
            'server_time' => $now->toIso8601String(),
            'license'     => $licensePayload,
        ]);
    }

    /**
     * Config snapshot for the device (includes version + items)
     */
    public function config(Request $request)
    {
        // Prefer the middleware-injected screen (screen.auth). Fallback to header.
        /** @var \App\Models\Screen|null $screen */
        $screen = $request->attributes->get('screen');
        if (!$screen) {
            $token = $request->header('X-Screen-Token') ?? $request->header('x-screen-token');
            if ($token) {
                $screen = Screen::where('api_token', $token)->first();
            }
        }
        if (!$screen) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // ---- Resolve playlist for this screen ----
        // 1) explicit assignment on screen
        $playlist = null;
        if (!empty($screen->playlist_id)) {
            $playlist = Playlist::find($screen->playlist_id);
        }

        // 2) meta.playlist_id (if meta is JSON)
        if (!$playlist) {
            $meta = $screen->meta;
            if (is_string($meta)) {
                $meta = json_decode($meta, true) ?: [];
            }
            if (is_array($meta) && !empty($meta['playlist_id'])) {
                $playlist = Playlist::find((int) $meta['playlist_id']);
            }
        }

        // 3) company default playlist
        if (!$playlist) {
            $playlist = Playlist::where('customer_id', $screen->customer_id)
                ->where('is_default', 1)
                ->first();
        }

        // ---- Build response ----
        $contentVersion = '';
        $updatedAt      = now();
        $items          = [];

        if ($playlist) {
            $contentVersion = (string) ($playlist->content_version ?? '');
            $updatedAt      = $playlist->updated_at ?? now();

            // Items belonging to this playlist
            $items = PlaylistItem::where('playlist_id', $playlist->id)
                ->orderBy('sort')
                ->get()
                ->map(function (PlaylistItem $it) {
                    return [
                        'type'         => $it->type,
                        'url'          => $it->src,                 // DB column is 'src'
                        'duration_sec' => (int) ($it->duration ?? 10), // DB column is 'duration'
                    ];
                })
                ->toArray();
        }

        return response()->json([
            'content_version' => $contentVersion,
            'updated_at'      => $updatedAt->toIso8601String(),
            'poll_after_sec'  => 60,
            'items'           => $items,
        ]);
    }


    /**
     * Public JSON for current playlist (used by TV app on bump)
     */
    public function playlistJson(Request $request)
    {
        /** @var Screen $screen */
        $screen = $request->attributes->get('screen');

        [$version, $items] = $this->buildPlaylistForScreen($screen);

        return response()->json([
            'content_version' => $version,
            'updated_at'      => now()->toIso8601String(),
            'items'           => $items,
        ]);
    }

    /**
     * Build playlist for a screen:
     * - Prefer screen->playlist (if لديك علاقة مباشرة على الموديل)
     * - Otherwise fallback to customer's default playlist (is_default = 1)
     * - Map items to {type, url, duration_sec} and compute content_version
     */
    protected function buildPlaylistForScreen(Screen $screen): array
    {
        // 1) If screen has a direct assigned playlist relation, use it
        $playlist = null;

        if (method_exists($screen, 'playlist')) {
            $playlist = $screen->playlist()->with('items')->first();
        }

        // 2) Fallback to customer's default playlist
        if (!$playlist) {
            $playlist = Playlist::where('customer_id', $screen->customer_id)
                ->where('is_default', 1)
                ->with('items')
                ->first();
        }

        // 3) If still none -> empty
        if (!$playlist) {
            return ['pl-empty', []];
        }

        // 4) Build items (support both schemas: (src,duration) OR (url,duration_sec))
        $items = ($playlist->items ?? collect())
            ->sortBy('sort')
            ->map(function ($item) {
                $url = $item->src ?? $item->url ?? '';
                $dur = isset($item->duration_sec)
                    ? (int) $item->duration_sec
                    : (int) ($item->duration ?? 0);

                return [
                    'type'         => (string) $item->type,
                    'url'          => (string) $url,
                    'duration_sec' => max(0, $dur),
                ];
            })
            ->values()
            ->toArray();

        // 5) Determine content_version
        $version = $playlist->content_version
            ?? ('pl-' . ($playlist->updated_at?->timestamp ?? time()));

        return [$version, $items];
    }
}
