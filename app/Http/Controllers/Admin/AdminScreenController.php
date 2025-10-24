<?php
// app/Http/Controllers/Admin/AdminScreenController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminScreenController extends Controller
{
    /** GET /admin/v1/screens */
public function index(Request $request)
    {
        $payload = $request->validate([
            'q'           => ['nullable','string','max:190'],
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'online'      => ['nullable','in:true,false,1,0'],               // <- optional
            'per_page'    => ['nullable','integer','min:1','max:100'],
            'sort_by'     => ['nullable','in:id,serial_number,created_at,last_check_in_at'],
            'sort_dir'    => ['nullable','in:asc,desc'],
        ]);

        $minutes   = (int) config('screens.online_grace_minutes', 5);
        $threshold = now()->subMinutes($minutes);

        // select only columns that exist in your schema
        $select = ['id','customer_id','serial_number','device_model','os_version','app_version','activated_at','created_at'];
        if (Schema::hasColumn('screens', 'last_check_in_at')) $select[] = 'last_check_in_at';
        if (Schema::hasColumn('screens', 'playlist_id'))      $select[] = 'playlist_id';

        $q = Screen::query()
            ->with(['customer:id,name'])
            ->select($select);

        if (!empty($payload['q'])) {
            $term = '%'.$payload['q'].'%';
            $q->where(function ($w) use ($term) {
                $w->where('serial_number','like',$term)
                  ->orWhere('device_model','like',$term);
            });
        }

        if (!empty($payload['customer_id'])) {
            $q->where('customer_id', $payload['customer_id']);
        }

        // Only filter by online if the client asked for it
        if (isset($payload['online'])) {
            $wantOnline = in_array($payload['online'], ['true','1',1,true], true);
            if ($wantOnline) {
                $q->where('last_check_in_at', '>=', $threshold);
            } else {
                $q->where(function ($w) use ($threshold) {
                    $w->whereNull('last_check_in_at')
                      ->orWhere('last_check_in_at', '<', $threshold);
                });
            }
        }

        $sortBy  = $payload['sort_by']  ?? (Schema::hasColumn('screens','last_check_in_at') ? 'last_check_in_at' : 'created_at');
        $sortDir = $payload['sort_dir'] ?? 'desc';
        $q->orderBy($sortBy, $sortDir);

        $page = $q->paginate($payload['per_page'] ?? 20);

        $page->getCollection()->transform(function (Screen $s) use ($threshold) {
            return [
                'id'               => $s->id,
                'customer_id'      => $s->customer_id,
                'customer_name'    => optional($s->customer)->name,
                'serial_number'    => $s->serial_number,
                'device_model'     => $s->device_model,
                'os_version'       => $s->os_version,
                'app_version'      => $s->app_version,
                'playlist_id'      => property_exists($s, 'playlist_id') ? $s->playlist_id : null,
                'last_check_in_at' => optional($s->last_check_in_at)->toIso8601String(),
                'activated_at'     => optional($s->activated_at)->toIso8601String(),
                'created_at'       => optional($s->created_at)->toIso8601String(),
                'online'           => $s->last_check_in_at && $s->last_check_in_at->gte($threshold),
            ];
        });

        return response()->json($page);
    }

    /** GET /admin/v1/screens/{screen} */
    public function show(Screen $screen)
    {
        $screen->load('customer:id,name');

        $playlistId = is_array($screen->meta ?? null) ? ($screen->meta['playlist_id'] ?? null) : null;

        return response()->json([
            'id'               => $screen->id,
            'customer_id'      => $screen->customer_id,
            'customer_name'    => optional($screen->customer)->name,
            'serial_number'    => $screen->serial_number,
            'device_model'     => $screen->device_model,
            'os_version'       => $screen->os_version,
            'app_version'      => $screen->app_version,
            'playlist_id'      => $playlistId,
            'last_check_in_at' => optional($screen->last_check_in_at)->toIso8601String(),
            'created_at'       => optional($screen->created_at)->toIso8601String(),
        ]);
    }
}
