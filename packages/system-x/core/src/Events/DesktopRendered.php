<?php

namespace SystemX\Core\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DesktopRendered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // desktopId FIRST + tree LAST are load-bearing (D5): every existing
    // $e->desktopId / $e->tree reader survives. appSlug + windowId slot in the middle
    // so a single private-user.{id} channel addresses multiple windows by payload.
    /** @param array<string, mixed> $tree serialized widget tree from Serializer::serialize() */
    public function __construct(
        public string $desktopId,
        public string $appSlug,
        public string $windowId,
        public array $tree,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->desktopId);
    }

    public function broadcastAs(): string
    {
        return 'desktop.rendered';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'app' => $this->appSlug,
            'window' => $this->windowId,
            'tree' => $this->tree,
        ];
    }
}
