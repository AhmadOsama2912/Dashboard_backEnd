<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Screen;            // adjust namespace if your model lives elsewhere
use Illuminate\Support\Carbon;

class AdminScreenController extends Controller
{
    /**
     * GET /api/admin/v1/screens
     *
     * Query params (all optional):
     *  - q: string (search name/serial)
     *  - status: online|offline
     *  - customer_id: int
     *  - page, per_page: pagination (per_page max 100)
     */
public function index(Request $request)
    {
        $perPage = min((int)($request->input('per_page', 20)), 100);
        $onlineWindowMinutes = 5; // consider online if heartbeat within last 5 minutes

        $q = Screen::query()
            ->with(['customer:id,name', 'licenses' => function($q) {
                $q->orderByDesc('expires_at')->limit(1);
            }]);

        if ($cid = $request->input('customer_id')) {
            $q->where('customer_id', $cid);
        }

        if ($serial = $request->input('serial')) {
            $q->where('serial_number', 'LIKE', "%{$serial}%");
        }

        if ($request->filled('online')) {
            $cutoff = now()->subMinutes($onlineWindowMinutes);
            if ((string)$request->input('online') === '1') {
                $q->where('last_heartbeat_at', '>=', $cutoff);
            } else {
                $q->where(function ($qq) use ($cutoff) {
                    $qq->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', $cutoff);
                });
            }
        }

        if ($from = $request->input('registered_from')) {
            $q->whereDate('activated_at', '>=', $from);
        }
        if ($to = $request->input('registered_to')) {
            $q->whereDate('activated_at', '<=', $to);
        }

        if ($lsFrom = $request->input('last_seen_from')) {
            $q->whereDate('last_heartbeat_at', '>=', $lsFrom);
        }
        if ($lsTo = $request->input('last_seen_to')) {
            $q->whereDate('last_heartbeat_at', '<=', $lsTo);
        }

        $paginator = $q->orderByDesc('id')->paginate($perPage);

        $cutoff = now()->subMinutes($onlineWindowMinutes);

        $data = collect($paginator->items())->map(function (Screen $s) use ($cutoff) {
            $license = $s->licenses->first();
            $isOnline = $s->last_heartbeat_at && $s->last_heartbeat_at >= $cutoff;

            return [
                'id'                => $s->id,
                'customer_id'       => $s->customer_id,
                'customer_name'     => optional($s->customer)->name,
                'serial_number'     => $s->serial_number,
                'device_model'      => $s->device_model,
                'os_version'        => $s->os_version,
                'app_version'       => $s->app_version,
                'status'            => $s->status,
                'activated_at'      => $s->activated_at,
                'last_heartbeat_at' => $s->last_heartbeat_at,
                'is_online'         => $isOnline,
                'license'           => $license ? [
                    'starts_at'  => $license->starts_at,
                    'expires_at' => $license->expires_at,
                    'status'     => ($license->expires_at && $license->expires_at->isPast()) ? 'expired' : 'active',
                ] : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'online_window_minutes' => $onlineWindowMinutes,
            ]
        ]);
    }


    /**
     * GET /api/admin/v1/screens/{screen}
     */
    public function show(Screen $screen)
    {
        $screen->loadMissing(['customer:id,name', 'playlist:id,name']);

        return response()->json([
            'id'                 => $screen->id,
            'name'               => $screen->name,
            'serial'             => $screen->serial,
            'customer_id'        => $screen->customer_id,
            'customer_name'      => optional($screen->customer)->name,
            'playlist_id'        => $screen->playlist_id,
            'playlist_name'      => optional($screen->playlist)->name,
            'last_heartbeat_at'  => optional($screen->last_heartbeat_at)->toIso8601String(),
            'screenshot_url'     => $screen->screenshot_url ?? null,
            'created_at'         => optional($screen->created_at)->toIso8601String(),
            'updated_at'         => optional($screen->updated_at)->toIso8601String(),
        ]);
    }
}
