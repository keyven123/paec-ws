<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
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
            'organization_uuid' => $this->organization_uuid,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'phone_number' => $this->phone_number,
            'status' => $this->status,
            'is_first_time_login' => $this->is_first_time_login,
            'email_verified_at' => $this->email_verified_at,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Organization information
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'uuid' => $this->organization->uuid,
                    'name' => $this->organization->name,
                    'status' => $this->organization->status,
                ];
            }) ?? null,

            // Role information
            'role' => $this->whenLoaded('role', function () {
                return [
                    'uuid' => $this->role->uuid,
                    'name' => $this->role->name,
                    'code' => $this->role->code,
                ];
            }),

            // Creator information
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => trim($this->creator->first_name . ' ' . $this->creator->last_name),
                ];
            }),

            // Updater information  
            'updater' => $this->whenLoaded('updater', function () {
                return [
                    'uuid' => $this->updater->uuid,
                    'name' => trim($this->updater->first_name . ' ' . $this->updater->last_name),
                ];
            }),
        ];
    }
}
