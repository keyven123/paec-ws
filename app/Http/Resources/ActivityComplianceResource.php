<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityComplianceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'activityable_type' => $this->activityable_type,
            'activityable_id' => $this->activityable_id,
            'label' => $this->label,
            'percentage' => (float) $this->percentage,
            'fixed_amount' => $this->fixed_amount !== null ? (float) $this->fixed_amount : null,
            'amount_type' => $this->amount_type,
            'status' => $this->status,
            'updated_by_uuid' => $this->updated_by_uuid,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
