<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicOrganizationResource extends JsonResource
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
            'image' => $this->whenLoaded('image', function () {
                return [
                    'uuid' => $this->image->uuid,
                    'url' => $this->image->url,
                    'path' => $this->image->path,
                ];
            }),
            'name' => $this->name,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'address' => $this->address,
            'description' => $this->description,
        ];
    }
}
