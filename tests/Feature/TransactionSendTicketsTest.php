<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionSendTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private string $token;

    private Role $customerRole;

    private User $customer;

    private Event $event;

    private EventTicket $eventTicket;

    private EventLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $organizerRole = Role::create([
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ]);
        $permission = Permission::create([
            'name' => 'Transactions',
            'code' => 'transactions',
            'available_access' => ['view', 'execute'],
        ]);
        foreach (['transactions-view', 'transactions-execute'] as $access) {
            RolePermission::create([
                'role_uuid' => $organizerRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => $access,
            ]);
        }

        $this->organization = Organization::create([
            'name' => 'Merchant A',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'email' => 'merchant_a@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $organizer = AdminUser::create([
            'role_uuid' => $organizerRole->uuid,
            'organization_uuid' => $this->organization->uuid,
            'email' => 'organizer@test.com',
            'password' => 'password123',
            'first_name' => 'Org',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $this->token = auth('admin')->login($organizer) ?? '';

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);
        $this->customer = $this->makeCustomer('customer@test.com', 'Test', 'Customer');

        $this->event = Event::create([
            'event_name' => 'Org A Event',
            'event_description' => 'Test',
            'contact_email' => 'event_a@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $this->organization->uuid,
        ]);
        $this->eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'code' => 'GA-SEND',
            'name' => 'General Admission',
            'price' => 150,
            'max_ticket' => 10,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->location = EventLocation::create([
            'event_uuid' => $this->event->uuid,
            'name' => 'Main Branch',
            'city' => 'Manila',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    #[Test]
    public function sendingToTheBuyersEmailKeepsTheTicketsAndNotifiesTheBuyer(): void
    {
        $transaction = $this->makeTransaction();
        $this->makeTicket($transaction, 'TKT-A');
        $this->makeTicket($transaction, 'TKT-B');
        $orderQr = $transaction->qr_code;

        $this->sendTickets($transaction, '  Customer@Test.com ')
            ->assertOk()
            ->assertJsonPath('mode', 'resent')
            ->assertJsonPath('message', 'Tickets sent to customer@test.com.')
            ->assertJsonPath('ticket_count', 2)
            ->assertJsonPath('transferred_count', 0)
            ->assertJsonPath('recipient.email', 'customer@test.com')
            ->assertJsonPath('recipient.name', 'Test Customer')
            ->assertJsonPath('recipient.is_new_account', false);

        $this->assertSame(2, Ticket::where('user_uuid', $this->customer->uuid)->where('status', 'active')->count());
        $this->assertSame(0, Ticket::where('status', 'transferred')->count());
        $this->assertSame($orderQr, $transaction->fresh()->qr_code);

        $notification = PlatformNotification::where('notifiable_uuid', $this->customer->uuid)->sole();
        $this->assertSame('ticket_resent', $notification->type);
        $this->assertSame('Your 2 tickets are in your account for "Org A Event" (order #ORD-SEND-001).', $notification->body);
        $this->assertSame(['transaction_uuid' => $transaction->uuid], $notification->data);
    }

    #[Test]
    public function sendingToAnotherAccountTransfersEveryActiveTicket(): void
    {
        $recipient = $this->makeCustomer('friend@test.com', 'Friendly', 'Guest');
        $transaction = $this->makeTransaction();
        $first = $this->makeTicket($transaction, 'TKT-A');
        $second = $this->makeTicket($transaction, 'TKT-B');
        $used = $this->makeTicket($transaction, 'TKT-USED', [
            'status' => GeneralConstants::TICKET_STATUSES['USED'],
            'used_at' => now(),
        ]);

        $this->sendTickets($transaction, 'friend@test.com')
            ->assertOk()
            ->assertJsonPath('mode', 'transferred')
            ->assertJsonPath('message', '2 tickets transferred to friend@test.com.')
            ->assertJsonPath('ticket_count', 2)
            ->assertJsonPath('transferred_count', 2)
            ->assertJsonPath('recipient.is_new_account', false);

        foreach ([$first, $second] as $original) {
            $original->refresh();
            $this->assertSame('transferred', $original->status);
            $this->assertSame($recipient->uuid, $original->transferred_to);
        }
        $this->assertSame('used', $used->fresh()->status);
        $this->assertNull($transaction->fresh()->qr_code);

        $received = Ticket::where('user_uuid', $recipient->uuid)->get();
        $this->assertCount(2, $received);
        foreach ($received as $ticket) {
            $this->assertSame('active', $ticket->status);
            $this->assertSame($transaction->uuid, $ticket->transaction_uuid);
            $this->assertSame($this->location->uuid, $ticket->event_location_uuid);
            $this->assertSame('2026-06-01', $ticket->valid_until->format('Y-m-d'));
            $this->assertSame('150.00', $ticket->price);
            $this->assertSame('Friendly Guest', $ticket->attendee_name);
            $this->assertSame('friend@test.com', $ticket->attendee_email);
        }

        $this->assertSame(
            '2 tickets were sent to your account for "Org A Event" (order #ORD-SEND-001).',
            PlatformNotification::where('notifiable_uuid', $recipient->uuid)->sole()->body,
        );
        $this->assertSame(
            'Your 2 tickets were transferred to friend@test.com for "Org A Event" (order #ORD-SEND-001).',
            PlatformNotification::where('notifiable_uuid', $this->customer->uuid)->sole()->body,
        );

        $detail = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])
            ->getJson('/api/v1/transactions/' . $transaction->uuid)
            ->assertOk()
            ->assertJsonPath('data.order_qr_code', null)
            ->assertJsonCount(5, 'data.tickets');
        $original = collect($detail->json('data.tickets'))->firstWhere('ticket_number', 'TKT-A');
        $this->assertSame('friend@test.com', $original['transferred_to']);
        $this->assertNotNull($original['transferred_at']);
        $this->assertSame('customer@test.com', $original['owner_email']);
        $this->assertSame(
            ['friend@test.com', 'friend@test.com'],
            collect($detail->json('data.tickets'))->where('status', 'active')->pluck('owner_email')->values()->all(),
        );
    }

    #[Test]
    public function sendingToAnUnknownEmailCreatesAGuestAccountForTheTickets(): void
    {
        $transaction = $this->makeTransaction();
        $this->makeTicket($transaction, 'TKT-A');

        $this->sendTickets($transaction, 'Juan.Dela-Cruz@Example.com')
            ->assertOk()
            ->assertJsonPath('mode', 'transferred')
            ->assertJsonPath('message', '1 ticket transferred to juan.dela-cruz@example.com.')
            ->assertJsonPath('recipient.name', 'Juan Dela Cruz')
            ->assertJsonPath('recipient.is_new_account', true);

        $guest = User::where('email', 'juan.dela-cruz@example.com')->sole();
        $this->assertSame(User::GUEST_PROVIDER, $guest->provider);
        $this->assertSame($this->customerRole->uuid, $guest->role_uuid);
        $this->assertSame('Juan Dela Cruz', Ticket::where('user_uuid', $guest->uuid)->sole()->attendee_name);
        $this->assertSame(
            '1 ticket was sent to your account for "Org A Event" (order #ORD-SEND-001).',
            PlatformNotification::where('notifiable_uuid', $guest->uuid)->sole()->body,
        );
    }

    #[Test]
    public function itRefusesUnpaidTransactions(): void
    {
        $transaction = $this->makeTransaction(['payment_status' => 'pending']);
        $this->makeTicket($transaction, 'TKT-A');

        $this->sendTickets($transaction, 'friend@test.com')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Only paid transactions can be sent.');
    }

    #[Test]
    public function itRefusesTransactionsWithoutActiveTickets(): void
    {
        $transaction = $this->makeTransaction();
        $this->makeTicket($transaction, 'TKT-USED', [
            'status' => GeneralConstants::TICKET_STATUSES['USED'],
            'used_at' => now(),
        ]);

        $this->sendTickets($transaction, 'friend@test.com')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This transaction has no active tickets left to send.');
    }

    #[Test]
    public function itRefusesInactiveRecipientsWithoutMovingTickets(): void
    {
        $this->makeCustomer('blocked@test.com', 'Blocked', 'User')->update(['status' => 'inactive']);
        $transaction = $this->makeTransaction();
        $ticket = $this->makeTicket($transaction, 'TKT-A');

        $this->sendTickets($transaction, 'blocked@test.com')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The account for this email is not active.');

        $this->assertSame('active', $ticket->fresh()->status);
        $this->assertSame(1, Ticket::count());
        $this->assertSame(0, PlatformNotification::count());
    }

    #[Test]
    public function itValidatesTheEmailAndHidesOtherOrganizationsTransactions(): void
    {
        $transaction = $this->makeTransaction();

        $this->sendTickets($transaction, 'not-an-email')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $otherOrganization = Organization::create([
            'name' => 'Merchant B',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Smith',
            'email' => 'merchant_b@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
        $other = $this->makeTransaction([
            'order_number' => 'ORD-OTHER',
            'organization_uuid' => $otherOrganization->uuid,
        ]);

        $this->sendTickets($other, 'friend@test.com')
            ->assertNotFound()
            ->assertJsonPath('message', 'Transaction not found');
    }

    private function sendTickets(Transaction $transaction, string $email): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])
            ->postJson("/api/v1/transactions/{$transaction->uuid}/send-tickets", ['email' => $email]);
    }

    private function makeCustomer(string $email, string $firstName, string $lastName): User
    {
        return User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    private function makeTransaction(array $overrides = []): Transaction
    {
        return Transaction::create($overrides + [
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->event->uuid,
            'organization_uuid' => $this->organization->uuid,
            'transactionable_type' => 'event',
            'transactionable_uuid' => $this->event->uuid,
            'order_number' => 'ORD-SEND-001',
            'total_amount' => 300.00,
            'sub_total' => 300.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);
    }

    private function makeTicket(Transaction $transaction, string $number, array $overrides = []): Ticket
    {
        return Ticket::create($overrides + [
            'user_uuid' => $this->customer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'organization_uuid' => $this->organization->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $this->eventTicket->uuid,
            'event_location_uuid' => $this->location->uuid,
            'ticket_number' => $number,
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'qr_code' => 'QR_' . $number,
            'attendee_name' => 'Test Customer',
            'attendee_email' => 'customer@test.com',
            'valid_until' => '2026-06-01 23:59:59',
            'price' => 150,
            'discount' => 0,
        ]);
    }
}
