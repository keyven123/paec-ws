<?php

namespace Tests\Feature\Coverage\GapOrganizer;

use App\Http\Controllers\Organizer\OrganizerAccountingController;
use App\Http\Requests\Organizer\OrganizerPnLRequest;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use App\Services\Organizer\OrganizerPnLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesMerchantPayoutTestData;
use Tests\TestCase;

class OrganizerAccountingControllerCoverageTest extends TestCase
{
    use CreatesMerchantPayoutTestData;
    use OrganizerGapFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/organizer/accounting';

    private Organization $organization;

    private Event $event;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->makeOrganization('Accounting Merchant');
        $this->event = $this->makeOrgEvent($this->organization, 'Zeta Accounting Event');
        $this->admin = $this->loginOrganizerAdmin($this->organization, [
            'organizer-accounting' => ['view', 'create'],
        ]);
    }

    private function loginOrganizationlessAdmin(): AdminUser
    {
        return $this->loginOrganizerAdmin(null, [
            'organizer-accounting' => ['view', 'create'],
        ]);
    }

    #[Test]
    public function pnlReturnsMonthlyReportByDefault(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/pnl')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Organizer P&L')
            ->assertJsonPath('data.organization_uuid', $this->organization->uuid)
            ->assertJsonPath('data.period.type', 'monthly')
            ->assertJsonPath('data.event_uuid', null);
    }

    #[Test]
    public function pnlAcceptsAsOfCustomRangeAndEventFilter(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/pnl?' . http_build_query([
                'period' => 'custom',
                'as_of' => '2026-03-31',
                'custom_start' => '2026-03-01',
                'custom_end' => '2026-03-15',
                'event_uuid' => $this->event->uuid,
            ]))
            ->assertOk()
            ->assertJsonPath('data.period.type', 'custom')
            ->assertJsonPath('data.event_uuid', $this->event->uuid);
    }

    /**
     * The form request's `date` / `required_if` rules run first over HTTP, so the controller's own
     * defensive parsing guards are exercised by handing it a crafted validated payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function callPnlWithValidated(array $validated): array
    {
        $request = Mockery::mock(OrganizerPnLRequest::class);
        $request->shouldReceive('validated')->andReturn($validated);

        $response = app(OrganizerAccountingController::class)->pnl($request);

        return [$response->getStatusCode(), $response->getData(true)];
    }

    #[Test]
    public function pnlRejectsUnparseableAsOf(): void
    {
        [$status, $body] = $this->callPnlWithValidated(['as_of' => 'definitely-not-a-date']);

        $this->assertSame(422, $status);
        $this->assertSame(['message' => 'Invalid as_of date.'], $body);
    }

    #[Test]
    public function pnlRejectsUnparseableCustomRange(): void
    {
        [$status, $body] = $this->callPnlWithValidated([
            'period' => 'custom',
            'custom_start' => '2026-03-01',
            'custom_end' => 'definitely-not-a-date',
        ]);

        $this->assertSame(422, $status);
        $this->assertSame(['message' => 'Invalid custom_start or custom_end.'], $body);
    }

    #[Test]
    public function pnlRequiresCustomDatesWhenValidatedPayloadLacksThem(): void
    {
        [$status, $body] = $this->callPnlWithValidated(['period' => 'CUSTOM', 'custom_start' => '2026-01-01']);

        $this->assertSame(422, $status);
        $this->assertSame(['message' => 'custom_start and custom_end are required when period is custom.'], $body);
    }

    #[Test]
    public function pnlMapsServiceInvalidArgumentToUnprocessable(): void
    {
        $this->mock(OrganizerPnLService::class, function ($mock) {
            $mock->shouldReceive('buildReport')
                ->once()
                ->andThrow(new \InvalidArgumentException('Invalid period type.'));
        });

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/pnl?period=weekly')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid period type.']);
    }

    #[Test]
    public function pnlRequiresOrganizationContext(): void
    {
        $this->loginOrganizationlessAdmin();

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/pnl')
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization context is required.');
    }

    #[Test]
    public function eventsListsOnlyOrganizationEventsSortedByName(): void
    {
        $this->makeOrgEvent($this->organization, 'Alpha Accounting Event');
        $this->makeOrgEvent($this->makeOrganization('Other Merchant'), 'Foreign Event');

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/events')
            ->assertOk()
            ->assertJsonPath('message', 'Organizer accounting events')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event_name', 'Alpha Accounting Event')
            ->assertJsonPath('data.1.event_name', 'Zeta Accounting Event')
            ->assertJsonMissing(['event_name' => 'Foreign Event']);
    }

    #[Test]
    public function eventsReturnsEmptyListWithoutOrganization(): void
    {
        $this->loginOrganizationlessAdmin();

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/events')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Organizer accounting events',
                'data' => [],
            ]);
    }

    #[Test]
    public function summaryReturnsReportForOrganization(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/summary?event_uuid=' . $this->event->uuid)
            ->assertOk()
            ->assertJsonPath('message', 'Organizer accounting summary')
            ->assertJsonStructure(['data' => ['available', 'pending']]);
    }

    #[Test]
    public function summaryReturnsZeroesWithoutOrganization(): void
    {
        $this->loginOrganizationlessAdmin();

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/summary')
            ->assertOk()
            ->assertJsonPath('data.available', 0)
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.total_cashout', 0)
            ->assertJsonPath('data.commission_percentage', null)
            ->assertJsonPath('data.currency', 'PHP');
    }

    #[Test]
    public function transactionsRequiresValidBucket(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/transactions?bucket=bogus')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Query parameter "bucket" is required and must be "available" or "pending".');
    }

    #[Test]
    public function transactionsReturnsBucketForOrganization(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/transactions?bucket=available&page=1&per_page=5')
            ->assertOk()
            ->assertJsonPath('message', 'Organizer accounting transactions')
            ->assertJsonPath('data.bucket', 'available')
            ->assertJsonPath('data.transactions', []);
    }

    #[Test]
    public function transactionsReturnsEmptyPayloadWithoutOrganization(): void
    {
        $this->loginOrganizationlessAdmin();

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/transactions?bucket=pending')
            ->assertOk()
            ->assertJsonPath('data.bucket', 'pending')
            ->assertJsonPath('data.transactions', [])
            ->assertJsonPath('data.meta.per_page', 15)
            ->assertJsonPath('data.meta.total', 0);
    }

    #[Test]
    public function remittanceBucketsRequiresValidBucket(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/remittance-buckets')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Query parameter "bucket" is required and must be "available" or "pending".');
    }

    #[Test]
    public function remittanceBucketsReturnsMonthsForOrganization(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/remittance-buckets?bucket=pending')
            ->assertOk()
            ->assertJsonPath('message', 'Organizer remittance buckets')
            ->assertJsonPath('data.bucket', 'pending')
            ->assertJsonPath('data.months', []);
    }

    #[Test]
    public function remittanceBucketsReturnsEmptyPayloadWithoutOrganization(): void
    {
        $this->loginOrganizationlessAdmin();

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/remittance-buckets?bucket=available')
            ->assertOk()
            ->assertJsonPath('data.bucket', 'available')
            ->assertJsonPath('data.totals.merchant_net_sum', 0)
            ->assertJsonPath('data.totals.transaction_count', 0)
            ->assertJsonPath('data.months', []);
    }

    #[Test]
    public function payoutRequestsListsOrganizationRequestsWithClampedPaging(): void
    {
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->event->uuid,
            'amount_requested' => 75,
            'status' => MerchantPayoutRequest::STATUS_PENDING,
        ]));

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/payout-requests?per_page=1&pending_page=0&success_page=1&declined_page=1')
            ->assertOk()
            ->assertJsonPath('message', 'Organizer payout requests')
            ->assertJsonCount(1, 'data.pending.rows')
            ->assertJsonPath('data.pending.meta.per_page', 5)
            ->assertJsonPath('data.pending.meta.current_page', 1);
    }

    #[Test]
    public function payoutRequestsReturnsEmptyPayloadWithoutOrganization(): void
    {
        $this->loginOrganizationlessAdmin();

        $empty = ['rows' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0]];

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/payout-requests')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Organizer payout requests',
                'data' => ['pending' => $empty, 'success' => $empty, 'declined' => $empty],
            ]);
    }

    #[Test]
    public function storePayoutRequestRequiresOrganization(): void
    {
        $bank = $this->createOrganizationBank($this->organization);
        $this->loginOrganizationlessAdmin();

        $this->withHeaders($this->organizerHeaders())
            ->postJson(self::BASE . '/payout-requests', [
                'event_uuid' => $this->event->uuid,
                'organization_bank_uuid' => $bank->uuid,
                'amount' => 10,
            ])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Organization not found for this account.']);

        $this->assertDatabaseCount('merchant_payout_requests', 0);
    }

    #[Test]
    public function storePayoutRequestRechecksAvailableBalanceInController(): void
    {
        $bank = $this->createOrganizationBank($this->organization, ['status' => OrganizationBank::STATUS_ACTIVE]);

        // Balance shrinks between form-request validation and the controller re-check.
        $this->mock(OrganizerAccountingBalanceService::class, function ($mock) {
            $mock->shouldReceive('availableForPayout')
                ->twice()
                ->andReturn(500.0, 40.0);
        });

        $this->withHeaders($this->organizerHeaders())
            ->postJson(self::BASE . '/payout-requests', [
                'event_uuid' => $this->event->uuid,
                'organization_bank_uuid' => $bank->uuid,
                'amount' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Amount exceeds available balance for this event.')
            ->assertJsonPath('available', 40);

        $this->assertDatabaseCount('merchant_payout_requests', 0);
    }
}
