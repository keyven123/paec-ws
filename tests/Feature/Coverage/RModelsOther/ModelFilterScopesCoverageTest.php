<?php

namespace Tests\Feature\Coverage\RModelsOther;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\EventSection;
use App\Models\Organization;
use App\Models\Place;
use App\Models\PromoCode;
use App\Models\Role;
use App\Models\Venue;
use App\Models\VenueListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelFilterScopesCoverageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function categoryFiltersByKeywordAndType(): void
    {
        $concert = Category::create(['name' => 'Concert', 'code' => 'concert', 'type' => Category::TYPES['EVENT']]);
        $rides = Category::create(['name' => 'Rides', 'code' => 'thrill_rides', 'type' => Category::TYPES['FUN']]);

        $this->assertSame([$concert->uuid], Category::filters(['q' => 'Conc'])->pluck('uuid')->all());
        $this->assertSame([$rides->uuid], Category::filters(['q' => 'thrill'])->pluck('uuid')->all());
        $this->assertSame([$rides->uuid], Category::filters(['type' => Category::TYPES['FUN']])->pluck('uuid')->all());
    }

    #[Test]
    public function cmsPageFiltersByStatusAndKeyword(): void
    {
        DB::table('cms_pages')->delete();
        $terms = CmsPage::create(['title' => 'Terms of Use', 'slug' => 'terms', 'status' => CmsPage::STATUS_PUBLISHED]);
        $privacy = CmsPage::create(['title' => 'Privacy', 'slug' => 'privacy-policy', 'status' => CmsPage::STATUS_DRAFT]);

        $this->assertSame([$privacy->uuid], CmsPage::filters(['status' => CmsPage::STATUS_DRAFT])->pluck('uuid')->all());
        $this->assertSame([$terms->uuid], CmsPage::filters(['q' => 'Terms'])->pluck('uuid')->all());
        $this->assertSame([$privacy->uuid], CmsPage::filters(['q' => 'policy'])->pluck('uuid')->all());
    }

    #[Test]
    public function eventSectionFiltersByStatus(): void
    {
        $active = EventSection::create(['name' => 'eat', 'title' => 'Eat', 'status' => 'active']);
        EventSection::create(['name' => 'stay', 'title' => 'Stay', 'status' => 'inactive']);

        $this->assertSame([$active->uuid], EventSection::filters(['status' => 'active'])->pluck('uuid')->all());
    }

    #[Test]
    public function organizationFiltersByStatus(): void
    {
        $approved = Organization::factory()->create(['status' => 'approved']);
        Organization::factory()->create(['status' => 'pending']);

        $this->assertSame([$approved->uuid], Organization::filters(['status' => 'approved'])->pluck('uuid')->all());
    }

    #[Test]
    public function placeFiltersByBooleanLikeVisibility(): void
    {
        $visible = Place::create(['name' => 'Manila', 'code' => 'mnl', 'is_visible' => true]);
        $hidden = Place::create(['name' => 'Cebu', 'code' => 'ceb', 'is_visible' => false]);

        $this->assertSame([$visible->uuid], Place::filters(['is_visible' => 'true'])->pluck('uuid')->all());
        $this->assertSame([$hidden->uuid], Place::filters(['is_visible' => '0'])->pluck('uuid')->all());
        $this->assertSame([$hidden->uuid], Place::filters(['is_visible' => 'maybe'])->pluck('uuid')->all());
    }

    #[Test]
    public function promoCodeFiltersByEveryCriterion(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $summer = PromoCode::create([
            'organization_uuid' => $orgA->uuid,
            'code' => 'SUMMER10',
            'description' => 'Summer sale',
            'activityable_type' => 'event',
            'activityable_id' => 'event-1',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now(),
            'usable_to' => now()->addMonth(),
            'status' => 'active',
        ]);
        $vip = PromoCode::create([
            'organization_uuid' => $orgB->uuid,
            'code' => 'VIP',
            'description' => 'Members only perk',
            'activityable_type' => 'venue_inquiry',
            'activityable_id' => 'inquiry-1',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'is_unlimited' => false,
            'usable_from' => now(),
            'usable_to' => now()->addMonth(),
            'status' => 'inactive',
        ]);

        $this->assertSame([$summer->uuid], PromoCode::filters(['q' => 'SUMMER'])->pluck('uuid')->all());
        $this->assertSame([$vip->uuid], PromoCode::filters(['q' => 'perk'])->pluck('uuid')->all());
        $this->assertSame([$vip->uuid], PromoCode::filters(['status' => 'inactive'])->pluck('uuid')->all());
        $this->assertSame([$summer->uuid], PromoCode::filters(['organization_uuid' => $orgA->uuid])->pluck('uuid')->all());
        $this->assertSame([$vip->uuid], PromoCode::filters(['discount_type' => 'fixed'])->pluck('uuid')->all());
        $this->assertSame([$summer->uuid], PromoCode::filters(['is_unlimited' => true])->pluck('uuid')->all());
        $this->assertSame([$vip->uuid], PromoCode::filters(['activityable_type' => 'venue_inquiry'])->pluck('uuid')->all());
        $this->assertSame([$summer->uuid], PromoCode::filters(['activityable_id' => 'event-1'])->pluck('uuid')->all());
    }

    #[Test]
    public function promoCodeCreatorAndUpdaterResolveAuthenticatedAdmin(): void
    {
        $role = Role::create(['name' => 'Admin', 'code' => 'admin', 'is_admin' => true]);
        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'promo-admin@paec.test',
            'password' => 'secret-password',
            'first_name' => 'Promo',
        ]);
        $this->actingAs($admin, 'admin');

        $promo = PromoCode::create([
            'organization_uuid' => Organization::factory()->create()->uuid,
            'code' => 'AUDIT',
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'usable_from' => now(),
            'usable_to' => now()->addDay(),
        ]);

        $this->assertTrue($promo->creator->is($admin));
        $this->assertTrue($promo->updater->is($admin));
    }

    #[Test]
    public function venueFiltersByStatusAndCode(): void
    {
        $arena = Venue::create(['name' => 'Arena', 'code' => 'ARN', 'type' => Venue::TYPES['SPORT'], 'status' => 'active']);
        $theatre = Venue::create(['name' => 'Theatre', 'code' => 'THR', 'type' => Venue::TYPES['THEATRE'], 'status' => 'inactive']);

        $this->assertSame([$arena->uuid], Venue::filters(['status' => 'active'])->pluck('uuid')->all());
        $this->assertSame([$theatre->uuid], Venue::filters(['code' => 'THR'])->pluck('uuid')->all());
    }

    #[Test]
    public function venueListingFiltersByKeywordAndSearch(): void
    {
        $hall = VenueListing::factory()->create([
            'name' => 'Grand Ballroom', 'city' => 'Pasig', 'venue_type' => 'Ballroom', 'location' => 'Ortigas',
        ]);
        $garden = VenueListing::factory()->create([
            'name' => 'Garden Patio', 'city' => 'Taguig', 'venue_type' => 'Garden', 'location' => 'BGC',
        ]);

        $this->assertSame([$hall->uuid], VenueListing::filters(['q' => 'Ortigas'])->pluck('uuid')->all());
        $this->assertSame([$garden->uuid], VenueListing::filters(['q' => 'Garden'])->pluck('uuid')->all());
        $this->assertSame([$garden->uuid], VenueListing::filters(['search' => 'Taguig'])->pluck('uuid')->all());
        $this->assertSame([], VenueListing::filters(['search' => 'BGC'])->pluck('uuid')->all());
    }

    #[Test]
    public function venueListingFiltersByStatusLocationAndGuests(): void
    {
        $small = VenueListing::factory()->published()->create([
            'city' => 'Makati City', 'location' => 'Legaspi', 'capacity_min' => 10, 'capacity_max' => 80,
        ]);
        $large = VenueListing::factory()->create([
            'city' => 'Quezon City', 'location' => 'Cubao', 'capacity_min' => 100, 'capacity_max' => 1000,
        ]);

        $this->assertSame(
            [$small->uuid],
            VenueListing::filters(['status' => VenueListing::STATUSES['PUBLISHED']])->pluck('uuid')->all()
        );
        $this->assertSame([$large->uuid], VenueListing::filters(['location' => 'Cubao'])->pluck('uuid')->all());
        $this->assertSame([$small->uuid], VenueListing::filters(['location' => 'Makati'])->pluck('uuid')->all());
        $this->assertCount(2, VenueListing::filters(['location' => 'Metro Manila (All)'])->get());
        $this->assertSame([$large->uuid], VenueListing::filters(['guests' => '500'])->pluck('uuid')->all());
    }
}
