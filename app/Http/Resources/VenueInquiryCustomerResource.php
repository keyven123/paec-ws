<?php

namespace App\Http\Resources;

use App\Models\VenueInquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueInquiryCustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = (new VenueInquiryResource($this->resource))->toArray($request);
        $data['status_label'] = VenueInquiry::customerStatusLabel($this->status);

        return array_merge(
            $data,
            [
                // Chat is only available for inquiries tied to a logged-in user
                // (no guest chat). Guest inquiries matched purely by email have
                // a null user_uuid and cannot open a thread.
                'can_chat' => $this->user_uuid !== null,
                'venue' => $this->whenLoaded('venueListing', function () {
                    return [
                        'uuid' => $this->venueListing->uuid,
                        'slug' => $this->venueListing->slug,
                        'name' => $this->venueListing->name,
                        'city' => $this->venueListing->city,
                        'type' => $this->venueListing->venue_type,
                        'image_color' => $this->venueListing->image_color,
                    ];
                }),
            ]
        );
    }
}
