<?php

namespace App\Http\Controllers\Screen;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentCode;
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
        $data = $request->validate([
            'serial_number' => ['required','string','max:190','unique:screens,serial_number'],
            'device_model'  => ['nullable','string','max:190'],
            'os_version'    => ['nullable','string','max:190'],
            'app_version'   => ['nullable','string','max:190'],
            'claim_code'    => ['required','string','max:64'],
        ]);

        // Find a valid enrollment code (not expired, not over-used)
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

        // Double safety – ensure no duplicate serial exists
        if (Screen::where('serial_number', $data['serial_number'])->exists()) {
            return response()->json(['message' => 'Serial number already exists'], 422);
        }

        // Create screen under the code's customer
        $screen = new Screen([
            'customer_id'       => $code->customer_id,
            'serial_number'     => $data['serial_number'],
            'device_model'      => $data['device_model'] ?? null,
            'os_version'        => $data['os_version'] ?? null,
            'app_version'       => $data['app_version'] ?? null,
            'activated_at'      => now(),
            'last_heartbeat_at' => now(),         // <— use last_heartbeat_at (admin expects this)
            'access_scope'      => 'company',
            'api_token'         => Str::random(64),
        ]);
        $screen->save();

        // Increment code usage
        $code->increment('used_count');

        // ===== AUTO LICENSE from code =====
        $licenseDays = (int) ($code->license_days ?? 30); // default 30 days if not configured
        $startsAt    = now();
        $expiresAt   = Carbon::now()->addDays($licenseDays);

        ScreenLicense::create([
            'screen_id'          => $screen->id,
            'enrollment_code_id' => $code->id,
            'starts_at'          => $startsAt,
            'expires_at'         => $expiresAt,
            'status'             => 'active',
        ]);

        // Prepare playlist payload (demo)
        [$version, $items] = $this->playlist($screen);

        return response()->json([
            'token'  => $screen->api_token,
            'screen' => [
                'id'                 => $screen->id,
                'customer_id'        => $screen->customer_id,
                'serial_number'      => $screen->serial_number,
                'status'             => $screen->status,
                'access_scope'       => $screen->access_scope,
                'assigned_user_id'   => $screen->assigned_user_id,
                'activated_at'       => $screen->activated_at,
                'last_heartbeat_at'  => $screen->last_heartbeat_at,
            ],
            'license' => [
                'starts_at'  => $startsAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
                'days'       => $licenseDays,
            ],
            'playlist' => [
                'content_version' => $version,
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
        /** @var Screen $screen */
        $screen = $request->attributes->get('screen');

        $screen->last_heartbeat_at = now(); // <— standardize on last_heartbeat_at
        $screen->save();

        // Latest license snapshot (if any)
        $license = $screen->licenses()
            ->orderByDesc('expires_at')
            ->first();

        $licensePayload = null;
        if ($license) {
            $licensePayload = [
                'expires_at' => optional($license->expires_at)->toIso8601String(),
                'status'     => $license->expires_at && $license->expires_at->isPast() ? 'expired' : 'active',
                'days_left'  => $license->expires_at ? now()->diffInDays($license->expires_at, false) : null,
            ];
        }

        return response()->json([
            'message'     => 'ok',
            'status'      => $screen->status,
            'server_time' => now(),
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
        $items = [
            [
                'id'       => 'img-1',
                'type'     => 'image',
                'url'      => url('/storage/media/customer_'.$screen->customer_id.'/hero.jpg'),
                'duration' => 10,
                'checksum' => 'md5:abc',
            ],
            [
                'id'       => 'vid-7',
                'type'     => 'video',
                'url'      => url('/storage/media/customer_'.$screen->customer_id.'/ad7.mp4'),
                'duration' => 30,
                'checksum' => 'md5:def',
            ],
        ];
        $version = 'sha256:'.hash('sha256', json_encode($items));

        return [$version, $items];
    }
}
