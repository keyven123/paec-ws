<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionVenueInquiryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            (new VenueInquiryResource($this->resource))->toArray($request),
            [
                'venue_listing' => $this->whenLoaded('venueListing', function () {
                    if ($this->venueListing === null) {
                        return null;
                    }

                    return [
                        'uuid' => $this->venueListing->uuid,
                        'slug' => $this->venueListing->slug,
                        'name' => $this->venueListing->name,
                        'address' => $this->venueListing->address,
                        'location' => $this->venueListing->location,
                        'city' => $this->venueListing->city,
                        'region' => $this->venueListing->region,
                        'area' => $this->venueListing->area,
                        'type' => $this->venueListing->venue_type,
                        'venue_type' => $this->venueListing->venue_type,
                    ];
                }),
            ]
        );
    }
}
