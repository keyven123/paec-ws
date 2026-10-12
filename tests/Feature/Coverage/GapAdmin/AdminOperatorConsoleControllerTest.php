<?php

namespace Tests\Feature\Coverage\GapAdmin;

use App\Constants\GeneralConstants;
use App\Http\Controllers\AdminOperatorConsoleController;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use App\Services\Platform\AdminOperatorConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesMerchantPayoutTestData;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\TestCase;

class AdminOperatorConsoleControllerTest extends TestCase
{
    use CreatesGapAdminUsers;
    use CreatesMerchantPayoutTestData;
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/finance/operator-console';

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createGapAdmin(['finance-view']);
    }

    private function asFinanceAdmin(): static
    {
        return $this->withHeaders($this->gapAdminHeaders($this->admin));
    }

    private function createOrganization(string $name = 'Console Merchant'): Organization
    {
        return Organization::create([
            'name' => $name,
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'email' => Str::slug($name).'-'.Str::lower(Str::random(5)).'@console.test',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
    }

    private function createEventFor(Organization $organization): Event
    {
        return Event::create([
            'event_name' => 'Console Event',
            'event_description' => 'Test',
            'contact_email' => 'event@console.test',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $organization->uuid,
        ]);
    }

    private function controller(): AdminOperatorConsoleController
    {
        return app(AdminOperatorConsoleController::class);
    }

    private function unusedService(): AdminOperatorConsoleService
    {
        $service = Mockery::mock(AdminOperatorConsoleService::class);
        $service->shouldNotReceive('build', 'paginatedRemittances', 'paginatedPendingPayouts', 'paginatedPayoutRequests');

        return $service;
    }

    #[Test]
    public function itReturnsDailyConsoleByDefault(): void
    {
        $this->mock(AdminOperatorConsoleService::class, function ($mock) {
            $mock->shouldReceive('build')
                ->once()
                ->with(null, AdminOperatorConsoleService::PERIOD_DAILY, null, null)
                ->andReturn(['kpis' => ['gmv' => 0]]);
        });

        $this->asFinanceAdmin()
            ->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Operator console')
            ->assertJsonPath('data.kpis.gmv', 0);
    }

    #[Test]
    public function itParsesAsOfAndCustomRangeBeforeCallingService(): void
    {
        $this->mock(AdminOperatorConsoleService::class, function ($mock) {
            $mock->shouldReceive('build')
                ->once()
                ->withArgs(function ($asOf, $period, $start, $end) {
                    return $asOf?->toDateString() === '2026-04-20'
                        && $period === AdminOperatorConsoleService::PERIOD_CUSTOM
                        && $start?->toDateTimeString() === '2026-04-01 00:00:00'
                        && $end?->toDateTimeString() === '2026-04-15 23:59:59';
                })
                ->andReturn(['ok' => true]);
        });

        $this->asFinanceAdmin()
            ->getJson(self::BASE.'?'.http_build_query([
                'as_of' => '2026-04-20',
                'period' => 'Custom',
                'custom_start' => '2026-04-01',
                'custom_end' => '2026-04-15',
            ]))
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    #[Test]
    public function itRejectsInvalidAsOfDate(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::BASE.'?as_of=definitely-not-a-date')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid as_of date.']);
    }

    #[Test]
    public function itRejectsUnknownPeriod(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::BASE.'?period=quarterly')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid period. Use daily, weekly, monthly, yearly, or custom.']);
    }

    #[Test]
    public function itRequiresBothBoundsForCustomPeriod(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::BASE.'?period=custom&date_to=2026-04-15')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'custom_start and custom_end are required when period is custom.']);
    }

    #[Test]
    public function itRejectsUnparseableCustomBounds(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::BASE.'?period=custom&date_from=2026-04-01&date_to=nonsense-day')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid custom_start or custom_end.']);
    }

    #[Test]
    public function itReturnsServiceArgumentErrorsAs422(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::BASE.'?period=custom&custom_start=2026-04-15&custom_end=2026-04-01')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Custom start date must be on or before end date.']);
    }

    #[Test]
    public function itRejectsRemittanceFilterForUnknownOrganization(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::BASE.'/remittances?organization_uuid='.Str::uuid())
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid organization_uuid.']);
    }

    #[Test]
    public function itRequiresValidStatusForPayoutRequests(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::BASE.'/payout-requests?status=pending')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Query parameter "status" is required and must be "approved" or "declined".']);
    }

    #[Test]
    public function itReturns404WhenVoidingUnknownRemittance(): void
    {
        $this->asFinanceAdmin()
            ->patchJson(self::BASE.'/remittances/'.Str::uuid().'/void')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Remittance not found.']);
    }

    #[Test]
    public function itForbidsRemittanceEventsForNonAdminRole(): void
    {
        $staff = $this->createGapAdmin(['finance-view'], isAdmin: false);
        $organization = $this->createOrganization();

        $this->withHeaders($this->gapAdminHeaders($staff))
            ->getJson(self::BASE.'/events?organization_uuid='.$organization->uuid)
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    #[Test]
    public function itForbidsStoringRemittanceForNonAdminRole(): void
    {
        $staff = $this->createGapAdmin(['finance-view'], isAdmin: false);
        $organization = $this->createOrganization();
        $event = $this->createEventFor($organization);

        $this->withHeaders($this->gapAdminHeaders($staff))
            ->postJson(self::BASE.'/remittances', [
                'organization_uuid' => $organization->uuid,
                'event_uuid' => $event->uuid,
                'amount' => 100,
            ])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);

        $this->assertDatabaseCount('merchant_payout_requests', 0);
    }

    #[Test]
    public function itRejectsRemittanceWhenMerchantHasNoBankAccount(): void
    {
        $organization = $this->createOrganization('Bankless Merchant');
        $event = $this->createEventFor($organization);

        $this->mock(OrganizerAccountingBalanceService::class, function ($mock) use ($organization, $event) {
            $mock->shouldReceive('availableForPayout')
                ->once()
                ->with($organization->uuid, $event->uuid)
                ->andReturn(5000.0);
        });

        $this->asFinanceAdmin()
            ->postJson(self::BASE.'/remittances', [
                'organization_uuid' => $organization->uuid,
                'event_uuid' => $event->uuid,
                'amount' => 250,
            ])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This merchant has no bank account on file.']);

        $this->assertDatabaseCount('merchant_payout_requests', 0);
    }

    #[Test]
    public function itReturns404WhenOrganizationIsSoftDeletedAfterPassingExistsRule(): void
    {
        $organization = $this->createOrganization('Archived Merchant');
        $event = $this->createEventFor($organization);
        $this->createOrganizationBank($organization);
        $organization->delete();

        $this->mock(OrganizerAccountingBalanceService::class, function ($mock) {
            $mock->shouldNotReceive('availableForPayout');
        });

        $this->asFinanceAdmin()
            ->postJson(self::BASE.'/remittances', [
                'organization_uuid' => $organization->uuid,
                'event_uuid' => $event->uuid,
                'amount' => 100,
            ])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Organizer not found.']);
    }

    #[Test]
    public function itForbidsShowWithoutAuthenticatedAdmin(): void
    {
        $response = $this->controller()->show(Request::create(self::BASE, 'GET'), $this->unusedService());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], $response->getData(true));
    }

    #[Test]
    public function itForbidsRemittancesWithoutAuthenticatedAdmin(): void
    {
        $response = $this->controller()->remittances(Request::create(self::BASE.'/remittances', 'GET'), $this->unusedService());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], $response->getData(true));
    }

    #[Test]
    public function itForbidsPendingPayoutsWithoutAuthenticatedAdmin(): void
    {
        $response = $this->controller()->pendingPayouts(Request::create(self::BASE.'/pending-payouts', 'GET'), $this->unusedService());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], $response->getData(true));
    }

    #[Test]
    public function itForbidsPayoutRequestsWithoutAuthenticatedAdmin(): void
    {
        $response = $this->controller()->payoutRequests(
            Request::create(self::BASE.'/payout-requests', 'GET', ['status' => 'approved']),
            $this->unusedService(),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], $response->getData(true));
    }

    #[Test]
    public function itForbidsVoidingWithoutAuthenticatedAdmin(): void
    {
        $organization = $this->createOrganization();
        $remittance = MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($organization, [
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        $response = $this->controller()->voidRemittance($remittance->uuid);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], $response->getData(true));
        $this->assertSame(MerchantPayoutRequest::STATUS_APPROVED, $remittance->fresh()->status);
    }
}
