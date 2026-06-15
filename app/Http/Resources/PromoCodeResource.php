<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoCodeResource extends JsonResource
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
            'organization_uuid' => $this->organization_uuid,
            'code' => $this->code,
            'description' => $this->description,
            'activityable_type' => $this->activityable_type,
            'activityable_id' => $this->activityable_id,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'is_unlimited' => $this->is_unlimited,
            'max_use' => $this->max_use,
            'usable_from' => $this->usable_from,
            'usable_to' => $this->usable_to,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Add relationships if needed
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'uuid' => $this->organization->uuid,
                    'name' => $this->organization->name,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->first_name . ' ' . $this->creator->last_name,
                ];
            }),
            'updater' => $this->whenLoaded('updater', function () {
                return [
                    'uuid' => $this->updater->uuid,
                    'name' => $this->updater->first_name . ' ' . $this->updater->last_name,
                ];
            }),
            'activityable' => $this->whenLoaded('activityable', function () {
                return [
                    'type' => $this->activityable_type,
                    'id' => $this->activityable_id,
                    'data' => $this->activityable,
                ];
            }),
        ];
    }
}
