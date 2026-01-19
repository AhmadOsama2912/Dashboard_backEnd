<?php
// app/Http/Controllers/Admin/AdminContentBulkController.php
namespace App\Http\Controllers\Admin;

use App\Events\ScreenConfigUpdated;
use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Screen;
use App\Services\ScreenPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminContentBulkController extends Controller
{
    /**
     * PATCH /admin/v1/screens/playlist/all
     * body: { playlist_id: int|null }
     *
     * السلوك:
     * - إذا playlist_id = null → إزالة meta.playlist_id من كل الشاشات (الرجوع للـ Default بحسب كل شركة).
     * - إذا playlist_id موجود → تعيينه لكل شاشات نفس شركة الـPlaylist فقط (مطابقاً لسلوكك السابق).
     */
    public function assignPlaylistToAllScreens(Request $request)
    {
        $data = $request->validate([
            'playlist_id' => ['nullable','integer','exists:playlists,id'],
        ]);

        $affectedIds = [];
        $customerBuckets = [];

        if (empty($data['playlist_id'])) {
            // إزالة playlist_id من meta لجميع الشاشات (جميع الشركات)
            Screen::select('id','customer_id','meta')
                ->chunkById(200, function ($chunk) use (&$affectedIds, &$customerBuckets) {
                    foreach ($chunk as $s) {
                        $meta = is_array($s->meta) ? $s->meta : (array) ($s->meta ?? []);
                        if (array_key_exists('playlist_id', $meta)) {
                            unset($meta['playlist_id']);
                            $s->meta = $meta;
                            $s->save();
                            $affectedIds[] = (int) $s->id;
                            $customerBuckets[$s->customer_id][] = (int) $s->id;
                        }
                    }
                });

            $this->pushAndBroadcast($affectedIds, $customerBuckets, '');
            return response()->json([
                'message' => 'All screens set to company default (meta.playlist_id removed)',
                'count'   => count($affectedIds),
            ]);
        }

        // playlist_id موجود → نعيّنه لكل شاشات الشركة التابعة لها الـPlaylist
        $pl = Playlist::findOrFail($data['playlist_id']);
        $version = (string) ($pl->content_version ?? '');

        Screen::where('customer_id', $pl->customer_id)
            ->select('id','customer_id','meta')
            ->chunkById(200, function ($chunk) use (&$affectedIds, &$customerBuckets, $pl) {
                foreach ($chunk as $s) {
                    $meta = is_array($s->meta) ? $s->meta : (array) ($s->meta ?? []);
                    $meta['playlist_id'] = (int) $pl->id;
                    $s->meta = $meta;
                    $s->save();

                    $affectedIds[] = (int) $s->id;
                    $customerBuckets[$s->customer_id][] = (int) $s->id;
                }
            });

        $this->pushAndBroadcast($affectedIds, $customerBuckets, $version);
        return response()->json([
            'message'      => 'Assigned playlist to all screens of that playlist company',
            'customer_id'  => $pl->customer_id,
            'playlist_id'  => (int) $pl->id,
            'count'        => count($affectedIds),
        ]);
    }

    /**
     * PATCH /admin/v1/companies/{customer}/screens/playlist
     * body: { playlist_id: int|null }
     *
     * السلوك:
     * - null → إزالة meta.playlist_id لجميع شاشات الشركة.
     * - مُعرّف قائمة تشغيل → يجب أن تكون تابعة لنفس الشركة؛ ثم تُعيَّن لكل الشاشات.
     */
    public function assignPlaylistToCompanyScreens(Request $request, int $customer)
    {
        $data = $request->validate([
            'playlist_id' => ['nullable','integer','exists:playlists,id'],
        ]);

        $affectedIds = [];
        $customerBuckets = [];

        if (empty($data['playlist_id'])) {
            Screen::where('customer_id', $customer)
                ->select('id','customer_id','meta')
                ->chunkById(200, function ($chunk) use (&$affectedIds, &$customerBuckets) {
                    foreach ($chunk as $s) {
                        $meta = is_array($s->meta) ? $s->meta : (array) ($s->meta ?? []);
                        if (array_key_exists('playlist_id', $meta)) {
                            unset($meta['playlist_id']);
                            $s->meta = $meta;
                            $s->save();

                            $affectedIds[] = (int) $s->id;
                            $customerBuckets[$s->customer_id][] = (int) $s->id;
                        }
                    }
                });

            $this->pushAndBroadcast($affectedIds, $customerBuckets, '');
            return response()->json([
                'message'     => 'Assigned company default to all company screens (playlist cleared)',
                'customer_id' => $customer,
                'count'       => count($affectedIds),
            ]);
        }

        $pl = Playlist::findOrFail($data['playlist_id']);
        if ($pl->customer_id !== $customer) {
            return response()->json(['message' => 'Playlist/customer mismatch'], 422);
        }
        $version = (string) ($pl->content_version ?? '');

        Screen::where('customer_id', $customer)
            ->select('id','customer_id','meta')
            ->chunkById(200, function ($chunk) use (&$affectedIds, &$customerBuckets, $pl) {
                foreach ($chunk as $s) {
                    $meta = is_array($s->meta) ? $s->meta : (array) ($s->meta ?? []);
                    $meta['playlist_id'] = (int) $pl->id;
                    $s->meta = $meta;
                    $s->save();

                    $affectedIds[] = (int) $s->id;
                    $customerBuckets[$s->customer_id][] = (int) $s->id;
                }
            });

        $this->pushAndBroadcast($affectedIds, $customerBuckets, $version);
        return response()->json([
            'message'     => 'Assigned to all company screens',
            'customer_id' => $customer,
            'playlist_id' => (int) $pl->id,
            'count'       => count($affectedIds),
        ]);
    }

    /**
     * PATCH /admin/v1/screens/playlist
     * body: { screen_ids: int[], playlist_id: int|null }
     *
     * السلوك:
     * - يتحقق من تطابق الشركة مع الـPlaylist إن وُجدت.
     * - يكتب/يحذف meta.playlist_id لكل شاشة محددة.
     * - يدفع عبر WS ويطلق events.
     */
    public function assignPlaylistToScreens(Request $request)
    {
        $data = $request->validate([
            'screen_ids'   => ['required','array','min:1'],
            'screen_ids.*' => ['integer','exists:screens,id'],
            'playlist_id'  => ['nullable','integer','exists:playlists,id'],
        ]);

        $pl = null;
        if (!empty($data['playlist_id'])) {
            $pl = Playlist::findOrFail($data['playlist_id']);
        }

        $screens = Screen::whereIn('id', $data['screen_ids'])
            ->select('id','customer_id','meta')
            ->get();

        if ($pl) {
            foreach ($screens as $s) {
                if ((int) $s->customer_id !== (int) $pl->customer_id) {
                    return response()->json([
                        'message' => "Playlist/customer mismatch for screen {$s->id}"
                    ], 422);
                }
            }
        }

        $affectedIds = [];
        $customerBuckets = [];

        DB::transaction(function () use ($screens, $pl, &$affectedIds, &$customerBuckets) {
            foreach ($screens as $s) {
                $meta = is_array($s->meta) ? $s->meta : (array) ($s->meta ?? []);
                if ($pl) {
                    $meta['playlist_id'] = (int) $pl->id;
                } else {
                    unset($meta['playlist_id']);
                }
                $s->meta = $meta;
                $s->save();

                $affectedIds[] = (int) $s->id;
                $customerBuckets[$s->customer_id][] = (int) $s->id;
            }
        });

        $version = $pl ? (string) ($pl->content_version ?? '') : '';
        $this->pushAndBroadcast($affectedIds, $customerBuckets, $version, perScreen: true);

        return response()->json([
            'message'     => 'Assigned to selected screens',
            'playlist_id' => $pl ? (int) $pl->id : null,
            'count'       => count($affectedIds),
        ]);
    }

    /**
     * POST /admin/v1/companies/{customer}/broadcast-config
     * إرسال بثّ عام لكل شاشات الشركة (بدون تغيير تخصيص الـplaylist).
     */
    public function broadcastCustomerConfig(int $customer)
    {
        // حدث واحد على مستوى الشركة
        event(new ScreenConfigUpdated($customer, null, ''));
        // دفع WS لكل شاشات الشركة
        $ids = Screen::where('customer_id', $customer)->pluck('id')->map(fn($v)=>(int)$v)->all();
        if ($ids) {
            try {
                app(ScreenPushService::class)->bumpScreens($ids, 'force');
            } catch (\Throwable $e) {
                \Log::warning('WS push failed (broadcastCustomerConfig)', [
                    'customer_id' => $customer,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
        return response()->json(['message' => 'Broadcast sent to company screens', 'customer_id' => $customer, 'count' => count($ids)]);
    }

    /**
     * POST /admin/v1/screens/broadcast-config
     * body: { screen_ids: int[] }
     * بثّ لسكّرين محددين.
     */
    public function broadcastScreensConfig(Request $request)
    {
        $data = $request->validate([
            'screen_ids'   => ['required','array','min:1'],
            'screen_ids.*' => ['integer','exists:screens,id'],
        ]);

        $ids = [];
        $screens = Screen::whereIn('id', $data['screen_ids'])
            ->select('id','customer_id')
            ->get();

        foreach ($screens as $s) {
            $ids[] = (int) $s->id;
            event(new ScreenConfigUpdated($s->customer_id, (int)$s->id, ''));
        }

        if ($ids) {
            try {
                app(ScreenPushService::class)->bumpScreens($ids, 'force');
            } catch (\Throwable $e) {
                \Log::warning('WS push failed (broadcastScreensConfig)', [
                    'screen_ids' => $ids,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['message' => 'Broadcast sent', 'count' => count($ids)]);
    }

    /* ------------------------ Helpers ------------------------ */

    /**
     * دفع WS وإطلاق Events بشكل مناسب بعد كل عملية Bulk.
     *
     * @param array<int> $ids               قائمة الشاشات المتأثرة
     * @param array<int,array<int>> $buckets تجميع الشاشات بحسب customer_id
     * @param string $version               نسخة المحتوى (إن وجدت)
     * @param bool $perScreen               هل نطلق حدث لكل شاشة؟ وإلا فلكل شركة مرة واحدة.
     */
    protected function pushAndBroadcast(array $ids, array $buckets, string $version, bool $perScreen = false): void
    {
        if ($ids) {
            try {
                app(ScreenPushService::class)->bumpScreens($ids, $version ?: 'force');
            } catch (\Throwable $e) {
                \Log::warning('WS push failed (bulk)', [
                    'screen_ids' => $ids,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if ($perScreen) {
            foreach ($buckets as $customerId => $screenIds) {
                foreach ($screenIds as $sid) {
                    event(new ScreenConfigUpdated((int)$customerId, (int)$sid, $version));
                }
            }
        } else {
            foreach ($buckets as $customerId => $_screenIds) {
                event(new ScreenConfigUpdated((int)$customerId, null, $version));
            }
        }
    }
}
