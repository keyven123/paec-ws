<?php

namespace App\Http\Resources;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowEventResource extends JsonResource
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
            'event_name' => $this->event_name,
            'event_description' => $this->event_description,
            'contact_email' => $this->contact_email,
            'logo_uuid' => $this->logo_uuid,
            'portrait_image_uuid' => $this->portrait_image_uuid,
            'featured_image_uuid' => $this->featured_image_uuid,
            'event_showcase' => Upload::whereIn('uuid', $this->event_showcase)->select('uuid', 'path')->get(),
            'event_config' => $this->event_config,
            'event_type' => $this->event_type,
            'schedule_type' => $this->schedule_type,
            'excluded_dates' => $this->excluded_dates,
            'published_at' => $this->published_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'registration_count' => $this->registration_count,
            'is_featured' => $this->is_featured,
            'featured_order' => $this->featured_order,
            'featured_from' => $this->featured_from?->toISOString(),
            'featured_until' => $this->featured_until?->toISOString(),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'tags' => $this->tags,
            'track_event_meta' => $this->track_event_meta,
            'meta_pixel_id' => $this->meta_pixel_id,
            // Note: meta_pixel_key is intentionally excluded from public responses for security
            'other_info' => $this->other_info,
            'other_info_deadline' => $this->other_info_deadline?->toISOString(),
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Computed fields
            'is_cancelled' => !is_null($this->cancelled_at),
            'is_completed' => !is_null($this->completed_at),
            'is_active' => is_null($this->cancelled_at) && is_null($this->completed_at),

            // Relationships
            'approved_by' => $this->whenLoaded('approved_by', function () {
                return [
                    'uuid' => $this->approved_by->uuid,
                    'name' => $this->approved_by->first_name . ' ' . $this->approved_by->last_name,
                    'email' => $this->approved_by->email,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->first_name . ' ' . $this->creator->last_name,
                    'email' => $this->creator->email,
                ];
            }),
            'updater' => $this->whenLoaded('updater', function () {
                return [
                    'uuid' => $this->updater->uuid,
                    'name' => $this->updater->first_name . ' ' . $this->updater->last_name,
                    'email' => $this->updater->email,
                ];
            }),
            'schedules' => $this->whenLoaded('schedules', function () {
                return ScheduleResource::collection($this->schedules);
            }),
            'event_tickets' => $this->whenLoaded('event_tickets', function () {
                return EventTicketResource::collection($this->event_tickets);
            }),
        ];
    }
}
