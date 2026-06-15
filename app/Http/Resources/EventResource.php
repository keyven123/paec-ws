<?php

namespace App\Http\Resources;

use App\Constants\GeneralConstants;
use App\Http\Repositories\EventRepository;
use App\Models\Transaction;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
class EventResource extends JsonResource
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
            // Ensure event_showcase is an array (it might be a JSON string or already an array)
            $showcaseArray = is_array($this->event_showcase)
                ? $this->event_showcase
                : (is_string($this->event_showcase) ? json_decode($this->event_showcase, true) : []);

            if (!empty($showcaseArray) && is_array($showcaseArray)) {
                $eventShowcase = Upload::whereIn('uuid', $showcaseArray)->select('uuid', 'path', 'disk')->get();
                $eventShowcase = $eventShowcase->map(function ($upload) {
                    $disk = $upload->disk ?? 'public';
                    $url = Storage::disk($disk)->url($upload->path);
                    return [
                        'uuid' => $upload->uuid,
                        'path' => $upload->path,
                        'url' => $url,
                        'disk' => $disk,
                    ];
                });
            }
        }
        return [
            'uuid' => $this->uuid,
            'venue_uuid' => $this->venue_uuid,
            'category_uuid' => $this->category_uuid,
            'event_section_uuid' => $this->event_section_uuid,
            'organization_uuid' => $this->organization_uuid,
            'event_section_name' => $this->eventSection?->name,
            'category' => $this->category,
            'event_name' => $this->event_name,
            'event_description' => $this->event_description,
            'contact_email' => $this->contact_email,
            'logo_uuid' => $this->logo_uuid,
            'portrait_image_uuid' => $this->portrait_image_uuid,
            'featured_image_uuid' => $this->featured_image_uuid,
            'event_showcase' => $eventShowcase,
            'address' => $this->address,
            'city' => $this->city,
            'event_config' => $this->event_config,
            'event_type' => $this->event_type,
            'schedule_type' => $this->schedule_type,
            'excluded_dates' => $this->excluded_dates,
            'published_at' => $this->published_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'registration_count' => $this->registration_count,
            'is_request_for_featured' => $this->is_request_for_featured,
            'is_featured' => $this->is_featured,
            'featured_order' => $this->featured_order,
            'featured_from' => $this->featured_from?->toISOString(),
            'featured_until' => $this->featured_until?->toISOString(),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'tags' => $this->tags,
            'track_event_meta' => $this->track_event_meta,
            'slug' => $this->slug,
            'meta_pixel_id' => $this->meta_pixel_id,
            'meta_pixel_key' => $this->meta_pixel_key,
            'other_info' => $this->other_info,
            'price_start' => $this->whenLoaded('eventTickets', fn () => $this->eventTickets->min('price')),
            'total_revenue' => app(EventRepository::class)->netRevenueAfterAffiliateFromTicketLines($this->uuid),
            'ticket_sold' => $this->tickets()->where('status', '!=', GeneralConstants::TICKET_STATUSES['TRANSFERRED'])->whereHas('transaction', function ($query) {
                $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
                    ->whereNotNull('payment_provider');
            })->count(),
            'total_orders' => $this->transactions()->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])->count(),
            'other_info_deadline' => $this->other_info_deadline?->toISOString(),
            'affiliate_enabled' => (bool) $this->affiliate_enabled,
            'affiliate_commission_percent' => $this->affiliate_commission_percent !== null ? (float) $this->affiliate_commission_percent : null,
            'affiliate_ends_at' => $this->affiliate_ends_at?->format('Y-m-d'),
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Computed fields
            'is_cancelled' => !is_null($this->cancelled_at),
            'is_completed' => !is_null($this->completed_at),
            'is_active' => is_null($this->cancelled_at) && is_null($this->completed_at),

            'has_seats' => $this->whenLoaded('venue', function () {
                if (!$this->venue) {
                    return false;
                }
                try {
                    return $this->venue->venueSeats && $this->venue->venueSeats->count() > 0;
                } catch (\Exception $e) {
                    return false;
                }
            }, false),

            // Relationships
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'uuid' => $this->organization->uuid,
                    'name' => $this->organization->name,
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
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->full_name,
                ];
            }),
            'updater' => $this->whenLoaded('updater', function () {
                return [
                    'uuid' => $this->updater->uuid,
                    'name' => $this->updater->full_name,
                ];
            }),
            'approvedBy' => $this->whenLoaded('approvedBy', function () {
                return [
                    'uuid' => $this->approvedBy->uuid,
                    'name' => $this->approvedBy->full_name,
                ];
            }),
            'schedules' => $this->whenLoaded('schedules', function () {
                return ScheduleResource::collection($this->schedules);
            }),
            'venue' => $this->whenLoaded('venue', function () {
                return new VenueResource($this->venue);
            }),
            'event_tickets' => $this->whenLoaded('eventTickets', function () {
                return EventTicketResource::collection($this->eventTickets);
            }),
            'event_locations' => $this->whenLoaded('eventLocations', function () {
                return EventLocationResource::collection($this->eventLocations);
            }),
        ];
    }
}
