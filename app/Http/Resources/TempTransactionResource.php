<?php

namespace App\Http\Resources;

use App\Services\ActivityComplianceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TempTransactionResource extends JsonResource
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
            'event_uuid' => $this->event_uuid,
            'schedule_uuid' => $this->schedule_uuid,
            'schedule_time_uuid' => $this->schedule_time_uuid,
            'organization_uuid' => $this->organization_uuid,
            'voucher_uuid' => $this->voucher_uuid,
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
            'discount' => $this->discount,
            'total_amount' => $this->total_amount,
            'compliance_included_note' => $this->complianceIncludedNote(),
            'promo_code_uuid' => $this->promo_code_uuid,
            'promo_code_discount' => $this->promo_code_discount,
            'schedule' => $this->whenLoaded('schedule', function () {
                return ScheduleResource::make($this->schedule);
            }),
            'scheduleTime' => $this->whenLoaded('scheduleTime', function () {
                return ScheduleTimeResource::make($this->scheduleTime);
            }),
            'temp_transaction_orders' => $this->whenLoaded('tempTransactionOrders', function () {
                return TempTransactionOrderResource::collection($this->tempTransactionOrders);
            }),
        ];
    }

    private function complianceIncludedNote(): ?string
    {
        $event = $this->relationLoaded('event') ? $this->event : null;
        if (! $event) {
            return null;
        }

        return ActivityComplianceService::applyToCheckoutAmounts($event, [
            'sub_total' => $this->sub_total,
            'discount' => $this->discount,
            'promo_code_discount' => $this->promo_code_discount ?? 0,
            'markup_amount' => $this->markup_amount ?? 0,
        ])['included_note'];
    }
}
