<?php

namespace App\Http\Resources;

use App\Models\Upload;
use App\Support\OrganizationPaymentMethods;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EventShowPublicResource extends JsonResource
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
            'venue_uuid' => $this->venue_uuid,
            'category_uuid' => $this->category_uuid,
            'event_section_uuid' => $this->event_section_uuid,
            'event_section_name' => $this->whenLoaded('eventSection', fn () => $this->eventSection->name),
            'event_name' => $this->event_name,
            'category_name' => $this->category->name,
            'event_description' => $this->event_description,
            'contact_email' => $this->contact_email,
            'address' => $this->address,
            'city' => $this->city,
            'logo_uuid' => $this->logo_uuid,
            'portrait_image_uuid' => $this->portrait_image_uuid,
            'featured_image_uuid' => $this->featured_image_uuid,
            'event_config' => $this->event_config,
            'event_showcase' => $this->when($this->event_showcase, function () {
                $uuids = collect($this->event_showcase);

                return Upload::whereIn('uuid', $uuids)
                    ->get()
                    ->sortBy(fn ($upload) => $uuids->search($upload->uuid))
                    ->values()
                    ->map(fn ($upload) => [
                        'uuid' => $upload->uuid,
                        'path' => $upload->path,
                        'url' => $upload->url,
                        'disk' => $upload->disk,
                    ]);
            }),
            'event_type' => $this->event_type,
            'schedule_type' => $this->schedule_type,
            'excluded_dates' => $this->excluded_dates,
            'track_event_meta' => $this->track_event_meta,
            'meta_pixel_id' => $this->meta_pixel_id,
            'meta_test_event_code' => $this->meta_test_event_code,
            'slug' => $this->slug,
            'other_info' => $this->other_info,
            'other_info_deadline' => $this->other_info_deadline?->toDateTimeString(),
            'today_cutoff_time' => $this->formattedTodayCutoffTime(),
            'payment_methods' => $this->whenLoaded('organization', function () {
                return OrganizationPaymentMethods::normalize($this->organization?->payment_methods);
            }),
            // Note: meta_pixel_key is intentionally excluded from public responses for security
            'portrait_image' => $this->whenLoaded('portraitImage', function () {
                return [
                    'uuid' => $this->portraitImage->uuid,
                    'url' => $this->portraitImage->url,
                    'disk' => $this->portraitImage->disk,
                ];
            }),
            'featured_image' => $this->whenLoaded('featuredImage', function () {
                return [
                    'uuid' => $this->featuredImage->uuid,
                    'url' => $this->featuredImage->url,
                    'disk' => $this->featuredImage->disk,
                ];
            }),
            'venue' => $this->whenLoaded('venue', function () {
                return [
                    'uuid' => $this->venue->uuid,
                    'name' => $this->venue->name,
                    'code' => $this->venue->code,
                    'type' => $this->venue->type,
                    'image' => [
                        'uuid' => $this->venue->image?->uuid,
                        'path' => $this->venue->image?->path,
                        'url' => config('app.url') . '/' . $this->venue->image?->path,
                    ],
                ];
            }),
            'event_locations' => $this->whenLoaded('eventLocations', function () {
                return EventLocationResource::collection(
                    $this->eventLocations->where('is_active', true)->values()
                );
            }),
        ];
    }
}
