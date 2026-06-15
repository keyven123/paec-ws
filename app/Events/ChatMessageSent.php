<?php

namespace App\Events;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public ChatThread $thread;

    public function __construct(public ChatMessage $message)
    {
        $this->message->loadMissing('thread', 'attachment');
        $this->thread = $this->message->thread;
    }

    /**
     * Broadcast on the thread channel (for the open conversation) and on the
     * recipient's personal channel so list pages can update unread badges live.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.thread.' . $this->message->chat_thread_uuid),
        ];

        // The recipient is the side that did NOT send this message.
        if ($this->message->sender_type === ChatThread::SENDER_CUSTOMER) {
            if ($this->thread->organization_uuid) {
                $channels[] = new PrivateChannel('chat.org.' . $this->thread->organization_uuid);
            }
        } else {
            if ($this->thread->customer_uuid) {
                $channels[] = new PrivateChannel('chat.user.' . $this->thread->customer_uuid);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => (new ChatMessageResource($this->message))->resolve(),
            'venue_inquiry_uuid' => $this->thread->venue_inquiry_uuid,
            'thread_uuid' => $this->thread->uuid,
        ];
    }
}
