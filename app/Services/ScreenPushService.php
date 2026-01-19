<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ScreenPushService
{
    private string $wsBase;

    public function __construct(?string $wsBase = null)
    {
        $this->wsBase = rtrim($wsBase ?? config('services.ws.url', 'http://127.0.0.1:8081'), '/');
    }

    public function bumpScreens(array $screenIds, ?string $contentVersion = null): void
    {
        foreach ($screenIds as $id) {
            Http::post("{$this->wsBase}/push/screen-{$id}", [
                'event'     => 'playlist.bump',
                'screen_id' => (int) $id,
                'version'   => $contentVersion ?? ('pl-'.now()->timestamp),
            ])->throwIf(fn($r) => !$r->ok());
        }
    }
}
