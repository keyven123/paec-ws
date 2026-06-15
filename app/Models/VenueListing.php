<?php

namespace App\Models;

use App\Contracts\Blockable;
use App\Models\Concerns\HasBlockedDates;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class VenueListing extends Model implements Blockable
{
    use HasUuids;
    use HasFactory;
    use HasBlockedDates;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    public const STATUSES = [
        'DRAFT' => 'draft',
        'PENDING' => 'pending',
        'APPROVED' => 'approved',
        'PUBLISHED' => 'published',
        'INACTIVE' => 'inactive',
    ];

    public const CATEGORIES = [
        'FUNCTION_HALLS' => 'function-halls',
        'CONFERENCE' => 'conference',
        'BALLROOMS' => 'ballrooms',
        'OUTDOOR' => 'outdoor',
        'LOFT' => 'loft',
    ];

    protected $fillable = [
        'organization_uuid',
        'slug',
        'name',
        'description',
        'address',
        'location',
        'city',
        'region',
        'area',
        'capacity_label',
        'capacity_min',
        'capacity_max',
        'venue_type',
        'category',
        'price_per_event',
        'currency',
        'status',
        'is_featured',
        'badge',
        'rating',
        'review_count',
        'inquiries_count',
        'bookings_count',
        'image_color',
        'verified',
        'responds_in',
        'packages',
        'default_package_id',
        'min_capacity_note',
        'max_capacity_note',
        'setups',
        'specs',
        'best_for',
        'amenities',
        'reviews',
        'created_by',
        'updated_by',
    ];

    const DATA = [
        'organization_uuid',
        'slug',
        'name',
        'description',
        'address',
        'location',
        'city',
        'region',
        'area',
        'capacity_label',
        'capacity_min',
        'capacity_max',
        'venue_type',
        'category',
        'price_per_event',
        'currency',
        'status',
        'is_featured',
        'badge',
        'rating',
        'review_count',
        'inquiries_count',
        'bookings_count',
        'image_color',
        'verified',
        'responds_in',
        'packages',
        'default_package_id',
        'min_capacity_note',
        'max_capacity_note',
        'setups',
        'specs',
        'best_for',
        'amenities',
        'reviews',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_featured'    => 'boolean',
        'verified'       => 'boolean',
        'price_per_event' => 'decimal:2',
        'rating'         => 'decimal:1',
        'packages'       => 'array',
        'setups'         => 'array',
        'specs'          => 'array',
        'best_for'       => 'array',
        'amenities'      => 'array',
        'reviews'        => 'array',
    ];

    /**
     * @param Builder $query
     * @param array|null $filters
     * @return Builder
     */
    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (!empty($filters['q'])) {
            $keyword = $filters['q'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'LIKE', "%{$keyword}%")
                    ->orWhere('city', 'LIKE', "%{$keyword}%")
                    ->orWhere('venue_type', 'LIKE', "%{$keyword}%")
                    ->orWhere('location', 'LIKE', "%{$keyword}%");
            });
        }

        if (!empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'LIKE', "%{$keyword}%")
                    ->orWhere('city', 'LIKE', "%{$keyword}%")
                    ->orWhere('venue_type', 'LIKE', "%{$keyword}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['location'])) {
            $location = $filters['location'];
            if (strtolower($location) !== 'metro manila (all)') {
                $query->where(function (Builder $builder) use ($location) {
                    $builder->where('city', 'LIKE', "%{$location}%")
                        ->orWhere('location', 'LIKE', "%{$location}%");
                });
            }
        }

        if (!empty($filters['guests'])) {
            $guests = (int) $filters['guests'];
            $query->where('capacity_min', '<=', $guests)
                ->where('capacity_max', '>=', $guests);
        }

        if (!empty($filters['organization_uuid'])) {
            $query->where('organization_uuid', $filters['organization_uuid']);
        }

        return $query;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(VenueInquiry::class, 'venue_listing_uuid', 'uuid');
    }

    /**
     * All uploads attached to this venue listing across all collections.
     */
    public function uploads(): MorphMany
    {
        return $this->morphMany(Upload::class, 'uploadable', 'uploadable_type', 'uploadable_uuid', 'uuid')
                    ->orderBy('order_number');
    }

    /**
     * The single featured/hero image for this venue listing.
     */
    public function featuredImage(): MorphOne
    {
        return $this->morphOne(Upload::class, 'uploadable', 'uploadable_type', 'uploadable_uuid', 'uuid')
                    ->where('collection', 'featured');
    }

    /**
     * Ordered gallery images for this venue listing.
     */
    public function gallery(): MorphMany
    {
        return $this->morphMany(Upload::class, 'uploadable', 'uploadable_type', 'uploadable_uuid', 'uuid')
                    ->where('collection', 'gallery')
                    ->orderBy('order_number');
    }
}
