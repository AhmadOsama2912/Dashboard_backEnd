<?php

namespace App\Http\Controllers\Screen;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentCode;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\ScreenLicense; // <— new
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class ScreenController extends Controller
{
    /**
     * First-time registration:
     * - Validates and redeems the enrollment (claim) code
     * - Creates the screen for the code's customer
     * - Auto-creates a license based on the code's license_days
     * - Returns API token + initial playlist + license info
     */

    public function register(Request $request)
        {
            // 1) Validate input (we'll do uniqueness after we know customer_id)
            $data = $request->validate([
                'serial_number' => ['required', 'string', 'max:190'],
                'device_model'  => ['nullable', 'string', 'max:190'],
                'os_version'    => ['nullable', 'string', 'max:190'],
                'app_version'   => ['nullable', 'string', 'max:190'],
                'claim_code'    => ['required', 'string', 'max:64'],
            ]);

            // 2) Resolve a valid enrollment code
            $code = EnrollmentCode::where('code', $data['claim_code'])
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();

            if (!$code) {
                return response()->json(['message' => 'Invalid or expired claim code'], 422);
            }

            $usedCount = (int) ($code->used_count ?? 0);
            $maxUses   = (int) ($code->max_uses ?? 1);
            if ($usedCount >= $maxUses) {
                return response()->json(['message' => 'Claim code usage limit reached'], 422);
            }

            // 3) Uniqueness per customer (DB unique index: customer_id + serial_number)
            $customerId = $code->customer_id;
            $serial     = $data['serial_number'];

            $exists = Screen::where('customer_id', $customerId)
                ->where('serial_number', $serial)
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Serial number already exists for this customer'], 422);
            }

            // 4) Create the Screen and License (no DB facade)
            $now         = now();
            $licenseDays = (int) ($code->license_days ?? 30);
            $startsAt    = $now->copy();
            $expiresAt   = Carbon::now()->addDays($licenseDays);

            // Create screen (match DB column names; last_check_in_at exists in schema)
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

            // Consume one use of the code
            $code->used_count = $usedCount + 1;
            $code->save();

            // Auto license
            ScreenLicense::create([
                'screen_id'          => $screen->id,
                'enrollment_code_id' => $code->id,
                'starts_at'          => $startsAt,
                'expires_at'         => $expiresAt,
                'status'             => 'active',
            ]);

            // 5) Get default playlist and items (use ->first() to get a model, not a builder)
            $playlist = Playlist::where('customer_id', $screen->customer_id)
                ->where('is_default', 1)
                ->first();

            $items = [];
            if ($playlist) {
                $items = PlaylistItem::where('playlist_id', $playlist->id)
                    ->orderBy('sort')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'type'         => $item->type,
                            'url'          => $item->src,           // DB column is 'src'
                            'duration_sec' => (int) $item->duration // DB column is 'duration'
                        ];
                    })
                    ->toArray();
            }

            // 6) Response
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
                    // alias for clients that expect this name
                    'last_heartbeat_at' => $screen->last_check_in_at,
                ],
                'license' => [
                    'starts_at'  => $startsAt->toIso8601String(),
                    'expires_at' => $expiresAt->toIso8601String(),
                    'days'       => $licenseDays,
                ],
                'playlist' => [
                    'content_version' => $playlist?->content_version ?? '',
                    'updated_at'      => now(),
                    'items'           => $items,
                ],
            ], 201);
        }

    /**
     * Heartbeat:
     * - updates last_heartbeat_at
     * - (optional) returns current license status snapshot for the device
     */
    public function heartbeat(Request $request)
    {
        /** @var \App\Models\Screen $screen */
        $screen = $request->attributes->get('screen');

        $now = now();

        // ✅ write to the real column from your migration/model
        $screen->last_check_in_at = $now;

        // optional: mark activated the first time we see a heartbeat
        if (is_null($screen->activated_at)) {
            $screen->activated_at = $now;
        }

        $screen->save();

        // Latest license snapshot (if any)
        $lic = $screen->licenses()
            ->orderByDesc('expires_at')
            ->first();

        $licensePayload = null;
        if ($lic) {
            $licensePayload = [
                'starts_at'  => optional($lic->starts_at)->toIso8601String(),
                'expires_at' => optional($lic->expires_at)->toIso8601String(),
                'status'     => ($lic->expires_at && $lic->expires_at->isPast()) ? 'expired' : ($lic->status ?? 'active'),
                'days_left'  => $lic->expires_at ? $now->diffInDays($lic->expires_at, false) : null,
            ];
        }

        return response()->json([
            'message'     => 'ok',
            'status'      => $screen->status,   // accessor uses last_check_in_at
            'server_time' => $now,
            'license'     => $licensePayload,
        ]);
    }

    /**
     * Config:
     * - returns playlist snapshot (you can add license info here as well if the player needs it)
     */
    public function config(Request $request)
    {
        /** @var Screen $screen */
        $screen = $request->attributes->get('screen');

        [$version, $items] = $this->playlist($screen);

        return response()->json([
            'content_version' => $version,
            'updated_at'      => now(),
            'poll_after_sec'  => 60,
            'items'           => $items,
        ]);
    }

    /**
     * Demo playlist builder
     */
    protected function playlist(Screen $screen): array
    {
        // Later: replace with your playlists/media tables

        $playlistId = $screen->meta['playlist_id'] ?? null;
        if ($playlistId) {
            $playlist = \App\Models\Playlist::find($playlistId);
            if ($playlist) {
                $items = $playlist->items()->get()->map(function ($item) {
                    return [
                        'type' => $item->type,
                        'url'  => $item->url,
                        'duration_sec' => $item->duration_sec,
                    ];
                })->toArray();
                $version = 'pl-' . $playlist->updated_at->timestamp;

                return [$version, $items];
            }
        }

        // default empty playlist
        return ['pl-empty', []];
    }
}
