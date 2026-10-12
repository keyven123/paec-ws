<?php

namespace Tests\Feature\Coverage\Requests;

use App\Http\Requests\Customer\GuestTempTransactionRequest;
use App\Http\Requests\Customer\StoreAffiliatePayoutRequest;
use App\Http\Requests\Customer\TempTransactionRequest;
use App\Http\Requests\Customer\UpdateTempTransactionRequest;
use App\Http\Requests\UserRegistrationRequest;
use App\Models\AffiliateConversion;
use App\Models\Event;
use App\Models\TempTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\TestCase;

class CustomerRequestsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RequestFixtures;
    use SeedsUserAffiliate;

    public static function tempTransactionRequests(): array
    {
        return [
            'create' => [TempTransactionRequest::class],
            'update' => [UpdateTempTransactionRequest::class],
        ];
    }

    #[Test]
    #[DataProvider('tempTransactionRequests')]
    public function seatSelectionEventsRequireSeatsForEveryTicket(string $requestClass): void
    {
        $event = $this->makeEvent(['event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION']]);
        $ticket = $this->makeEventTicket($event);

        $errors = $this->validateFormRequest($requestClass, [
            'event_uuid' => $event->uuid,
            'tickets' => [
                ['event_ticket_uuid' => $ticket->uuid, 'quantity' => 1],
                ['event_ticket_uuid' => $ticket->uuid, 'quantity' => 1, 'seats' => [['uuid' => 'not-a-uuid']]],
            ],
        ], $this->routeFor($requestClass, $event));

        $this->assertFormRequestError($errors, 'tickets.0.seats');
        $this->assertFormRequestError($errors, 'tickets.1.seats.0.uuid', 'Seat uuid must be a valid uuid.');
        $this->assertFormRequestError($errors, 'tickets.1.seats.0.row', 'Row is required.');
        $this->assertFormRequestError($errors, 'tickets.1.seats.0.seat_no', 'Seat number is required.');
        $this->assertFormRequestError($errors, 'tickets.1.seats.0.category', 'Category is required.');
    }

    #[Test]
    #[DataProvider('tempTransactionRequests')]
    public function amusementPriorityTicketsRequireAVisitDate(string $requestClass): void
    {
        $event = $this->makeAmusementEvent();
        $flexible = $this->makeEventTicket($event, ['visit_policy' => 'flexible']);
        $priority = $this->makeEventTicket($event, ['visit_policy' => 'priority']);

        $errors = $this->validateFormRequest($requestClass, [
            'event_uuid' => $event->uuid,
            'tickets' => [
                ['quantity' => 1],
                ['event_ticket_uuid' => $flexible->uuid, 'quantity' => 1],
                ['event_ticket_uuid' => $priority->uuid, 'quantity' => 1],
                ['event_ticket_uuid' => $priority->uuid, 'quantity' => 1, 'valid_until' => now()->addDays(3)->toDateString()],
            ],
        ], $this->routeFor($requestClass, $event));

        $this->assertFormRequestError($errors, 'tickets.0.event_ticket_uuid');
        $this->assertArrayNotHasKey('tickets.1.valid_until', $errors);
        $this->assertFormRequestError($errors, 'tickets.2.valid_until', 'Date of Visit is required for this priority ticket.');
        $this->assertArrayNotHasKey('tickets.3.valid_until', $errors);
    }

    #[Test]
    #[DataProvider('tempTransactionRequests')]
    public function amusementVisitsForTodayAreRejectedAfterTheCutoff(string $requestClass): void
    {
        $this->travelTo(now(config('app.timezone'))->setTime(15, 0));
        $event = $this->makeAmusementEvent(['today_cutoff_time' => '10:00:00']);
        $priority = $this->makeEventTicket($event, ['visit_policy' => 'priority']);

        $errors = $this->validateFormRequest($requestClass, [
            'event_uuid' => $event->uuid,
            'tickets' => [
                ['event_ticket_uuid' => $priority->uuid, 'quantity' => 1, 'valid_until' => now()->toDateString()],
            ],
        ], $this->routeFor($requestClass, $event));

        $this->assertFormRequestError(
            $errors,
            'tickets.0.valid_until',
            "Today's visit date is no longer available. Please choose another date.",
        );
    }

    #[Test]
    #[DataProvider('tempTransactionRequests')]
    public function amusementChecksAreSkippedWhenTicketsIsNotAnArray(string $requestClass): void
    {
        $event = $this->makeAmusementEvent();

        $errors = $this->validateFormRequest($requestClass, [
            'event_uuid' => $event->uuid,
            'tickets' => 'not-an-array',
        ], $this->routeFor($requestClass, $event));

        $this->assertFormRequestError($errors, 'tickets', 'Tickets must be an array.');
        $this->assertSame(['tickets'], array_keys($errors));
    }

    #[Test]
    public function updateTempTransactionTakesTheUuidFromTheRoute(): void
    {
        $event = $this->makeEvent();
        $ticket = $this->makeEventTicket($event);
        $temp = $this->makeTempTransaction($event);

        $request = $this->assertFormRequestPasses(UpdateTempTransactionRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [['event_ticket_uuid' => $ticket->uuid, 'quantity' => 2]],
        ], ['uuid' => $temp->uuid]);

        $this->assertSame($temp->uuid, $request->validated('temp_transaction_uuid'));
    }

    public static function phoneNumberFormats(): array
    {
        return [
            'local 09 prefix' => ['0917 123 4567', '+639171234567'],
            'missing leading zero' => ['9171234567', '+639171234567'],
            'country code without plus' => ['63-917-123-4567', '+639171234567'],
            'already international' => ['+639171234567', '+639171234567'],
        ];
    }

    #[Test]
    #[DataProvider('phoneNumberFormats')]
    public function guestCheckoutNormalizesPhilippineMobileNumbers(string $input, string $expected): void
    {
        $event = $this->makeEvent();
        $ticket = $this->makeEventTicket($event);

        $request = $this->assertFormRequestPasses(GuestTempTransactionRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [['event_ticket_uuid' => $ticket->uuid, 'quantity' => 1]],
            'guest' => [
                'first_name' => 'Guest',
                'last_name' => 'Buyer',
                'address' => 'Manila',
                'email' => 'guest@test.com',
                'phone_number' => $input,
            ],
        ]);

        $this->assertSame($expected, $request->validated('guest.phone_number'));
    }

    #[Test]
    public function guestCheckoutRejectsUnrecognisedPhoneNumbers(): void
    {
        $errors = $this->validateFormRequest(GuestTempTransactionRequest::class, [
            'guest' => ['phone_number' => '12345'],
        ]);

        $this->assertFormRequestError($errors, 'guest.phone_number', 'Enter a valid mobile number (e.g. 09171234567).');
        $this->assertFormRequestError($errors, 'guest.first_name', 'First name is required.');
    }

    #[Test]
    #[DataProvider('phoneNumberFormats')]
    public function registrationNormalizesPhilippineMobileNumbers(string $input, string $expected): void
    {
        $request = $this->assertFormRequestPasses(UserRegistrationRequest::class, [
            'first_name' => 'New',
            'last_name' => 'User',
            'address' => 'Manila',
            'phone_number' => $input,
            'email' => 'new-user@test.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'terms_accepted' => true,
        ]);

        $this->assertSame($expected, $request->validated('phone_number'));
    }

    #[Test]
    public function affiliatePayoutCannotExceedAvailableCommission(): void
    {
        $partner = $this->makeApprovedPartnerWithCommission(5000);
        $this->actingAs($partner, 'api');

        $errors = $this->validateFormRequest(StoreAffiliatePayoutRequest::class, ['amount' => 5000]);

        $this->assertSame(['amount' => ['Amount exceeds available commission (PHP 4,500.00).']], $errors);
    }

    #[Test]
    public function affiliatePayoutWithinAvailableCommissionPasses(): void
    {
        $partner = $this->makeApprovedPartnerWithCommission(5000);
        $this->actingAs($partner, 'api');

        $request = $this->assertFormRequestPasses(StoreAffiliatePayoutRequest::class, ['amount' => 4500]);

        $this->assertSame(4500, $request->validated('amount'));
    }

    #[Test]
    public function affiliatePayoutEndpointReportsTheExceededCommission(): void
    {
        $partner = $this->makeApprovedPartnerWithCommission(2000);

        $this->actingAs($partner, 'api')
            ->postJson('/api/v1/customer/affiliate/payout-requests', ['amount' => 1900])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount' => 'Amount exceeds available commission (PHP 1,800.00).']);

        $this->assertDatabaseMissing('affiliate_payout_requests', ['user_uuid' => $partner->uuid]);
    }

    private function routeFor(string $requestClass, Event $event): array
    {
        return $requestClass === UpdateTempTransactionRequest::class
            ? ['uuid' => $this->makeTempTransaction($event)->uuid]
            : [];
    }

    private function makeTempTransaction(Event $event): TempTransaction
    {
        return TempTransaction::create([
            'user_uuid' => $this->makeCustomer()->uuid,
            'event_uuid' => $event->uuid,
            'total_amount' => 100,
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
        ]);
    }

    private function makeApprovedPartnerWithCommission(float $grossCommission): User
    {
        $partner = $this->makeCustomer();
        $this->seedApprovedAffiliate($partner, 'PARTNER' . random_int(100, 999), now()->subYears(7), now()->subYears(7));

        $event = $this->makeEvent(['affiliate_enabled' => true, 'affiliate_commission_percent' => 10]);
        $transaction = $this->makeTransaction($this->makeCustomer(), $event, [
            'total_amount' => $grossCommission * 10,
            'sub_total' => $grossCommission * 10,
            'affiliate_partner_uuid' => $partner->uuid,
        ]);
        $conversion = AffiliateConversion::create([
            'partner_user_uuid' => $partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $event->uuid,
            'order_total' => $grossCommission * 10,
            'commission_percent' => 10,
            'commission_amount' => $grossCommission,
        ]);
        DB::table('affiliate_conversions')->where('uuid', $conversion->uuid)->update([
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        return $partner;
    }
}
