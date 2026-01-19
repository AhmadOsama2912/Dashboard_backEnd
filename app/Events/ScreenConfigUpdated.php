<?php
// app/Events/ScreenConfigUpdated.php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class ScreenConfigUpdated implements ShouldBroadcast
{
    public function __construct(
        public int $customerId,                // 0 if unknown, ok
        public ?int $screenId = null,          // null => broadcast to all customer's screens
        public string $contentVersion = ''     // optional version hint
    ) {}

    public function broadcastOn(): array
    {
        return $this->screenId
            ? [new Channel("screen.{$this->screenId}")]
            : [new Channel("customer.{$this->customerId}.screens")];
    }

    public function broadcastAs(): string
    {
        return 'config.updated';
    }

    public function broadcastWith(): array
    {
        return ['content_version' => $this->contentVersion];
    }
}
