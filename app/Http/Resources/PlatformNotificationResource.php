<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'       => $this->uuid,
            'type'       => $this->type,
            'title'      => $this->title,
            'body'       => $this->body,
            'action_url' => $this->action_url,
            'data'       => $this->data,
            'is_read'    => $this->read_at !== null,
            'read_at'    => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
