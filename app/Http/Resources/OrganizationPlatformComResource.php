<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationPlatformComResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'organization_uuid' => $this->organization_uuid,
            'previous_coms' => $this->previous_coms !== null ? (float) $this->previous_coms : null,
            'current_coms' => (float) $this->current_coms,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'uuid' => $this->organization->uuid,
                    'name' => $this->organization->name,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => trim($this->creator->first_name . ' ' . $this->creator->last_name),
                    'email' => $this->creator->email,
                ];
            }),
        ];
    }
}
