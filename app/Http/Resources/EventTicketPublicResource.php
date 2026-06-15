<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Services\TicketMarkupService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventTicketPublicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userUuid = $request->user()?->uuid;
        $boughtTicket = 0;
        if ($userUuid) {
            // Count tickets bought for THIS event ticket (not all tickets in the event)
            $boughtTicket = $this->tickets()
                ->where('user_uuid', $userUuid)
                ->whereHas('transaction', function ($query) {
                    $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
                })
                ->count();
        }

        $limit = $this->ticket_limit_per_user;
        $remaining = $limit !== null ? max(0, (int) $limit - (int) $boughtTicket) : null;

        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price' => TicketMarkupService::displayUnitPrice($this->resource),
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'is_bundle' => $this->is_bundle,
            'bundle_tickets' => $this->bundle_tickets,
            'bundle_quantity' => $this->bundle_quantity,
            'available_from' => $this->available_from?->format('Y-m-d H:i:s'),
            'available_to' => $this->available_to?->format('Y-m-d H:i:s'),
            'bg_color' => $this->bg_color,
            'display_order' => $this->display_order,
            'is_unlimited' => $this->is_unlimited,
            'available_tickets' => $this->is_unlimited ? 999999999 : $this->max_ticket - $this->sold_ticket,
            'status' => $this->status,
            'is_virtual' => $this->is_virtual,
            'visit_policy' => $this->visit_policy,
            'validity_days' => $this->validity_days,
            'ticket_limit_per_user' => $limit,
            'bought_ticket_count' => $boughtTicket,
            'remaining_ticket_limit_per_user' => $remaining,

            'schedule_time' => $this->whenLoaded('scheduleTime', function () {
                return [
                    'uuid' => $this->scheduleTime->uuid,
                    'time_start' => $this->scheduleTime->time_start,
                    'time_end' => $this->scheduleTime->time_end,
                    'schedule' => $this->whenLoaded('scheduleTime.schedule', function () {
                        return [
                            'uuid' => $this->scheduleTime->schedule->uuid,
                            'date_from' => $this->scheduleTime->schedule->date_from?->format('Y-m-d'),
                            'date_to' => $this->scheduleTime->schedule->date_to?->format('Y-m-d'),
                        ];
                    }),
                ];
            }),
        ];
    }
}
