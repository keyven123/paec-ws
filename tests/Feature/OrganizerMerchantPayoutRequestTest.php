<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMerchantPayoutTestData;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class OrganizerMerchantPayoutRequestTest extends TestCase
{
    use CreatesMerchantPayoutTestData;
    use GrantsAdminPermissions;
    use RefreshDatabase;

    private const PAYOUT_STORE_URL = '/api/v1/organizer/accounting/payout-requests';

    private Organization $organization;

    private Event $event;

    private OrganizationBank $defaultBank;

    private OrganizationBank $secondaryBank;

    private AdminUser $organizerUser;

    private string $organizerToken;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $organizerRole = Role::create([
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ]);

        $this->grantOrganizerProfilePermissions($organizerRole);
        $this->grantRolePermissions($organizerRole, [
            'organizer-accounting' => ['view', 'create'],
        ]);

        $this->organization = Organization::create([
            'name' => 'Payout Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Street',
            'contact_number' => '09171234567',
            'email' => 'payout-merchant@test.com',
            'description' => 'Merchant',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);

        $this->defaultBank = $this->createOrganizationBank($this->organization, [
            'bank_name' => 'BDO',
            'bank_account_number' => '1111111111',
            'is_default' => true,
        ]);

        $this->secondaryBank = $this->createOrganizationBank($this->organization, [
            'bank_name' => 'Metrobank',
            'bank_account_number' => '2222222222',
            'is_default' => false,
        ]);

        $this->organizerUser = AdminUser::create([
            'role_uuid' => $organizerRole->uuid,
            'organization_uuid' => $this->organization->uuid,
            'email' => 'organizer-payout@test.com',
            'password' => 'password123',
            'first_name' => 'Org',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->organizerToken = auth('admin')->login($this->organizerUser) ?? '';

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->customer = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'customer-payout@test.com',
            'password' => 'password123',
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->event = $this->createEvent($this->organization, 'Payout Event');
        $this->createPaidTransaction($this->event, 1000, Carbon::parse('2020-06-01 12:00:00'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => $this->event->uuid,
            'organization_bank_uuid' => $this->defaultBank->uuid,
            'amount' => 100,
            'note' => 'Please transfer soon',
        ], $overrides);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->organizerToken];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationToSubmitPayoutRequest(): void
    {
        $this->postJson(self::PAYOUT_STORE_URL, $this->validPayload())->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itStoresPayoutRequestWithPreferredBank(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson(self::PAYOUT_STORE_URL, $this->validPayload([
                'amount' => 250,
                'organization_bank_uuid' => $this->secondaryBank->uuid,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount_requested', 250);

        $this->assertDatabaseHas('merchant_payout_requests', [
            'organization_uuid' => $this->organization->uuid,
            'organization_bank_uuid' => $this->secondaryBank->uuid,
            'event_uuid' => $this->event->uuid,
            'amount_requested' => '250.00',
            'status' => MerchantPayoutRequest::STATUS_PENDING,
            'merchant_note' => 'Please transfer soon',
            'requested_by_admin_uuid' => $this->organizerUser->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresOrganizationBankUuid(): void
    {
        $payload = $this->validPayload();
        unset($payload['organization_bank_uuid']);

        $this->withHeaders($this->authHeaders())
            ->postJson(self::PAYOUT_STORE_URL, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organization_bank_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsBankAccountFromAnotherOrganization(): void
    {
        $otherOrganization = Organization::create([
            'name' => 'Other Merchant',
            'representative_first_name' => 'Other',
            'representative_last_name' => 'Org',
            'email' => 'other-payout@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $foreignBank = $this->createOrganizationBank($otherOrganization, [
            'bank_name' => 'Foreign Bank',
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson(self::PAYOUT_STORE_URL, $this->validPayload([
                'organization_bank_uuid' => $foreignBank->uuid,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organization_bank_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsInactiveBankAccount(): void
    {
        $inactiveBank = $this->createOrganizationBank($this->organization, [
            'bank_name' => 'Inactive Bank',
            'bank_account_number' => '3333333333',
            'is_default' => false,
            'status' => OrganizationBank::STATUS_INACTIVE,
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson(self::PAYOUT_STORE_URL, $this->validPayload([
                'organization_bank_uuid' => $inactiveBank->uuid,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organization_bank_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsDuplicatePendingPayoutForSameEvent(): void
    {
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->event->uuid,
            'organization_bank_uuid' => $this->defaultBank->uuid,
            'amount_requested' => 50,
            'status' => MerchantPayoutRequest::STATUS_PENDING,
        ]));

        $this->withHeaders($this->authHeaders())
            ->postJson(self::PAYOUT_STORE_URL, $this->validPayload(['amount' => 25]))
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'You already have a pending payout request for this event. Wait for it to be processed.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsAmountAboveAvailableBalance(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson(self::PAYOUT_STORE_URL, $this->validPayload(['amount' => 99999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    private function createEvent(Organization $organization, string $name): Event
    {
        $event = Event::create([
            'event_name' => $name,
            'event_description' => 'Test',
            'contact_email' => 'event-payout@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $organization->uuid,
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);

        ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        return $event;
    }

    private function createPaidTransaction(Event $event, float $amount, Carbon $paidAt): Transaction
    {
        $schedule = Schedule::query()->where('event_uuid', $event->uuid)->firstOrFail();
        $scheduleTime = ScheduleTime::query()->where('schedule_uuid', $schedule->uuid)->firstOrFail();

        return Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'organization_uuid' => $event->organization_uuid,
            'order_number' => 'ORD-' . uniqid('', true),
            'total_amount' => $amount,
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => $paidAt,
        ]);
    }
}
