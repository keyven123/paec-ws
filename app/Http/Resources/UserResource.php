<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'profile_image_uuid' => $this->profile_image_uuid,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'phone_number' => $this->phone_number,
            'birth_date' => $this->birth_date,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'status' => $this->status,
            'is_first_time_login' => $this->is_first_time_login,
            'qr_code' => $this->qr_code,
            'marketing_consent' => $this->marketing_consent,
            'marketing_consent_date' => $this->marketing_consent_date,
            'terms_accepted_at' => $this->terms_accepted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Role information
            'role' => $this->whenLoaded('role', function () {
                return [
                    'uuid' => $this->role->uuid,
                    'name' => $this->role->name,
                    'code' => $this->role->code,
                ];
            }),

            'image' => $this->whenLoaded('profileImage', function () {
                return [
                    'uuid' => $this->profileImage->uuid,
                    'url' => $this->profileImage->url,
                    'path' => $this->profileImage->path,
                ];
            }),

            // Affiliate fields
            'affiliate_status' => $this->userAffiliate?->affiliate_status ?? 'none',
            'affiliate_code' => $this->userAffiliate?->affiliate_code,
            'affiliate_applied_at' => $this->userAffiliate?->affiliate_applied_at,
            'affiliate_approved_at' => $this->userAffiliate?->affiliate_approved_at,
            'affiliate_suspend_reason' => $this->userAffiliate?->affiliate_suspend_reason,
            'affiliate_suspended_at' => $this->userAffiliate?->affiliate_suspended_at,
        ];
    }
}
