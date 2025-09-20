<?php

// app/Http/Controllers/Screen/ScreenController.php
namespace App\Http\Controllers\Screen;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentCode;
use App\Models\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ScreenController extends Controller
{
    // أول تسجيل: يربط الشاشة بالشركة تلقائياً من خلال claim_code
    public function register(Request $request)
    {
        $data = $request->validate([
            'serial_number' => ['required','string','max:190'],
            'device_model'  => ['nullable','string','max:190'],
            'os_version'    => ['nullable','string','max:190'],
            'app_version'   => ['nullable','string','max:190'],
            'claim_code'    => ['required','string','max:32'],
        ]);

        $code = EnrollmentCode::where('code', $data['claim_code'])
            ->where(function($q){ $q->whereNull('expires_at')->orWhere('expires_at','>', now()); })
            ->first();

        if (!$code || $code->used_count >= $code->max_uses) {
            return response()->json(['message'=>'Invalid or expired claim code'], 422);
        }

        // أنشئ/حدّث سجل الشاشة عند نفس الشركة + نفس السيريال
        $screen = Screen::firstOrNew([
            'customer_id'   => $code->customer_id,
            'serial_number' => $data['serial_number'],
        ]);

        $screen->fill([
            'device_model'     => $data['device_model'] ?? $screen->device_model,
            'os_version'       => $data['os_version']   ?? $screen->os_version,
            'app_version'      => $data['app_version']  ?? $screen->app_version,
            'activated_at'     => $screen->activated_at ?? now(),
            'last_check_in_at' => now(),
            'access_scope'     => $screen->access_scope ?? 'company', // افتراضيًا: كل المشرفين بالشركة
        ]);

        if (!$screen->api_token) $screen->api_token = Str::random(64);
        $screen->save();

        $code->increment('used_count');

        [$version, $items] = $this->playlist($screen);

        return response()->json([
            'token'  => $screen->api_token,
            'screen' => [
              'id'=>$screen->id,'customer_id'=>$screen->customer_id,'serial_number'=>$screen->serial_number,
              'status'=>$screen->status,'access_scope'=>$screen->access_scope,'assigned_user_id'=>$screen->assigned_user_id,
              'activated_at'=>$screen->activated_at,'last_check_in_at'=>$screen->last_check_in_at,
            ],
            'playlist' => [
              'content_version' => $version,
              'updated_at'      => now(),
              'items'           => $items,
            ],
        ], 201);
    }

    public function heartbeat(Request $request)
    {
        /** @var Screen $screen */
        $screen = $request->attributes->get('screen');
        $screen->last_check_in_at = now();
        $screen->save();

        return response()->json(['message'=>'ok','status'=>$screen->status,'server_time'=>now()]);
    }

    public function config(Request $request)
    {
        /** @var Screen $screen */
        $screen = $request->attributes->get('screen');
        [$version, $items] = $this->playlist($screen);

        return response()->json([
          'content_version'=>$version,
          'updated_at'=>now(),
          'poll_after_sec'=>60,
          'items'=>$items
        ]);
    }

    protected function playlist(Screen $screen): array
    {
        // لاحقًا اربطها بجدول playlists/media
        $items = [
          ['id'=>'img-1','type'=>'image','url'=>url('/storage/media/customer_'.$screen->customer_id.'/hero.jpg'),'duration'=>10,'checksum'=>'md5:abc'],
          ['id'=>'vid-7','type'=>'video','url'=>url('/storage/media/customer_'.$screen->customer_id.'/ad7.mp4'),'duration'=>30,'checksum'=>'md5:def'],
        ];
        $version = 'sha256:'.hash('sha256', json_encode($items));
        return [$version, $items];
    }
}
