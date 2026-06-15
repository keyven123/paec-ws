<?php

namespace App\Http\Resources;

use App\Constants\GeneralConstants;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventTicketResource extends JsonResource
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
            'event_uuid' => $this->event_uuid,
            'schedule_uuid' => $this->schedule_uuid,
            'schedule_time_uuid' => $this->schedule_time_uuid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'ticket_code' => $this->ticket_code,
            'is_bundle' => $this->is_bundle,
            'bundle_quantity' => $this->bundle_quantity,
            'bundle_tickets' => $this->bundle_tickets,
            'available_from' => $this->available_from?->format('Y-m-d H:i:s'),
            'available_to' => $this->available_to?->format('Y-m-d H:i:s'),
            'visit_policy' => $this->visit_policy,
            'validity_days' => $this->validity_days,
            'bg_color' => $this->bg_color,
            'display_order' => $this->display_order,
            'with_coupon' => $this->coupons->count() > 0,
            'max_ticket' => $this->max_ticket ?? 0,
            'is_virtual' => $this->is_virtual,
            'ticket_limit_per_user' => $this->ticket_limit_per_user,
            'virtual_event_url' => $this->virtual_event_url,
            'sold_ticket' => $this->tickets()->whereNotNull('qr_code')->whereNull('deleted_at')->whereIn(
                'status',
                [
                    GeneralConstants::TICKET_STATUSES['ACTIVE'],
                    GeneralConstants::TICKET_STATUSES['PENDING'],
                    GeneralConstants::TICKET_STATUSES['EXPIRED'],
                ]
            )->count(),
            'is_unlimited' => $this->is_unlimited,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relationships
            'event' => $this->whenLoaded('event', function () {
                return [
                    'uuid' => $this->event->uuid,
                    'name' => $this->event->event_name,
                    'description' => $this->event->event_description,
                ];
            }),
            'schedule' => $this->whenLoaded('schedule', function () {
                return [
                    'uuid' => $this->schedule->uuid,
                    'date_from' => $this->schedule->date_from?->format('Y-m-d'),
                    'date_to' => $this->schedule->date_to?->format('Y-m-d'),
                ];
            }),
            'schedule_time' => $this->whenLoaded('scheduleTime', function () {
                return [
                    'uuid' => $this->scheduleTime->uuid,
                    'time_start' => $this->scheduleTime->time_start,
                    'time_end' => $this->scheduleTime->time_end,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->first_name . ' ' . $this->creator->last_name,
                ];
            }),
            'coupons' => $this->whenLoaded('coupons', function () {
                return $this->coupons->map(fn ($c) => [
                    'uuid' => $c->uuid,
                    'name' => $c->name,
                    'once_only' => $c->once_only ?? false,
                ]);
            }),
        ];
    }
}
