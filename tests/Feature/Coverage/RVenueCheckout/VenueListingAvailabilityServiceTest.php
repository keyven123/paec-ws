<?php

namespace Tests\Feature\Coverage\RVenueCheckout;

use App\Models\BlockedDate;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Services\VenueListingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueListingAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): VenueListingAvailabilityService
    {
        return app(VenueListingAvailabilityService::class);
    }

    #[Test]
    public function publicUnavailableDatesDeduplicatesAcrossBlocksSoftHoldsAndBookings(): void
    {
        $venue = VenueListing::factory()->published()->create();

        // Same calendar day stored in two textual formats (date-only legacy row + datetime row).
        foreach (['2026-12-01', '2026-12-01 00:00:00'] as $stored) {
            DB::table('blocked_dates')->insert([
                'uuid' => (string) Str::uuid(),
                'blockable_type' => $venue->getMorphClass(),
                'blockable_uuid' => $venue->uuid,
                'blocked_date' => $stored,
                'reason' => 'Maintenance',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        VenueInquiry::factory()->create([
            'venue_listing_uuid' => $venue->uuid,
            'status' => VenueInquiry::STATUSES['DEPOSIT_PAID'],
            'event_date' => '2026-12-01',
        ]);
        VenueInquiry::factory()->create([
            'venue_listing_uuid' => $venue->uuid,
            'status' => VenueInquiry::STATUSES['BALANCE_DUE'],
            'event_date' => '2026-12-05',
        ]);
        VenueInquiry::factory()->create([
            'venue_listing_uuid' => $venue->uuid,
            'status' => VenueInquiry::STATUSES['FULLY_PAID'],
            'event_date' => '2026-12-05',
        ]);
        VenueInquiry::factory()->create([
            'venue_listing_uuid' => $venue->uuid,
            'status' => VenueInquiry::STATUSES['COMPLETED'],
            'event_date' => '2026-12-09',
        ]);

        $dates = $this->service()->publicUnavailableDates($venue);

        $this->assertSame(
            [
                ['2026-12-01', 'Maintenance'],
                ['2026-12-05', 'Reserved'],
                ['2026-12-09', 'Booked'],
            ],
            array_map(fn (array $row) => [$row['blocked_date'], $row['reason']], $dates),
        );
        $this->assertNotNull($dates[0]['uuid']);
        $this->assertNull($dates[1]['uuid']);
        $this->assertNull($dates[2]['uuid']);
    }

    #[Test]
    public function softHoldMakesDateUnavailable(): void
    {
        $venue = VenueListing::factory()->published()->create();
        VenueInquiry::factory()->create([
            'venue_listing_uuid' => $venue->uuid,
            'status' => VenueInquiry::STATUSES['DEPOSIT_PAID'],
            'event_date' => '2026-11-20',
        ]);

        $this->assertTrue($this->service()->isDateUnavailable($venue, '2026-11-20'));
        $this->assertFalse($this->service()->isDateUnavailable($venue, '2026-11-21'));
    }

    #[Test]
    public function manualBlockMakesDateUnavailable(): void
    {
        $venue = VenueListing::factory()->published()->create();
        BlockedDate::create([
            'blockable_type' => $venue->getMorphClass(),
            'blockable_uuid' => $venue->uuid,
            'blocked_date' => '2026-11-22',
            'reason' => 'Private event',
        ]);

        $this->assertTrue($this->service()->isDateUnavailable($venue, '2026-11-22'));
    }

    #[Test]
    public function normalizeDateHandlesNullCarbonAndRawStrings(): void
    {
        $method = new \ReflectionMethod(VenueListingAvailabilityService::class, 'normalizeDate');
        $service = $this->service();

        $this->assertNull($method->invoke($service, null));
        $this->assertSame('2026-10-01', $method->invoke($service, Carbon::parse('2026-10-01 15:30:00')));
        $this->assertSame('2026-10-02', $method->invoke($service, '2026-10-02 08:00:00'));
    }
}
