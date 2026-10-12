<?php

namespace Tests\Feature\Coverage\GapOrganizer;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerCustomerControllerCoverageTest extends TestCase
{
    use OrganizerGapFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/organizer/customers';

    private Organization $organization;

    private Event $event;

    private User $customer;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->makeOrganization('Customer Merchant');
        $this->event = $this->makeOrgEvent($this->organization, 'Customer Event');
        $this->customer = $this->makeCustomer('linked-customer@example.com', 'Linked');
        $this->transaction = $this->makeOrgTransaction($this->organization, $this->event, $this->customer, 250);

        $this->loginOrganizerAdmin($this->organization, [
            'users' => ['view', 'create', 'update', 'delete', 'execute'],
        ]);
    }

    private function makeTicket(string $number, string $status): Ticket
    {
        return Ticket::create([
            'user_uuid' => $this->customer->uuid,
            'organization_uuid' => $this->organization->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $this->makeEventTicket($this->event)->uuid,
            'ticket_number' => $number,
            'qr_code' => 'QR-' . $number,
            'attendee_name' => 'Linked Customer',
            'attendee_email' => $this->customer->email,
            'status' => $status,
        ]);
    }

    #[Test]
    public function storeCreatesCustomerWithCustomerRole(): void
    {
        $customerRole = $this->customerRole();

        $response = $this->withHeaders($this->organizerHeaders())
            ->postJson(self::BASE, [
                'email' => 'walk-in@example.com',
                'password' => 'walkin-pass-1',
                'password_confirmation' => 'walkin-pass-1',
                'first_name' => 'Walk',
                'last_name' => 'In',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'walk-in@example.com');

        $user = User::findOrFail($response->json('data.uuid'));
        $this->assertSame($customerRole->uuid, $user->role_uuid);
        $this->assertTrue(Hash::check('walkin-pass-1', $user->password));
    }

    #[Test]
    public function storeValidatesPayload(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->postJson(self::BASE, [
                'email' => $this->customer->email,
                'password' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password', 'first_name', 'last_name']);
    }

    #[Test]
    public function storeRequiresOrganizationContext(): void
    {
        $this->loginOrganizerAdmin(null, ['users' => ['create']]);

        $this->withHeaders($this->organizerHeaders())
            ->postJson(self::BASE, [
                'email' => 'orphan@example.com',
                'password' => 'orphan-pass-1',
                'password_confirmation' => 'orphan-pass-1',
                'first_name' => 'Orphan',
                'last_name' => 'Customer',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization context is required.');

        $this->assertDatabaseMissing('users', ['email' => 'orphan@example.com']);
    }

    #[Test]
    public function destroySoftDeletesLinkedCustomer(): void
    {
        // UserRepository::delete() dereferences auth('api')->user(), so an api-guard user must be present.
        $this->actingAs($this->makeCustomer('api-actor@example.com'), 'api');

        $this->withHeaders($this->organizerHeaders())
            ->deleteJson(self::BASE . '/' . $this->customer->uuid)
            ->assertNoContent();

        $this->assertSoftDeleted('users', ['uuid' => $this->customer->uuid]);
    }

    #[Test]
    public function destroyReturnsNotFoundForCustomerOutsideOrganization(): void
    {
        $stranger = $this->makeCustomer('stranger@example.com');

        $this->withHeaders($this->organizerHeaders())
            ->deleteJson(self::BASE . '/' . $stranger->uuid)
            ->assertNotFound()
            ->assertJsonPath('message', 'Customer not found.');

        $this->assertNotSoftDeleted('users', ['uuid' => $stranger->uuid]);
    }

    #[Test]
    public function statsReturnsCustomerStatistics(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $this->customer->uuid . '/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User statistics retrieved successfully')
            ->assertJsonStructure(['data']);
    }

    #[Test]
    public function statsReturnsNotFoundForCustomerOutsideOrganization(): void
    {
        $stranger = $this->makeCustomer('stats-stranger@example.com');

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $stranger->uuid . '/stats')
            ->assertNotFound();
    }

    #[Test]
    public function recentActivityReturnsCustomerActivity(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $this->customer->uuid . '/recent-activity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User recent activity retrieved successfully')
            ->assertJsonStructure(['data']);
    }

    #[Test]
    public function ticketsReturnsPaginatedTicketsFilteredByStatus(): void
    {
        $active = $this->makeTicket('TKT-ACTIVE-1', GeneralConstants::TICKET_STATUSES['ACTIVE']);
        $this->makeTicket('TKT-USED-1', GeneralConstants::TICKET_STATUSES['USED']);

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $this->customer->uuid . '/tickets?per_page=5&status=' . GeneralConstants::TICKET_STATUSES['ACTIVE'] . '&q=%20%20')
            ->assertOk()
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.uuid', $active->uuid);
    }

    #[Test]
    public function ticketsReturnsAllTicketsWithoutFilters(): void
    {
        $this->makeTicket('TKT-ACTIVE-2', GeneralConstants::TICKET_STATUSES['ACTIVE']);
        $this->makeTicket('TKT-USED-2', GeneralConstants::TICKET_STATUSES['USED']);

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $this->customer->uuid . '/tickets')
            ->assertOk()
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('total', 2);
    }

    #[Test]
    public function exportDownloadsCustomerCsv(): void
    {
        $response = $this->withHeaders($this->organizerHeaders())
            ->get(self::BASE . '/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition, Content-Type');

        $this->assertMatchesRegularExpression(
            '/^attachment; filename="list_of_customers_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.csv"$/',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('Email', $response->getContent());
        $this->assertStringContainsString('linked-customer@example.com', $response->getContent());
    }

    #[Test]
    public function exportRequiresExecutePermission(): void
    {
        $this->loginOrganizerAdmin($this->organization, ['users' => ['view']]);

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/export')
            ->assertForbidden();
    }
}
