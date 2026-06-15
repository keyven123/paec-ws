<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PromoCode;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicPromoCodeTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create([
            'name' => 'Public Promo Org',
            'representative_first_name' => 'Test',
            'representative_last_name' => 'Org',
            'email' => 'public-promo@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
        $this->event = Event::create([
            'organization_uuid' => $org->uuid,
            'event_name' => 'Public Promo Event',
            'event_description' => 'Test',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);

        PromoCode::create([
            'organization_uuid' => $org->uuid,
            'code' => 'BOOM',
            'description' => 'Public validate test',
            'activityable_type' => Event::class,
            'activityable_id' => $this->event->uuid,
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    #[Test]
    public function guest_can_validate_promo_code_for_event_without_auth(): void
    {
        $this->getJson('/api/v1/public/promo-codes/BOOM?event_uuid=' . $this->event->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.code', 'BOOM');
    }

    #[Test]
    public function validate_promo_code_requires_event_uuid(): void
    {
        $this->getJson('/api/v1/public/promo-codes/BOOM')
            ->assertStatus(422);
    }

    #[Test]
    public function validate_promo_code_returns_404_for_invalid_code(): void
    {
        $this->getJson('/api/v1/public/promo-codes/INVALID?event_uuid=' . $this->event->uuid)
            ->assertStatus(404);
    }

    #[Test]
    public function logged_in_user_cannot_reuse_promo_code_on_public_validate_route(): void
    {
        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);
        $user = User::factory()->create(['role_uuid' => $customerRole->uuid]);

        Transaction::create([
            'user_uuid' => $user->uuid,
            'event_uuid' => $this->event->uuid,
            'organization_uuid' => $this->event->organization_uuid,
            'order_number' => 'ORD-PUBLIC-PROMO-' . uniqid(),
            'total_amount' => 90.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'promo_code_uuid' => PromoCode::where('code', 'BOOM')->value('uuid'),
            'promo_code_discount' => 10.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/public/promo-codes/BOOM?event_uuid=' . $this->event->uuid)
            ->assertStatus(404)
            ->assertJsonPath('message', 'You have already used this promo code');
    }
}
