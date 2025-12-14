<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Screen;
use App\Services\ScreenPushService;
use Illuminate\Http\Request;

class PlaylistPushController extends Controller
{
    public function push(Request $request, Screen $screen, ScreenPushService $pusher)
    {
        $data = $request->validate([
            'version' => 'nullable|string',
        ]);

        $pusher->bumpScreens([$screen->id], $data['version'] ?? null);

        return response()->json(['ok' => true]);
    }
}
