<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliatePayoutRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'user_uuid' => $this->user_uuid,
            'amount_requested' => (float) $this->amount_requested,
            'currency' => $this->currency,
            'status' => $this->status,
            'admin_notes' => $this->admin_notes,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'customer' => $this->whenLoaded('user', function () {
                return [
                    'uuid' => $this->user->uuid,
                    'email' => $this->user->email,
                    'full_name' => $this->user->full_name,
                ];
            }),
            'bank_details' => $this->whenLoaded('user', function () {
                $affiliate = $this->user->userAffiliate;
                return [
                    'bank' => $affiliate?->affiliate_bank_name,
                    'branch' => $affiliate?->affiliate_bank_branch,
                    'account_name' => $affiliate?->affiliate_bank_account_name,
                    'account_number' => $affiliate?->affiliate_bank_account_number,
                    'tin' => $affiliate?->affiliate_bank_tin,
                ];
            }),
        ];
    }
}
