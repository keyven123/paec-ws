<?php

namespace App\Http\Resources;

use App\Models\ChatThread;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatThreadResource extends JsonResource
{
    /**
     * The viewer side ("customer" or "merchant") used to compute the unread count.
     */
    protected ?string $viewerSide = null;

    public function forSide(string $side): self
    {
        $this->viewerSide = $side;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $side = $this->viewerSide ?? ChatThread::SENDER_CUSTOMER;

        return [
            'uuid' => $this->uuid,
            'venue_inquiry_uuid' => $this->venue_inquiry_uuid,
            'venue_listing_uuid' => $this->venue_listing_uuid,
            'organization_uuid' => $this->organization_uuid,
            'customer_uuid' => $this->customer_uuid,
            'last_message_preview' => $this->last_message_preview,
            'last_message_at' => $this->last_message_at?->toISOString(),
            'unread_count' => $this->unreadCountFor($side),
            'channel_name' => 'chat.thread.' . $this->uuid,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
