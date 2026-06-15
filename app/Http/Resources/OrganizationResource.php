<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $defaultBank = $this->defaultBank();

        return [
            'uuid' => $this->uuid,
            'image' => $this->whenLoaded('image', function () {
                return [
                    'uuid' => $this->image->uuid,
                    'url' => $this->image->url,
                    'path' => $this->image->path,
                ];
            }),
            'name' => $this->name,
            'business_type' => $this->business_type,
            'representative_first_name' => $this->representative_first_name,
            'representative_last_name' => $this->representative_last_name,
            'address' => $this->address,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'banks' => OrganizationBankResource::collection($this->whenLoaded('banks')),
            'bank_name' => $defaultBank?->bank_name,
            'bank_branch' => $defaultBank?->bank_branch,
            'bank_address' => $defaultBank?->bank_address,
            'bank_account_name' => $defaultBank?->bank_account_name,
            'bank_account_number' => $defaultBank?->bank_account_number,
            'tin' => $this->tin,
            'description' => $this->description,
            'commission_percentage' => $this->commission_percentage !== null
                ? (float) $this->commission_percentage
                : null,
            'image_uuid' => $this->image_uuid,
            'status' => $this->status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'send_invite_count' => $this->send_invite_count,
            'payment_methods' => \App\Support\OrganizationPaymentMethods::normalize($this->payment_methods),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'approvedBy' => $this->whenLoaded('approvedBy', function () {
                return [
                    'uuid' => $this->approvedBy->uuid,
                    'name' => $this->approvedBy->full_name,
                ];
            }),
        ];
    }
}
