<?php

namespace Tests\Feature\Coverage\GapCheckout;

use App\Constants\GeneralConstants;
use App\Http\Repositories\TempTransactionRepository;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\PaymentGateway;
use App\Models\PromoCode;
use App\Models\Role;
use App\Models\TempTransaction;
use App\Models\TempTransactionOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Checkout\CheckoutHandlerRegistry;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;

trait GapCheckoutFixtures
{
    protected function customerRole(): Role
    {
        return Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer', 'is_admin' => false],
        );
    }

    protected function createCustomer(array $attributes = []): User
    {
        return User::create(array_merge([
            'role_uuid' => $this->customerRole()->uuid,
            'email' => 'buyer-' . Str::lower(Str::random(6)) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Bea',
            'last_name' => 'Buyer',
            'phone_number' => '09170000000',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    /**
     * @param array<int, array<string, mixed>>|null $paymentMethods
     */
    protected function createOrganization(?array $paymentMethods = null): Organization
    {
        $payload = [
            'name' => 'Checkout Org ' . Str::random(4),
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'org-' . Str::lower(Str::random(6)) . '@test.com',
            'bank_name' => 'Test Bank',
            'bank_branch' => 'Main',
            'bank_address' => 'Bank Address',
            'bank_account_name' => 'Jane Doe',
            'bank_account_number' => '1234567890',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ];
        if ($paymentMethods !== null) {
            $payload['payment_methods'] = $paymentMethods;
        }

        return Organization::create($payload);
    }

    protected function createEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Checkout Event ' . Str::random(4),
            'event_description' => 'Checkout coverage',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    protected function createEventTicket(Event $event, array $attributes = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $event->uuid,
            'code' => 'GA-' . Str::random(4),
            'name' => 'General',
            'description' => 'General admission',
            'price' => 500,
            'max_ticket' => 100,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }

    protected function createPromoCode(array $attributes = []): PromoCode
    {
        return PromoCode::create(array_merge([
            'organization_uuid' => $attributes['organization_uuid'] ?? $this->createOrganization()->uuid,
            'code' => 'SAVE' . Str::upper(Str::random(4)),
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }

    /**
     * Temp transaction holding a single order line for the given ticket.
     */
    protected function createTempTransaction(User $user, EventTicket $ticket, array $attributes = [], array $orderAttributes = []): TempTransaction
    {
        $quantity = $orderAttributes['quantity'] ?? 1;
        $lineTotal = $attributes['total_amount'] ?? (float) $ticket->price * $quantity;

        $tempTransaction = TempTransaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'transactionable_type' => 'event',
            'transactionable_uuid' => $ticket->event_uuid,
            'event_uuid' => $ticket->event_uuid,
            'sub_total' => $lineTotal,
            'discount' => 0,
            'tax_amount' => 0,
            'total_amount' => $lineTotal,
        ], $attributes));

        TempTransactionOrder::create(array_merge([
            'user_uuid' => $user->uuid,
            'temp_transaction_uuid' => $tempTransaction->uuid,
            'event_ticket_uuid' => $ticket->uuid,
            'quantity' => $quantity,
            'price' => $ticket->price,
            'discount' => 0,
            'total_amount' => $lineTotal,
        ], $orderAttributes));

        return $tempTransaction->fresh();
    }

    protected function createTransaction(User $user, Event $event, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'transactionable_type' => 'event',
            'transactionable_uuid' => $event->uuid,
            'event_uuid' => $event->uuid,
            'order_number' => 'ORD-' . Str::upper(Str::random(8)),
            'payment_order_id' => 'PO-' . Str::upper(Str::random(8)),
            'payment_provider' => 'paypal',
            'payment_id' => 'ORDER-' . Str::upper(Str::random(6)),
            'total_amount' => 500,
            'sub_total' => 500,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
        ], $attributes));
    }

    protected function createGateway(string $provider, string $secret = 'sk_test_gap'): PaymentGateway
    {
        return PaymentGateway::create([
            'name' => ucfirst($provider),
            'provider' => $provider,
            'environment' => 'testing',
            'gateway_id' => $provider . '-' . uniqid(),
            'public_key' => 'pk_test_gap',
            'secret_key' => $secret,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    /**
     * Checkout handler registry without handlers, so the controller falls back to the SuccessPaymentProcess job.
     */
    protected function useEmptyCheckoutHandlerRegistry(): void
    {
        $this->app->instance(CheckoutHandlerRegistry::class, new CheckoutHandlerRegistry());
    }

    /**
     * Real repository whose given method throws, to drive the controllers' error branches.
     */
    protected function failingRepository(string $method, string $message = 'Database unavailable'): void
    {
        /** @var TempTransactionRepository&MockInterface $repository */
        $repository = Mockery::mock(TempTransactionRepository::class, [
            new TempTransaction(),
            new TempTransactionOrder(),
            new Transaction(),
        ])->makePartial();
        $repository->shouldReceive($method)->andThrow(new \RuntimeException($message));

        $this->app->instance(TempTransactionRepository::class, $repository);
    }

    protected function configurePaypal(): void
    {
        config([
            'services.paypal.client_id' => 'paypal-client',
            'services.paypal.client_secret' => 'paypal-secret',
            'services.paypal.mode' => 'sandbox',
        ]);
    }
}
