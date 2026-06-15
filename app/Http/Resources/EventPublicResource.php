<?php

namespace App\Http\Resources;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventPublicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $eventShowcase = null;
        if ($this->event_showcase != null) {
            $eventShowcase = Upload::whereIn('uuid', $this->event_showcase)->get();
            $eventShowcase = $eventShowcase->map(function ($upload) {
                return [
                    'uuid' => $upload->uuid,
                    'path' => $upload->path,
                    'url' => $upload->url,
                    'disk' => $upload->disk,
                ];
            });
        }
        return [
            'uuid' => $this->uuid,
            'venue_uuid' => $this->venue_uuid,
            'category_uuid' => $this->category_uuid,
            'event_section_uuid' => $this->event_section_uuid,
            'event_section_name' => $this->whenLoaded('eventSection', fn () => $this->eventSection->name),
            'event_name' => $this->event_name,
            'event_description' => $this->event_description,
            'contact_email' => $this->contact_email,
            'logo_uuid' => $this->logo_uuid,
            'portrait_image_uuid' => $this->portrait_image_uuid,
            'featured_image_uuid' => $this->featured_image_uuid,
            'address' => $this->address,
            'city' => $this->city,
            'category_name' => $this->whenLoaded('category', fn () => $this->category->name),
            'event_type' => $this->event_type,
            'event_config' => $this->event_config,
            'event_showcase' => $eventShowcase,
            'status' => $this->status,
            'track_event_meta' => $this->track_event_meta,
            'meta_pixel_id' => $this->meta_pixel_id,
            'meta_pixel_key' => $this->meta_pixel_key,
            'meta_test_event_code' => $this->meta_test_event_code,
            'slug' => $this->slug,
            'affiliate_enabled' => (bool) $this->affiliate_enabled,
            'affiliate_commission_percent' => $this->affiliate_commission_percent !== null
                ? (float) $this->affiliate_commission_percent
                : null,
            'affiliate_ends_at' => $this->affiliate_ends_at?->format('Y-m-d'),
            'price_start' => $this->eventTickets->min('price'),
            'schedules' => $this->whenLoaded('schedules', function () {
                return $this->schedules->map(function ($schedule) {
                    return [
                        'uuid' => $schedule->uuid,
                        'date_from' => $schedule->date_from?->format('Y-m-d'),
                        'date_to' => $schedule->date_to?->format('Y-m-d'),
                        'schedule_times' => $schedule->scheduleTimes->map(function ($scheduleTime) {
                            return [
                                'uuid' => $scheduleTime->uuid,
                                'time_start' => $scheduleTime->time_start,
                                'time_end' => $scheduleTime->time_end,
                            ];
                        }),
                    ];
                });
            }),

            // Image URLs
            'logo' => $this->whenLoaded('logo', function () {
                return [
                    'uuid' => $this->logo->uuid,
                    'url' => $this->logo->url,
                    'disk' => $this->logo->disk,
                ];
            }),
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

            // Category relationship
            'category' => $this->whenLoaded('category', function () {
                return [
                    'uuid' => $this->category->uuid,
                    'name' => $this->category->name,
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
