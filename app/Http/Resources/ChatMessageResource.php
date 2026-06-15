<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'chat_thread_uuid' => $this->chat_thread_uuid,
            'sender_type' => $this->sender_type,
            'sender_uuid' => $this->sender_uuid,
            'sender_name' => $this->sender_name,
            'message_type' => $this->message_type ?: 'text',
            'body' => $this->body,
            'metadata' => $this->metadata,
            'attachment' => $this->attachmentPayload(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attachmentPayload(): ?array
    {
        if (!$this->attachment_upload_uuid) {
            return null;
        }

        $upload = $this->attachment;

        if (!$upload) {
            return null;
        }

        return [
            'uuid' => $upload->uuid,
            'name' => $this->attachment_name ?: ('file.' . $upload->extension),
            'url' => $upload->url,
            'type' => $upload->type,
            'mime_type' => $upload->mime_type,
            'size_bytes' => $upload->size_bytes,
        ];
    }
}
