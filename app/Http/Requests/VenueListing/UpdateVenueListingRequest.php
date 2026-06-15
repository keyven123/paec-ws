<?php

namespace App\Http\Requests\VenueListing;

use App\Models\VenueListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVenueListingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $uuid = $this->route('uuid');

        return [
            'organization_uuid' => ['nullable', 'uuid', 'exists:organizations,uuid'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('venue_listings', 'slug')->ignore($uuid, 'uuid')],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'capacity_label' => ['nullable', 'string', 'max:100'],
            'capacity_min' => ['nullable', 'integer', 'min:1'],
            'capacity_max' => ['nullable', 'integer', 'min:1'],
            'venue_type' => ['sometimes', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(array_values(VenueListing::CATEGORIES))],
            'price_per_event' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', Rule::in(array_values(VenueListing::STATUSES))],
            'is_featured' => ['nullable', 'boolean'],
            'badge' => ['nullable', 'string', 'max:50'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'review_count' => ['nullable', 'integer', 'min:0'],
            'image_color' => ['nullable', 'string', 'max:7'],
            'featured_image_url' => ['nullable', 'string', 'max:2048'],
            'gallery_image_urls' => ['nullable', 'array'],
            'gallery_image_urls.*' => ['nullable', 'string', 'max:2048'],
            'verified' => ['nullable', 'boolean'],
            'responds_in' => ['nullable', 'string', 'max:50'],
            'photo_count' => ['nullable', 'integer', 'min:0'],
            'gallery_colors' => ['nullable', 'array'],
            'packages' => ['nullable', 'array'],
            'packages.*.id' => ['nullable', 'string', 'max:100'],
            'packages.*.label' => ['required_with:packages', 'string', 'max:255'],
            'packages.*.priceFrom' => ['nullable', 'numeric', 'min:0'],
            'packages.*.note' => ['nullable', 'string', 'max:500'],
            'packages.*.start_time' => ['required_with:packages', 'regex:/^\d{1,2}:\d{2}$/'],
            'packages.*.end_time' => ['required_with:packages', 'regex:/^\d{1,2}:\d{2}$/'],
            'packages.*.crosses_midnight' => ['nullable', 'boolean'],
            'default_package_id' => ['nullable', 'string', 'max:50'],
            'min_capacity_note' => ['nullable', 'string', 'max:255'],
            'max_capacity_note' => ['nullable', 'string', 'max:255'],
            'setups' => ['nullable', 'array'],
            'specs' => ['nullable', 'array'],
            'best_for' => ['nullable', 'array'],
            'amenities' => ['nullable', 'array'],
            'reviews' => ['nullable', 'array'],
        ];
    }
}
