<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePayoutRequest;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\TestCase;

/**
 * Single end-to-end check: public click tracking, partner customer APIs, and admin payout approval.
 * Run all affiliate tests: php artisan test --testsuite=Affiliate
 */
class AffiliateProgramSmokeTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;
    use SeedsUserAffiliate;

    #[\PHPUnit\Framework\Attributes\Test]
    public function affiliatePublicCustomerAndAdminPayoutPipelineIsReachable(): void
    {
        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $this->grantAffiliatePayoutAdminPermissions($adminRole);

        $adminUser = AdminUser::create([
            'role_uuid' => $adminRole->uuid,
            'email' => 'admin-smoke@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'Smoke',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $buyer = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'buyer-smoke@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Buyer',
            'last_name' => 'Smoke',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $partner = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'partner-smoke@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Partner',
            'last_name' => 'Smoke',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $this->seedApprovedAffiliate($partner, 'SMOKE1');

        $event = Event::create([
            'event_name' => 'Smoke Affiliate Event',
            'event_description' => 'Test',
            'status' => 'published',
            'contact_email' => 'smoke@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $this->postJson('/api/v1/public/affiliate/track-click', [
            'ref' => 'SMOKE1',
            'path' => '/browse?ref=SMOKE1',
        ])->assertStatus(200)->assertJson(['ok' => true]);

        $this->actingAs($partner, 'api')
            ->getJson('/api/v1/customer/affiliate')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.code', 'SMOKE1');

        $this->actingAs($partner, 'api')
            ->getJson('/api/v1/customer/affiliate/available-events')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);

        EventSection::create([
            'name' => EventSection::AMUSEMENT_SECTION,
            'title' => 'Amusements',
            'status' => 'active',
        ]);

        $this->actingAs($partner, 'api')
            ->getJson('/api/v1/customer/affiliate/available-fun')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);

        $transaction = Transaction::create([
            'user_uuid' => $buyer->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-SMOKE-' . uniqid(),
            'total_amount' => 20000,
            'sub_total' => 20000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $partner->uuid,
        ]);

        $conversion = AffiliateConversion::create([
            'partner_user_uuid' => $partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $event->uuid,
            'order_total' => 20000,
            'commission_percent' => 10,
            'commission_amount' => 2000,
        ]);

        DB::table('affiliate_conversions')->where('uuid', $conversion->uuid)->update([
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        $this->actingAs($partner, 'api')
            ->getJson('/api/v1/customer/affiliate/conversions')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $payout = $this->actingAs($partner, 'api')
            ->postJson('/api/v1/customer/affiliate/payout-requests', [
                'amount' => 1000,
            ])
            ->assertStatus(201)
            ->json('data.uuid');

        $adminToken = auth('admin')->login($adminUser);

        $this->withHeaders(['Authorization' => 'Bearer ' . $adminToken])
            ->getJson('/api/v1/affiliate-payout-requests')
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => 'Bearer ' . $adminToken])
            ->patchJson('/api/v1/affiliate-payout-requests/' . $payout . '/approve')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertEquals(
            AffiliatePayoutRequest::STATUS_APPROVED,
            AffiliatePayoutRequest::where('uuid', $payout)->value('status')
        );
    }
}
