<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UploadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'disk' => $this->disk,
            'path' => $this->path,
            'collection' => $this->collection,
            'url' => $this->url,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'checksum' => $this->checksum,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
