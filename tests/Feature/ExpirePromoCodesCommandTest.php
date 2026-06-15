<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Organization;
use App\Models\PromoCode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpirePromoCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Promo Expire Test Org',
            'representative_first_name' => 'Test',
            'representative_last_name' => 'Org',
            'email' => 'promo-expire-org@example.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_deactivates_active_promo_codes_when_usable_to_has_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', config('app.timezone')));

        $promo = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'EXPIRED1',
            'description' => 'Past window',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => '2026-01-01 00:00:00',
            'usable_to' => '2026-06-10 23:00:00',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->artisan('app:expire-promo-codes')->assertSuccessful();

        $this->assertSame(
            GeneralConstants::GENERAL_STATUSES['INACTIVE'],
            $promo->fresh()->status
        );
    }

    #[Test]
    public function it_does_not_change_active_promo_codes_when_usable_to_is_still_in_the_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', config('app.timezone')));

        $promo = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'STILL_OK',
            'description' => 'Future window',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 5,
            'is_unlimited' => true,
            'usable_from' => '2026-06-01 00:00:00',
            'usable_to' => '2026-12-31 23:00:00',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->artisan('app:expire-promo-codes')->assertSuccessful();

        $this->assertSame(
            GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            $promo->fresh()->status
        );
    }

    #[Test]
    public function it_does_not_reactivate_inactive_promo_codes_even_when_usable_to_is_in_the_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', config('app.timezone')));

        $promo = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'ALREADY_OFF',
            'description' => 'Already inactive',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 15,
            'is_unlimited' => true,
            'usable_from' => '2026-01-01 00:00:00',
            'usable_to' => '2026-06-10 23:00:00',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $this->artisan('app:expire-promo-codes')->assertSuccessful();

        $this->assertSame(
            GeneralConstants::GENERAL_STATUSES['INACTIVE'],
            $promo->fresh()->status
        );
    }

}
