<?php

namespace App\Http\Resources;

use App\Services\TicketPurchasePricingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $schedule = $this->transaction->schedule ? [
            'uuid' => $this->transaction->schedule->uuid,
            'date_from' => $this->transaction->schedule->date_from?->format('Y-m-d'),
            'date_to' => $this->transaction->schedule->date_to?->format('Y-m-d'),
        ] : null;
        $scheduleTime = $this->transaction->scheduleTime ? [
            'uuid' => $this->transaction->scheduleTime->uuid,
            'time_start' => $this->transaction->scheduleTime->time_start,
            'time_end' => $this->transaction->scheduleTime->time_end,
        ] : null;
        $isPastDue = $this->isSchedulePastDue()
            || in_array($this->status, ['used', 'expired'], true);
        $dateOfVisit = $this->resolveDateOfVisit();

        return [
            'uuid' => $this->uuid,
            'user_uuid' => $this->user_uuid,
            'transaction_uuid' => $this->transaction_uuid,
            'event_uuid' => $this->event_uuid,
            'event_ticket_uuid' => $this->event_ticket_uuid,
            'col' => $this->col,
            'row' => $this->row,
            'status' => $this->status,
            'ticket_number' => $this->ticket_number,
            'attendee_name' => $this->attendee_name,
            'attendee_email' => $this->attendee_email,
            'attendee_contact' => $this->attendee_contact,
            'is_past_due' => $isPastDue,
            'qr_code' => in_array($this->status, ['transferred', 'used', 'expired'], true) || $isPastDue
                ? null
                : $this->qr_code,
            'visit_policy' => $this->visit_policy,
            'date_of_visit' => $dateOfVisit?->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d H:i:s'),
            'used_at' => $this->used_at?->format('Y-m-d H:i:s'),
            'transferred_at' => $this->transferred_at?->format('Y-m-d H:i:s'),
            'transfer_count' => $this->transfer_count,
            'transferred_to_user' => $this->whenLoaded('transferredToUser', function () {
                if (!$this->transferredToUser) {
                    return null;
                }

                return [
                    'uuid' => $this->transferredToUser->uuid,
                    'name' => trim($this->transferredToUser->first_name . ' ' . $this->transferredToUser->last_name),
                    'email' => $this->transferredToUser->email,
                ];
            }),
            'is_downloaded' => $this->is_downloaded,
            'price' => $this->price,
            'discount' => $this->discount,
            'gross_revenue' => TicketPurchasePricingService::customerGrossRevenuePerTicket($this->resource),
            'other_info' => $this->other_info,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'event_location' => $this->formatEventLocation(),
            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'uuid' => $this->user->uuid,
                    'name' => $this->user->first_name . ' ' . $this->user->last_name,
                    'email' => $this->user->email,
                ];
            }),
            'transaction' => $this->whenLoaded('transaction', function ($transaction) {
                return [
                    'uuid' => $transaction->uuid,
                    'order_number' => $transaction->order_number,
                    'total_amount' => $transaction->total_amount,
                    'status' => $transaction->status,
                    'payment_status' => $transaction->payment_status,
                    'order_status' => $transaction->order_status,
                    'paid_at' => $transaction->paid_at?->format('Y-m-d H:i:s'),
                ];
            }),
            'schedule' => $schedule,
            'schedule_time' => $scheduleTime,
            'event' => $this->whenLoaded('event', function () {
                return [
                    'uuid' => $this->event->uuid,
                    'name' => $this->event->event_name,
                    'organizer_name' => $this->event->organization?->name,
                    'description' => $this->event->event_description,
                    'address' => $this->event->address,
                    'other_info_deadline' => $this->event->other_info_deadline?->format('Y-m-d H:i:s'),
                    'portrait' => $this->event->portraitImage ? [
                        'path' => $this->event->portraitImage->path,
                        'url' => $this->event->portraitImage->url,
                    ] : null,
                    'featured' => $this->event->featuredImage ? [
                        'path' => $this->event->featuredImage->path,
                        'url' => $this->event->featuredImage->url,
                    ] : null,
                ];
            }),
            'event_ticket' => $this->whenLoaded('eventTicket', function () use ($schedule) {
                return [
                    'uuid' => $this->eventTicket->uuid,
                    'name' => $this->eventTicket->name,
                    'code' => $this->eventTicket->code,
                    'price' => $this->eventTicket->price,
                    'is_virtual' => $this->eventTicket->is_virtual,
                    'virtual_event_url' => $this->eventTicket->is_virtual
                        && is_array($schedule)
                        && !empty($schedule['date_from'])
                        && Carbon::now()->format('Y-m-d') === Carbon::parse($schedule['date_from'])->format('Y-m-d')
                        ? $this->eventTicket->virtual_event_url
                        : null,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->first_name . ' ' . $this->creator->last_name,
                ];
            }),
            'venue_seat' => $this->whenLoaded('venueSeat', function () {
                if (!$this->venueSeat) {
                    return null;
                }

                $venue = $this->venueSeat->venue;

                return [
                    'uuid' => $this->venueSeat->uuid,
                    'venue' => $venue ? [
                        'uuid' => $venue->uuid,
                        'name' => $venue->name,
                    ] : null,
                ];
            }),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatEventLocation(): ?array
    {
        $location = $this->relationLoaded('eventLocation')
            ? $this->eventLocation
            : ($this->event_location_uuid ? $this->eventLocation()->first() : null);

        if (!$location) {
            return null;
        }

        return [
            'uuid' => $location->uuid,
            'name' => $location->name,
            'city' => $location->city,
            'address' => $location->address,
            'label' => $location->displayLabel(),
        ];
    }
}
