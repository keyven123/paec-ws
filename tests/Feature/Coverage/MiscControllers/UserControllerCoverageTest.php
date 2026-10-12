<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Constants\GeneralConstants;
use App\Exceptions\NoUserFoundException;
use App\Http\Repositories\UserRepository;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private const MISSING_UUID = '00000000-0000-0000-0000-000000000000';

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->createCustomer(['first_name' => 'Maria', 'last_name' => 'Clara']);
    }

    private function seedAffiliate(string $status, array $attributes = []): void
    {
        $this->customer->userAffiliate()->create(array_merge([
            'affiliate_status' => $status,
            'affiliate_code' => 'MARIA1',
        ], $attributes));
    }

    #[Test]
    public function updateReturns404WhenUserDisappearsAfterValidation(): void
    {
        $this->mock(UserRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoUserFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/users/{$this->customer->uuid}", ['first_name' => 'Changed'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No user found');
    }

    #[Test]
    public function destroyReturns404ForUnknownUser(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/users/' . self::MISSING_UUID)
            ->assertNotFound()
            ->assertJsonPath('message', 'No user found');
    }

    #[Test]
    public function destroySoftDeletesAnotherUser(): void
    {
        // UserRepository::delete compares against the `api` guard user, so one must be present.
        $this->actingAs($this->createCustomer(), 'api');

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/users/{$this->customer->uuid}")
            ->assertNoContent();

        $this->assertSoftDeleted('users', ['uuid' => $this->customer->uuid]);
    }

    #[Test]
    public function destroyRefusesSelfDeletion(): void
    {
        $this->actingAs($this->customer, 'api');

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/users/{$this->customer->uuid}")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized access');

        $this->assertDatabaseHas('users', ['uuid' => $this->customer->uuid, 'deleted_at' => null]);
    }

    #[Test]
    public function statsSummarisesPurchasesAndTicketStates(): void
    {
        $event = $this->createEvent();
        $paid = $this->createTransaction($this->customer, $event, ['total_amount' => 1500]);
        $this->createTransaction($this->customer, $event, [
            'total_amount' => 999,
            'payment_status' => 'pending',
        ]);
        $this->createTicket($paid);
        $this->createTicket($paid, ['status' => GeneralConstants::TICKET_STATUSES['TRANSFERRED']]);
        $this->createTicket($paid, ['status' => GeneralConstants::TICKET_STATUSES['USED']]);
        $this->createTicket($paid, ['status' => GeneralConstants::TICKET_STATUSES['USED']]);

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/users/{$this->customer->uuid}/stats")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User statistics retrieved successfully')
            ->assertJsonPath('data.total_purchase', 1500)
            ->assertJsonPath('data.on_hand_tickets', 1)
            ->assertJsonPath('data.transferred_tickets', 1)
            ->assertJsonPath('data.used_tickets', 2);
    }

    #[Test]
    public function recentActivityListsPaidPurchases(): void
    {
        $event = $this->createEvent(['event_name' => 'Jazz Night']);
        $this->createTransaction($this->customer, $event, [
            'payment_order_id' => 'pay_123',
            'total_amount' => 750,
            'paid_at' => now(),
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/users/{$this->customer->uuid}/recent-activity")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User recent activity retrieved successfully')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'purchase');

        $this->assertStringContainsString('purchased tickets for Jazz Night', $response->json('data.0.message'));
    }

    #[Test]
    public function ticketsFiltersByStatusAndSearchTerm(): void
    {
        $transaction = $this->createTransaction($this->customer, $this->createEvent());
        $match = $this->createTicket($transaction, ['ticket_number' => 'TKT-FIND-ME']);
        $this->createTicket($transaction, ['ticket_number' => 'TKT-OTHER']);
        $this->createTicket($transaction, [
            'ticket_number' => 'TKT-FIND-USED',
            'status' => GeneralConstants::TICKET_STATUSES['USED'],
        ]);

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/users/{$this->customer->uuid}/tickets?" . http_build_query([
                'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
                'q' => '  FIND  ',
                'per_page' => 5,
            ]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('data.0.uuid', $match->uuid);
    }

    #[Test]
    public function ticketsTreatsBlankSearchAsNoFilter(): void
    {
        $transaction = $this->createTransaction($this->customer, $this->createEvent());
        $this->createTicket($transaction);
        $this->createTicket($transaction);

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/users/{$this->customer->uuid}/tickets?q=%20%20")
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    #[Test]
    public function affiliatePartnerStatsFormatsAffiliateTimestamps(): void
    {
        $this->customer->forceFill(['terms_accepted_at' => '2026-01-15'])->save();
        $this->seedAffiliate(GeneralConstants::AFFILIATE_STATUSES['SUSPENDED'], [
            'affiliate_applied_at' => Carbon::parse('2026-02-01 08:00:00'),
            'affiliate_approved_at' => Carbon::parse('2026-02-03 09:30:00'),
            'affiliate_suspended_at' => Carbon::parse('2026-03-10 10:00:00'),
            'affiliate_suspend_reason' => 'Policy breach',
            'affiliate_bank_name' => 'BDO',
        ]);

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/users/{$this->customer->uuid}/affiliate-partner-stats")
            ->assertOk()
            ->assertJsonPath('data.user.affiliate_status', 'suspended')
            ->assertJsonPath('data.user.affiliate_applied_at', Carbon::parse('2026-02-01 08:00:00')->toIso8601String())
            ->assertJsonPath('data.user.affiliate_approved_at', Carbon::parse('2026-02-03 09:30:00')->toIso8601String())
            ->assertJsonPath('data.user.terms_accepted_at', Carbon::parse('2026-01-15')->toIso8601String())
            ->assertJsonPath('data.user.affiliate_suspended_at', Carbon::parse('2026-03-10 10:00:00')->toIso8601String())
            ->assertJsonPath('data.user.affiliate_suspend_reason', 'Policy breach')
            ->assertJsonPath('data.bank_details.bank', 'BDO');
    }

    #[Test]
    public function affiliateSuspendRejectsPartnerThatIsNotApproved(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/v1/users/{$this->customer->uuid}/affiliate-suspend", ['reason' => 'Fraud suspected'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Only approved affiliate partners can be suspended.']);

        $this->assertNull($this->customer->userAffiliate()->first());
    }

    #[Test]
    public function affiliateSuspendReturns404ForUnknownUser(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->patchJson('/api/v1/users/' . self::MISSING_UUID . '/affiliate-suspend', ['reason' => 'Fraud suspected'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No user found');
    }

    #[Test]
    public function affiliateReinstateRejectsPartnerThatIsNotSuspended(): void
    {
        $this->seedAffiliate(GeneralConstants::AFFILIATE_STATUSES['APPROVED']);

        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/v1/users/{$this->customer->uuid}/affiliate-reinstate")
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Only suspended affiliate partners can be reinstated.']);

        $this->assertSame('approved', $this->customer->userAffiliate()->first()->affiliate_status);
    }

    #[Test]
    public function affiliateReinstateReturns404ForUnknownUser(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->patchJson('/api/v1/users/' . self::MISSING_UUID . '/affiliate-reinstate')
            ->assertNotFound()
            ->assertJsonPath('message', 'No user found');
    }
}
