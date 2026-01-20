<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScreenPushService
{
    private string $wsBase;
    private string $pushSecret;
    private int $timeout;

    public function __construct(?string $wsBase = null)
    {
        $this->wsBase = rtrim(
            $wsBase ?? config('services.ws.url', 'https://api.madar2030.com:8081'),
            '/'
        );

        $this->pushSecret = (string) config('services.ws.push_secret');
        $this->timeout    = (int) config('services.ws.timeout', 3);
    }

    /**
     * Notify screens that playlist/content has changed
     */
    public function bumpScreens(array $screenIds, ?string $contentVersion = null): void
    {
        foreach ($screenIds as $id) {
            try {
                Http::timeout($this->timeout)
                    ->withHeaders([
                        'X-Push-Secret' => $this->pushSecret,
                        'Accept'        => 'application/json',
                    ])
                    ->post("{$this->wsBase}/push/screen-{$id}", [
                        'event'     => 'playlist.bump',
                        'screen_id' => (int) $id,
                        'version'   => $contentVersion ?? ('pl-' . now()->timestamp),
                    ])
                    ->throw();

            } catch (\Throwable $e) {
                // IMPORTANT: do NOT break the loop if one screen fails
                Log::warning('WS screen bump failed', [
                    'screen_id' => $id,
                    'url'       => "{$this->wsBase}/push/screen-{$id}",
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }
}

