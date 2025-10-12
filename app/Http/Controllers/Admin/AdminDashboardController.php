<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Playlist;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AdminDashboardController extends Controller
{
    /**
     * GET /admin/v1/dashboard/summary
     * Small KPIs for the top cards on the dashboard.
     */
    public function summary(Request $request)
    {
        $minutes   = (int) config('screens.online_grace_minutes', 5);
        $threshold = now()->subMinutes($minutes);

        $data = Cache::remember('admin.dashboard.summary', 30, function () use ($threshold) {

            // Screens
            $totalScreens  = Screen::count();
            $onlineScreens = Screen::where('last_check_in_at', '>=', $threshold)->count();
            $offlineScreens = max($totalScreens - $onlineScreens, 0);

            // Count screens that have a playlist only if the column exists
            $withPlaylist = 0;
            if (Schema::hasColumn('screens', 'playlist_id')) {
                $withPlaylist = Screen::whereNotNull('playlist_id')->count();
            }
            $withoutPlaylist = max($totalScreens - $withPlaylist, 0);

            // Customers
            $totalCustomers = Customer::count();

            // Users
            $managers    = User::where('role', 'manager')->count();
            $supervisors = User::where('role', 'supervisor')->count();

            // Playlists (optional table/column)
            $publishedPlaylists = 0;
            if (Schema::hasTable('playlists') && Schema::hasColumn('playlists', 'published_at')) {
                $publishedPlaylists = Playlist::whereNotNull('published_at')->count();
            }

            return [
                'totals' => [
                    'screens' => $totalScreens,
                    'customers' => $totalCustomers,
                    'playlists_published' => $publishedPlaylists,
                    'users' => [
                        'managers' => $managers,
                        'supervisors' => $supervisors,
                    ],
                ],
                'screens' => [
                    'online'            => $onlineScreens,
                    'offline'           => $offlineScreens,
                    'with_playlist'     => $withPlaylist,
                    'without_playlist'  => $withoutPlaylist,
                    'online_threshold_minutes' => (int) config('screens.online_grace_minutes', 5),
                ],
                'refreshed_at' => now()->toIso8601String(),
            ];
        });

        return response()->json($data);
    }

    /**
     * GET /admin/v1/dashboard/screens
     * Paginated list of screens with online flag and customer name.
     * Query: q, customer_id, status, online, sort_by, sort_dir, per_page
     */
    public function screens(Request $request)
    {
        $data = $request->validate([
            'q'           => ['nullable','string','max:190'],
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'status'      => ['nullable','in:not_activated,active,disabled'],
            'online'      => ['nullable','in:true,false,1,0'],
            'sort_by'     => ['nullable','in:last_check_in_at,created_at,label,serial_number'],
            'sort_dir'    => ['nullable','in:asc,desc'],
            'per_page'    => ['nullable','integer','min:1','max:100'],
        ]);

        $minutes   = (int) config('screens.online_grace_minutes', 5);
        $threshold = now()->subMinutes($minutes);

        $q = Screen::query()
            ->with(['customer:id,name'])
            ->select(['id','customer_id','serial_number','label','status',
                      // include if present; harmless if not selected
                      'last_check_in_at','created_at']);

        if (!empty($data['q'])) {
            $term = '%'.$data['q'].'%';
            $q->where(function ($qq) use ($term) {
                $qq->where('serial_number', 'like', $term)
                   ->orWhere('label', 'like', $term);
            });
        }
        if (!empty($data['customer_id'])) {
            $q->where('customer_id', $data['customer_id']);
        }
        if (!empty($data['status'])) {
            $q->where('status', $data['status']);
        }
        if (isset($data['online'])) {
            $wantOnline = in_array($data['online'], ['true','1',1,true], true);
            $q->where('last_check_in_at', $wantOnline ? '>=' : '<', $threshold);
        }

        $sortBy  = $data['sort_by']  ?? 'last_check_in_at';
        $sortDir = $data['sort_dir'] ?? 'desc';
        $q->orderBy($sortBy, $sortDir);

        $paginator = $q->paginate($data['per_page'] ?? 15);

        $paginator->getCollection()->transform(function ($s) use ($threshold) {
            return [
                'id'                => $s->id,
                'customer_id'       => $s->customer_id,
                'customer_name'     => optional($s->customer)->name,
                'serial_number'     => $s->serial_number,
                'label'             => $s->label,
                'status'            => $s->status,
                'last_check_in_at'  => optional($s->last_check_in_at)->toIso8601String(),
                'created_at'        => optional($s->created_at)->toIso8601String(),
                'online'            => $s->last_check_in_at && $s->last_check_in_at->gte($threshold),
            ];
        });

        return response()->json($paginator);
    }

    /**
     * GET /admin/v1/dashboard/customers
     * Paginated customers with counts (screens/users/online screens).
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

        $q = Customer::query();

        // Build withCount array only for relations we know exist
        $withs = [
            'screens',
            'users',
            // online screens count
            'screens as screens_online_count' => function ($qq) use ($threshold) {
                $qq->where('last_check_in_at', '>=', $threshold);
            },
        ];

        // Only add playlists count if the relation **and** table exist
        if (method_exists(Customer::class, 'playlists') && Schema::hasTable('playlists')) {
            $withs[] = 'playlists';
        }

        $q->withCount($withs);

        if (!empty($data['q'])) {
            $term = '%'.$data['q'].'%';
            $q->where('name', 'like', $term);
        }

        $q->orderBy('created_at', 'desc');

        $paginator = $q->paginate($data['per_page'] ?? 15);

        $paginator->getCollection()->transform(function ($c) {
            $offline = max(($c->screens_count ?? 0) - ($c->screens_online_count ?? 0), 0);

            return [
                'id'                    => $c->id,
                'name'                  => $c->name,
                'package_id'            => $c->package_id,
                'screens_count'         => (int) ($c->screens_count ?? 0),
                'screens_online_count'  => (int) ($c->screens_online_count ?? 0),
                'screens_offline_count' => (int) $offline,
                'users_count'           => (int) ($c->users_count ?? 0),
                // Only present if you kept playlists() + table exists
                'playlists_count'       => (int) ($c->playlists_count ?? 0),
                'created_at'            => optional($c->created_at)->toIso8601String(),
            ];
        });

        return response()->json($paginator);
    }
}
