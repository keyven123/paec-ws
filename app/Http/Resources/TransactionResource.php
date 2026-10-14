<?php

namespace App\Http\Resources;

use App\Services\ActivityComplianceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'user_uuid' => $this->user_uuid,
            'transactionable_type' => $this->transactionable_type,
            'transactionable_uuid' => $this->transactionable_uuid,
            'user_uuid' => $this->user_uuid,
            'event_uuid' => $this->event_uuid,
            'organization_uuid' => $this->organization_uuid,
            'schedule_uuid' => $this->schedule_uuid,
            'schedule_time_uuid' => $this->schedule_time_uuid,
            'affiliate_partner_uuid' => $this->affiliate_partner_uuid,
            'promo_code_uuid' => $this->promo_code_uuid,
            'promo_code_discount' => $this->promo_code_discount !== null
                ? (float) $this->promo_code_discount
                : null,
            'paypal_order_id' => $this->paypal_order_id,
            'payment_provider' => $this->payment_provider,
            'payment_id' => $this->payment_id,
            'paybiz_wallet_id' => $this->when(auth('admin')->check(), $this->paybiz_wallet_id),
            'order_number' => $this->order_number,
            'order_qr_code' => $this->qr_code,
            'total_amount' => $this->total_amount,
            'sub_total' => $this->sub_total,
            'markup_amount' => $this->markup_amount ?? 0,
            'markup_discount' => $this->markup_discount ?? 0,
            'display_subtotal' => round(
                (float) $this->sub_total + (float) ($this->markup_amount ?? 0) + (float) ($this->markup_discount ?? 0),
                2,
            ),
            'display_discount' => round(
                (float) $this->discount + (float) ($this->markup_discount ?? 0),
                2,
            ),
            'tax_amount' => $this->tax_amount,
            'taxes_and_fees_label' => ((float) $this->tax_amount) > 0 ? 'Taxes and fees' : null,
            'compliance_included_note' => $this->complianceIncludedNoteFromSnapshots(),
            'discount' => $this->discount,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'order_status' => $this->order_status,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'user' => $this->whenLoaded('user', function () {
                return [
                    'uuid' => $this->user->uuid,
                    'name' => trim($this->user->first_name . ' ' . $this->user->last_name),
                    'email' => $this->user->email,
                    'phone_number' => $this->user->phone_number,
                ];
            }),
            'event' => $this->whenLoaded('event', function () {
                return [
                    'uuid' => $this->event->uuid,
                    'name' => $this->event->event_name,
                    'event_name' => $this->event->event_name,
                    'description' => $this->event->event_description,
                    'meta_pixel_id' => $this->event->meta_pixel_id,
                    'meta_test_event_code' => $this->event->meta_test_event_code,
                    'track_event_meta' => $this->event->track_event_meta,
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
            'promo_code' => $this->whenLoaded('promoCode', function () {
                return [
                    'uuid' => $this->promoCode->uuid,
                    'code' => $this->promoCode->code,
                    'description' => $this->promoCode->description,
                ];
            }),
            'affiliate_partner' => $this->whenLoaded('affiliatePartner', function () {
                return [
                    'uuid' => $this->affiliatePartner->uuid,
                    'name' => trim($this->affiliatePartner->first_name . ' ' . $this->affiliatePartner->last_name),
                    'email' => $this->affiliatePartner->email,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => trim($this->creator->first_name . ' ' . $this->creator->last_name),
                ];
            }),
            'tickets_count' => $this->whenCounted('tickets'),
            'tickets' => $this->whenLoaded('tickets', fn () => $this->tickets->map(fn ($ticket) => [
                'uuid' => $ticket->uuid,
                'ticket_number' => $ticket->ticket_number,
                'status' => $ticket->status,
                'ticket_type' => $ticket->eventTicket?->name,
                'attendee_name' => $ticket->attendee_name,
                'attendee_email' => $ticket->attendee_email,
                'attendee_contact' => $ticket->attendee_contact,
                'owner_email' => $ticket->relationLoaded('user') ? $ticket->user?->email : null,
                // Used, transferred or expired tickets can no longer be scanned, so their QR is withheld.
                'qr_code' => in_array($ticket->status, ['transferred', 'used', 'expired'], true)
                    ? null
                    : $ticket->qr_code,
                'seat' => $ticket->col !== null && $ticket->row !== null ? "{$ticket->col}-{$ticket->row}" : null,
                'location' => $ticket->eventLocation?->displayLabel(),
                'price' => $ticket->price,
                'valid_until' => $ticket->valid_until?->format('Y-m-d'),
                'used_at' => $ticket->used_at?->format('Y-m-d H:i:s'),
                'transferred_to' => $ticket->relationLoaded('transferredToUser') ? $ticket->transferredToUser?->email : null,
                'transferred_at' => $ticket->transferred_at?->format('Y-m-d H:i:s'),
            ])->values()),
            'transaction_orders' => $this->whenLoaded('transactionOrders', function () {
                return TempTransactionOrderResource::collection($this->transactionOrders);
            }),
            'venue_inquiry' => $this->when(
                $this->transactionable_type === 'venue_inquiry'
                    && $this->relationLoaded('transactionable')
                    && $this->transactionable !== null,
                fn () => new TransactionVenueInquiryResource($this->transactionable),
            ),

            $this->mergeWhen(
                $this->viewerIsPlatformAdmin($request) && $this->affiliate_partner_uuid,
                [
                    'affiliate_commission_amount' => $this->resolveAffiliateCommissionAmount(),
                    'affiliate_commission_percent' => $this->resolveAffiliateCommissionPercent(),
                ]
            ),
        ];
    }

    private function viewerIsPlatformAdmin(Request $request): bool
    {
        $user = auth('admin')->user();

        return (bool) ($user?->role?->is_admin);
    }

    private function resolveAffiliateCommissionAmount(): ?float
    {
        if ($this->relationLoaded('affiliateConversion') && $this->affiliateConversion !== null) {
            return (float) $this->affiliateConversion->commission_amount;
        }

        if ($this->relationLoaded('commissionLedger') && $this->commissionLedger !== null) {
            return (float) $this->commissionLedger->agent_commission;
        }

        return null;
    }

    private function resolveAffiliateCommissionPercent(): ?float
    {
        if ($this->relationLoaded('affiliateConversion') && $this->affiliateConversion !== null) {
            return (float) $this->affiliateConversion->commission_percent;
        }

        if ($this->relationLoaded('commissionLedger') && $this->commissionLedger !== null) {
            return (float) $this->commissionLedger->agent_commission_percent;
        }

        return null;
    }

    private function complianceIncludedNoteFromSnapshots(): ?string
    {
        if (! $this->relationLoaded('transactionCompliances')) {
            return null;
        }

        return ActivityComplianceService::buildIncludedNoteFromSnapshots($this->transactionCompliances);
    }
}
