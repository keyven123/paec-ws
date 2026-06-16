<?php

namespace App\Models;

use App\Constants\GeneralConstants;
use App\Contracts\Blockable;
use App\Http\Repositories\EventRepository;
use App\Models\Concerns\HasBlockedDates;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model implements Blockable
{
    use HasUuids;
    use HasFactory;
    use HasBlockedDates;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'venue_uuid',
        'category_uuid',
        'event_section_uuid',
        'organization_uuid',
        'event_name',
        'event_description',
        'contact_email',
        'total_revenue',
        'ticket_sold',
        'total_orders',
        'address',
        'city',
        'logo_uuid',
        'portrait_image_uuid',
        'featured_image_uuid',
        'event_showcase',
        'event_config',
        'event_type',
        'schedule_type',
        'ticket_prefix',
        'excluded_dates',
        'published_at',
        'cancelled_at',
        'completed_at',
        'registration_count',
        'approved_at',
        'approved_by',
        'is_request_for_featured',
        'is_featured',
        'featured_order',
        'featured_from',
        'featured_until',
        'meta_title',
        'meta_description',
        'tags',
        'track_event_meta',
        'meta_pixel_id',
        'meta_pixel_key',
        'meta_test_event_code',
        'slug',
        'status',
        'blocked_seats',
        'today_cutoff_time',
        'other_info',
        'other_info_deadline',
        'affiliate_enabled',
        'affiliate_commission_percent',
        'affiliate_ends_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'event_showcase' => 'array',
        'excluded_dates' => 'array',
        'published_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'featured_from' => 'datetime',
        'featured_until' => 'datetime',
        'registration_count' => 'integer',
        'is_request_for_featured' => 'boolean',
        'is_featured' => 'boolean',
        'featured_order' => 'integer',
        'tags' => 'array',
        'track_event_meta' => 'boolean',
        'blocked_seats' => 'array',
        'other_info' => 'array',
        'other_info_deadline' => 'datetime',
        'affiliate_enabled' => 'boolean',
        'affiliate_commission_percent' => 'decimal:2',
        'affiliate_ends_at' => 'date',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'venue_uuid',
        'category_uuid',
        'event_section_uuid',
        'organization_uuid',
        'event_name',
        'event_description',
        'contact_email',
        'total_revenue',
        'ticket_sold',
        'total_orders',
        'address',
        'city',
        'logo_uuid',
        'portrait_image_uuid',
        'featured_image_uuid',
        'event_showcase',
        'event_config',
        'event_type',
        'schedule_type',
        'schedule_uuids',
        'ticket_prefix',
        'excluded_dates',
        'published_at',
        'cancelled_at',
        'completed_at',
        'registration_count',
        'is_request_for_featured',
        'is_featured',
        'approved_at',
        'approved_by',
        'featured_order',
        'featured_from',
        'featured_until',
        'meta_title',
        'meta_description',
        'tags',
        'track_event_meta',
        'meta_pixel_id',
        'meta_pixel_key',
        'meta_test_event_code',
        'slug',
        'status',
        'blocked_seats',
        'today_cutoff_time',
        'other_info',
        'other_info_deadline',
        'affiliate_enabled',
        'affiliate_commission_percent',
        'affiliate_ends_at',
        'created_by',
        'updated_by',
    ];

    /**
     * Affiliate program is still within its configured end calendar day (app timezone), or has no end date.
     */
    public function scopeWhereAffiliateProgramNotPastEndDate(Builder $query): Builder
    {
        $today = Carbon::now((string) config('app.timezone', 'UTC'))->toDateString();

        return $query->where(function (Builder $q) use ($today) {
            $q->whereNull('affiliate_ends_at')
                ->orWhereDate('affiliate_ends_at', '>=', $today);
        });
    }

    // Event categories
    const CATEGORIES = [
        'CONFERENCE' => 'Conference',
        'WORKSHOP' => 'Workshop',
        'SEMINAR' => 'Seminar',
        'NETWORKING' => 'Networking',
        'CONCERT' => 'Concert',
        'FESTIVAL' => 'Festival',
        'SPORTS' => 'Sports',
        'EXHIBITION' => 'Exhibition',
        'OTHERS' => 'Others',
    ];

    // Event config options
    const EVENT_CONFIGS = [
        'OPEN_TICKET' => 'open_ticket',
        'SEAT_SELECTION' => 'seat_selection',
        'PRIVATE_EVENT' => 'private_event',
    ];

    // Event types
    const EVENT_TYPES = [
        'SINGLE' => 'single',
        'MULTIPLE' => 'multiple',
        'DAILY' => 'daily'
    ];

    // Schedule types
    const SCHEDULE_TYPES = [
        'SINGLE' => 'single',
        'DAILY' => 'daily',
        'CUSTOM_DATE' => 'custom_date',
    ];

    // Fun types
    const FUN_TYPES = [
        'PROMO' => 'promo',
        'NEW' => 'new',
    ];

    /**
     * Scope for filtering records
     * @param Builder $query
     * @param array|null $filters
     * @return Builder
     */
    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (isset($filters['q'])) {
            $qKeyword = $filters['q'];
            $query = $query->where(function ($q) use ($qKeyword) {
                $q->where('event_name', 'LIKE', "%$qKeyword%")
                    ->orWhere('event_description', 'LIKE', "%$qKeyword%")
                    ->orWhere('contact_email', 'LIKE', "%$qKeyword%")
                    ->orWhere('meta_title', 'LIKE', "%$qKeyword%")
                    ->orWhere('meta_description', 'LIKE', "%$qKeyword%");
            });
        }

        if (isset($filters['address'])) {
            $query = $query->where('address', 'LIKE', "%{$filters['address']}%");
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['category_uuid'])) {
            $query = $query->where('category_uuid', $filters['category_uuid']);
        }

        if (isset($filters['venue_uuid'])) {
            $query = $query->where('venue_uuid', $filters['venue_uuid']);
        }

        if (isset($filters['event_type'])) {
            $query = $query->where('event_type', $filters['event_type']);
        }

        if (isset($filters['schedule_type'])) {
            $query = $query->where('schedule_type', $filters['schedule_type']);
        }

        if (isset($filters['is_featured'])) {
            $query = $query->where('is_featured', $filters['is_featured']);
        }

        if (isset($filters['organization_uuid'])) {
            $query = $query->where('organization_uuid', $filters['organization_uuid']);
        }

        if (isset($filters['event_section_type'])) {
            $query = $query->whereHas('eventSection', function (Builder $q) use ($filters) {
                $q->where('name', $filters['event_section_type']);
            })->when($filters['event_section_type'] === EventSection::FEATURED_SECTION, function (Builder $q) {
                $q->where('is_featured', true);
            });
        }

        if (isset($filters['event_section_types'])) {
            $query = $query->whereHas('eventSection', function (Builder $q) use ($filters) {
                $q->whereIn('name', $filters['event_section_types']);
            });
        }

        if (isset($filters['available_event'])) {
            $query = $query->whereStatus(GeneralConstants::EVENT_STATUSES['PUBLISHED'])
                ->whereHas('eventSection', function (Builder $q) {
                    $q->where('name', '=', EventSection::FEATURED_SECTION);
                })->where('is_featured', false);
        }

        if (isset($filters['published'])) {
            if ($filters['published']) {
                $query = $query->whereStatus(GeneralConstants::EVENT_STATUSES['PUBLISHED']);
            } else {
                $query = $query->whereIn('status', [GeneralConstants::EVENT_STATUSES['DRAFT'], GeneralConstants::EVENT_STATUSES['PENDING']]);
            }
        }

        if (isset($filters['is_request_for_featured'])) {
            $query = $query->where('is_request_for_featured', $filters['is_request_for_featured']);
        }

        if (isset($filters['affiliate_catalog'])) {
            if ($filters['affiliate_catalog'] === 'fun') {
                $query->whereHas('eventSection', function (Builder $q) {
                    $q->where('name', EventSection::AMUSEMENT_SECTION);
                });
            } else {
                $query->where(function (Builder $q) {
                    $q->whereDoesntHave('eventSection')
                        ->orWhereHas('eventSection', function (Builder $section) {
                            $section->where('name', '!=', EventSection::AMUSEMENT_SECTION);
                        });
                });
            }
        }

        if (isset($filters['fun_type'])) {
            if ($filters['fun_type'] === Event::FUN_TYPES['PROMO']) {
                $query = $query->whereHas('eventTickets', function (Builder $q) {
                    $q->whereNotNull('discount_type')
                        ->where('discount_value', '>', 0);
                });
            }
            if ($filters['fun_type'] === Event::FUN_TYPES['NEW']) {
                $query = $query->whereHas('eventTickets', function (Builder $q) {
                    $q->whereNull('discount_type');
                });
            }
        }

        return $query;
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    // Relationships
    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'uuid');
    }

    public function updater()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'uuid');
    }

    public function approvedBy()
    {
        return $this->belongsTo(AdminUser::class, 'approved_by', 'uuid');
    }

    // Scopes for common queries
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED']);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', GeneralConstants::EVENT_STATUSES['DRAFT']);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        $eventSection = EventSection::where('name', EventSection::FEATURED_SECTION)->first();
        return $query->where('is_featured', true);
            // ->where(function (Builder $q) {
            //     $q->whereNull('featured_from')
            //         ->orWhere('featured_from', '<=', now());
            // })
            // ->where(function (Builder $q) {
            //     $q->whereNull('featured_until')
            //         ->orWhere('featured_until', '>=', now());
            // });
    }

    public function scopeOpenPass(Builder $query): Builder
    {
        return $query->whereHas('eventSection', function (Builder $q) {
            $q->where('name', EventSection::OPEN_PASS_SECTION);
        });
    }

    public function scopeNewEvent(Builder $query): Builder
    {
        return $query->whereHas('eventSection', function (Builder $q) {
            $q->where('name', EventSection::NEW_EVENT_SECTION);
        });
    }

    public function scopeAmusement(Builder $query): Builder
    {
        return $query->whereHas('eventSection', function (Builder $q) {
            $q->where('name', EventSection::AMUSEMENT_SECTION);
        });
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->whereNull('completed_at')
            ->whereNotNull('published_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->whereNull('completed_at');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function eventLocations(): HasMany
    {
        return $this->hasMany(EventLocation::class, 'event_uuid', 'uuid')
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function eventTickets(): HasMany
    {
        return $this->hasMany(EventTicket::class);
    }

    public function activityCompliances(): MorphMany
    {
        return $this->morphMany(ActivityCompliance::class, 'activityable');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function logo()
    {
        return $this->belongsTo(Upload::class, 'logo_uuid', 'uuid');
    }
    public function portraitImage()
    {
        return $this->belongsTo(Upload::class, 'portrait_image_uuid', 'uuid');
    }
    public function featuredImage()
    {
        return $this->belongsTo(Upload::class, 'featured_image_uuid', 'uuid');
    }

    public function uploads(): MorphMany
    {
        return $this->morphMany(Upload::class, 'uploadable', 'uploadable_type', 'uploadable_uuid', 'uuid');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function eventSection()
    {
        return $this->belongsTo(EventSection::class);
    }

    /**
     * A date cannot be blocked when paid tickets are already scheduled on it.
     */
    public function hasBlockedDateConflict(string $date): bool
    {
        return app(EventRepository::class)->hasPaidTicketsScheduledOnDate($this->uuid, $date);
    }

    /**
     * Scope for filtering records by organization
     * @param Builder $query
     * @param string|null $organizationUuid
     * @return Builder
     */
    public function scopeByOrganization(Builder $query): Builder
    {
        if (auth('admin')->user() && !auth('admin')->user()->role->is_admin) {
            return $query->where('organization_uuid', auth('admin')->user()->organization_uuid);
        }
        return $query;
    }

    // For showcases (JSON array of UUIDs), a helper accessor:
    public function getShowcaseUploadsAttribute()
    {
        $uuids = collect($this->event_showcases ?? []);

        if ($uuids->isEmpty()) {
            return collect();
        };

        return Upload::query()
            ->whereIn('uuid', $uuids)
            ->get()
            ->sortBy(fn($u) => $uuids->search($u->uuid)) // keep original order
            ->values();
    }

    public function formattedTodayCutoffTime(): ?string
    {
        if (empty($this->today_cutoff_time)) {
            return null;
        }

        return substr((string) $this->today_cutoff_time, 0, 5);
    }

    public function isVisitDateBookable(string $visitDate, ?Carbon $now = null): bool
    {
        $timezone = (string) config('app.timezone', 'Asia/Manila');
        $now = $now ?? now($timezone);
        $visit = Carbon::parse($visitDate, $timezone)->startOfDay();
        $today = $now->copy()->startOfDay();

        if ($visit->lt($today)) {
            return false;
        }

        if ($visit->equalTo($today) && !empty($this->today_cutoff_time)) {
            $cutoff = Carbon::parse(
                $today->toDateString() . ' ' . $this->today_cutoff_time,
                $timezone,
            );

            return $now->lt($cutoff);
        }

        return true;
    }
}
