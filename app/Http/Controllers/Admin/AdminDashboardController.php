<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Playlist;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDashboardController extends Controller
{
    /**
     * GET /admin/v1/dashboard/summary
     */
    public function summary(Request $request)
    {
        $minutes   = (int) config('screens.online_grace_minutes', 5);
        $threshold = now()->subMinutes($minutes);

        $data = Cache::remember('admin.dashboard.summary', 30, function () use ($threshold, $minutes) {

            // Screens — use last_check_in_at (per your Screen model)
            $totalScreens   = Screen::count();
            $onlineScreens  = Screen::where('last_check_in_at', '>=', $threshold)->count();
            $offlineScreens = max($totalScreens - $onlineScreens, 0);

            // With/without playlist only if the column exists
            $withPlaylist = Schema::hasColumn('screens', 'playlist_id')
                ? Screen::whereNotNull('playlist_id')->count()
                : 0;
            $withoutPlaylist = max($totalScreens - $withPlaylist, 0);

            // Customers & users
            $totalCustomers = Customer::count();
            $managers       = User::where('role', 'manager')->count();
            $supervisors    = User::where('role', 'supervisor')->count();

            // Published playlists (guard table/column)
            $publishedPlaylists = (Schema::hasTable('playlists') && Schema::hasColumn('playlists', 'published_at'))
                ? Playlist::whereNotNull('published_at')->count()
                : 0;

            return [
                'totals' => [
                    'screens'   => $totalScreens,
                    'customers' => $totalCustomers,
                    'playlists_published' => $publishedPlaylists,
                    'users' => [
                        'managers'    => $managers,
                        'supervisors' => $supervisors,
                    ],
                ],
                'screens' => [
                    'online'                    => $onlineScreens,
                    'offline'                   => $offlineScreens,
                    'with_playlist'             => $withPlaylist,
                    'without_playlist'          => $withoutPlaylist,
                    'online_threshold_minutes'  => $minutes,
                ],
                'refreshed_at' => now()->toIso8601String(),
            ];
        });

        return response()->json($data);
    }

    /**
     * GET /admin/v1/dashboard/screens
     * Query: q, customer_id, online, sort_by, sort_dir, per_page
     * sort_by: last_check_in_at|created_at|serial_number
     */
    public function screens(Request $request)
    {
        $data = $request->validate([
            'q'           => ['nullable','string','max:190'],
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'online'      => ['nullable','in:true,false,1,0'],
            'sort_by'     => ['nullable','in:last_check_in_at,created_at,serial_number'],
            'sort_dir'    => ['nullable','in:asc,desc'],
            'per_page'    => ['nullable','integer','min:1','max:100'],
        ]);

        $minutes   = (int) config('screens.online_grace_minutes', 5);
        $threshold = now()->subMinutes($minutes);

        // Select only columns that exist in your migration/model
        $cols = [
            'id', 'customer_id', 'serial_number',
            'device_model', 'os_version', 'app_version',
            'activated_at', 'last_check_in_at', 'created_at',
        ];
        if (Schema::hasColumn('screens', 'playlist_id')) {
            $cols[] = 'playlist_id';
        }

        $q = Screen::query()
            ->with(['customer:id,name'])
            ->select($cols);

        if (!empty($data['q'])) {
            $term = '%'.$data['q'].'%';
            $q->where(function ($w) use ($term) {
                $w->where('serial_number', 'like', $term)
                  ->orWhere('device_model', 'like', $term)
                  ->orWhere('os_version', 'like', $term)
                  ->orWhere('app_version', 'like', $term);
            });
        }
        if (!empty($data['customer_id'])) {
            $q->where('customer_id', $data['customer_id']);
        }
        if (isset($data['online'])) {
            $wantOnline = in_array($data['online'], ['true','1',1,true], true);
            $q->where('last_check_in_at', $wantOnline ? '>=' : '<', $threshold);
        }

        $sortBy  = $data['sort_by']  ?? 'last_check_in_at';
        $sortDir = $data['sort_dir'] ?? 'desc';
        $q->orderBy($sortBy, $sortDir);

        $paginator = $q->paginate($data['per_page'] ?? 10);

        $paginator->getCollection()->transform(function (Screen $s) use ($threshold) {
            return [
                'id'                => $s->id,
                'customer_id'       => $s->customer_id,
                'customer_name'     => optional($s->customer)->name,
                'serial_number'     => $s->serial_number,
                'device_model'      => $s->device_model,
                'os_version'        => $s->os_version,
                'app_version'       => $s->app_version,
                'playlist_id'       => Schema::hasColumn('screens', 'playlist_id') ? $s->playlist_id : null,
                'activated_at'      => optional($s->activated_at)->toIso8601String(),
                'last_check_in_at'  => optional($s->last_check_in_at)->toIso8601String(),
                'created_at'        => optional($s->created_at)->toIso8601String(),
                // status is an accessor on the model, not a DB column:
                'status'            => $s->status,
                'online'            => $s->last_check_in_at && $s->last_check_in_at->gte($threshold),
            ];
        });

        return response()->json($paginator);
    }

    /**
     * GET /admin/v1/dashboard/customers
     * Query: q, per_page
     */
    public function customers(Request $request)
    {
        $data = $request->validate([
            'q'        => ['nullable','string','max:190'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $minutes   = (int) config('screens.online_grace_minutes', 5);
        $threshold = now()->subMinutes($minutes);

        $q = Customer::query()
            ->withCount([
                'screens',
                'users',
                // Count online screens using last_check_in_at
                'screens as screens_online_count' => function ($qq) use ($threshold) {
                    $qq->where('last_check_in_at', '>=', $threshold);
                },
            ]);

        if (method_exists(Customer::class, 'playlists') && Schema::hasTable('playlists')) {
            $q->withCount('playlists');
        }

        if (!empty($data['q'])) {
            $term = '%'.$data['q'].'%';
            $q->where('name', 'like', $term);
        }

        $paginator = $q->orderBy('created_at', 'desc')
            ->paginate($data['per_page'] ?? 100);

        $paginator->getCollection()->transform(function (Customer $c) {
            $offline = max(($c->screens_count ?? 0) - ($c->screens_online_count ?? 0), 0);
            return [
                'id'                    => $c->id,
                'name'                  => $c->name,
                'package_id'            => $c->package_id,
                'screens_count'         => (int) ($c->screens_count ?? 0),
                'screens_online_count'  => (int) ($c->screens_online_count ?? 0),
                'screens_offline_count' => (int) $offline,
                'users_count'           => (int) ($c->users_count ?? 0),
                'playlists_count'       => (int) ($c->playlists_count ?? 0),
                'created_at'            => optional($c->created_at)->toIso8601String(),
            ];
        });

        return response()->json($paginator);
    }

    /**
     * GET /admin/v1/dashboard/metrics?days=14
     */
    public function metrics(Request $request)
    {
        $days = (int) $request->query('days', 14);
        $days = max(1, min($days, 60));
        $from = now()->startOfDay()->subDays($days - 1);
        $to   = now()->endOfDay();

        $period = collect();
        for ($d = 0; $d < $days; $d++) {
            $period->push($from->copy()->addDays($d)->format('Y-m-d'));
        }

        $newScreens = Screen::whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $newCustomers = Customer::whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        // Count heartbeats per day using last_check_in_at
        $online = Screen::whereBetween('last_check_in_at', [$from, $to])
            ->selectRaw('DATE(last_check_in_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        return response()->json([
            'days'          => $period,
            'new_screens'   => $period->map(fn($d) => (int) ($newScreens[$d] ?? 0)),
            'new_customers' => $period->map(fn($d) => (int) ($newCustomers[$d] ?? 0)),
            'online'        => $period->map(fn($d) => (int) ($online[$d] ?? 0)),
        ]);
    }

    /**
     * GET /admin/v1/dashboard/licenses-expiring?days=30&per_page=100
     */
    public function licensesExpiring(Request $request)
    {
        $days = (int) $request->query('days', 30);
        $per  = (int) $request->query('per_page', 100);
        $days = max(1, min($days, 365));
        $per  = max(1, min($per, 100));

        $until = now()->addDays($days);

        if (Schema::hasTable('screen_licenses')) {
            $query = DB::table('screen_licenses as sl')
                ->leftJoin('screens as s', 's.id', '=', 'sl.screen_id')
                ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
                ->whereNotNull('sl.expires_at')
                ->where('sl.expires_at', '<=', $until)
                ->orderBy('sl.expires_at', 'asc')
                ->select([
                    'sl.id', 'sl.expires_at',
                    's.serial_number',
                    'c.id as customer_id',
                    'c.name as customer_name',
                ]);
            return response()->json($query->paginate($per));
        }

        // Fallback: enrollment codes
        $query = DB::table('enrollment_codes as ec')
            ->leftJoin('customers as c', 'c.id', '=', 'ec.customer_id')
            ->whereNotNull('ec.expires_at')
            ->where('ec.expires_at', '<=', $until)
            ->orderBy('ec.expires_at', 'asc')
            ->select([
                'ec.id', 'ec.code', 'ec.max_uses', 'ec.used_count', 'ec.expires_at',
                'c.id as customer_id', 'c.name as customer_name'
            ]);

        return response()->json($query->paginate($per));
    }
}
