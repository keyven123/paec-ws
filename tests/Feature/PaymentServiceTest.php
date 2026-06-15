<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketCoupon;
use App\Models\AffiliateConversion;
use App\Models\PromoCode;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\TicketCoupon;
use App\Models\TempTransaction;
use App\Models\TempTransactionOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Organization;
use App\Services\Payments\PaymentServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;
    use SeedsUserAffiliate;

    protected User $user;
    protected Event $event;
    protected Schedule $schedule;
    protected ScheduleTime $scheduleTime;
    protected TempTransaction $tempTransaction;
    protected Role $customerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->user = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);

        $this->event = Event::create([
            'event_name' => 'Payment Test Event',
            'event_description' => 'Test event for payment tests',
            'contact_email' => 'contact@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $this->schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-06-01',
            'date_to' => '2025-06-02',
            'status' => 'published',
        ]);

        $this->scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $this->tempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'total_amount' => 1000.00,
            'sub_total' => 1000.00,
            'tax_amount' => 0.00,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymentServiceFactoryCreatesPaymongoService()
    {
        $service = PaymentServiceFactory::create(GeneralConstants::PAYMENT_PROVIDERS['PAYMONGO']);
        $this->assertInstanceOf(\App\Services\Payments\PayMongoService::class, $service);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymentServiceFactoryCreatesPaypalService()
    {
        $service = PaymentServiceFactory::create(GeneralConstants::PAYMENT_PROVIDERS['PAYPAL']);
        $this->assertInstanceOf(\App\Services\Payments\PayPalService::class, $service);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymentServiceFactoryThrowsExceptionForInvalidProvider()
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentServiceFactory::create('invalid_provider');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymongoCreatePaymentSuccess()
    {
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_123',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_test_123',
                        'status' => 'active',
                        'line_items' => [
                            ['amount' => 100000, 'currency' => 'PHP']
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = PaymentServiceFactory::create('paymongo');
        $result = $service->createPayment([
            'amount' => 1000.00,
            'currency' => 'PHP',
            'description' => 'Test payment'
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('cs_test_123', $result['payment_id']);
        $this->assertEquals(1000.00, $result['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymongoCreatePaymentFailure()
    {
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'errors' => [
                    ['detail' => 'Invalid amount']
                ]
            ], 400)
        ]);

        $service = PaymentServiceFactory::create('paymongo');
        $result = $service->createPayment([
            'amount' => -100.00,
            'currency' => 'PHP'
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paypalCreatePaymentSuccess()
    {
        // Mock PayPal OAuth response
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 32400
            ], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER_TEST_123',
                'status' => 'CREATED',
                'links' => [
                    [
                        'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER_TEST_123',
                        'rel' => 'approve',
                        'method' => 'GET'
                    ]
                ]
            ], 200)
        ]);

        $service = PaymentServiceFactory::create('paypal');
        $result = $service->createPayment([
            'amount' => 1000.00,
            'currency' => 'PHP',
            'description' => 'Test payment'
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('ORDER_TEST_123', $result['payment_id']);
        $this->assertEquals('CREATED', $result['status']);
        $this->assertArrayHasKey('approval_url', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutWithPaymongoSuccess()
    {
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_123',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_test_123',
                        'status' => 'active',
                        'line_items' => [
                            ['amount' => 100000, 'currency' => 'PHP']
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $this->tempTransaction->uuid,
                'payment_provider' => 'paymongo',
                'payment_methods' => ['card', 'gcash']
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'transaction' => [
                    'uuid',
                    'payment_id',
                    'payment_provider',
                    'payment_status'
                ],
                'tickets',
                'payment' => [
                    'provider',
                    'payment_id',
                    'status',
                ]
            ]);

        $this->assertDatabaseHas('transactions', [
            'payment_provider' => 'paymongo',
            'payment_id' => 'cs_test_123'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutWithPaypalSuccess()
    {
        // Mock PayPal API responses
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 32400
            ], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER_TEST_123',
                'status' => 'CREATED',
                'links' => [
                    [
                        'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER_TEST_123',
                        'rel' => 'approve',
                        'method' => 'GET'
                    ]
                ]
            ], 200)
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $this->tempTransaction->uuid,
                'payment_provider' => 'paypal',
                'return_url' => 'https://example.com/success',
                'cancel_url' => 'https://example.com/cancel'
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'transaction' => [
                    'uuid',
                    'payment_id',
                    'payment_provider',
                    'payment_status'
                ],
                'tickets',
                'payment' => [
                    'provider',
                    'payment_id',
                    'status',
                    'approval_url'
                ]
            ]);

        // Verify transaction was created
        $this->assertDatabaseHas('transactions', [
            'payment_provider' => 'paypal',
            'payment_id' => 'ORDER_TEST_123'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutWithPaypalCardCreatesCardOrderWithoutRedirect()
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 32400
            ], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'CARD_ORDER_TEST_123',
                'status' => 'CREATED',
            ], 200)
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout-paypal-card', [
                'temp_transaction_uuid' => $this->tempTransaction->uuid,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'paypal_order_id' => 'CARD_ORDER_TEST_123',
                'payment' => [
                    'provider' => 'paypal',
                    'flow' => 'card',
                    'payment_id' => 'CARD_ORDER_TEST_123',
                    'status' => 'CREATED',
                ],
            ])
            ->assertJsonStructure([
                'transaction' => [
                    'uuid',
                    'payment_id',
                    'payment_provider',
                    'payment_status',
                ],
                'orders',
                'tickets',
                'transaction_uuid',
            ])
            ->assertJsonMissingPath('redirect_url');

        $this->assertDatabaseHas('transactions', [
            'payment_provider' => 'paypal',
            'payment_id' => 'CARD_ORDER_TEST_123',
            'payment_status' => 'CREATED',
        ]);

        $this->assertDatabaseMissing('temp_transactions', [
            'uuid' => $this->tempTransaction->uuid,
        ]);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }

            $payload = $request->data();

            return $payload['intent'] === 'CAPTURE'
                && $payload['purchase_units'][0]['amount']['currency_code'] === 'PHP'
                && $payload['purchase_units'][0]['amount']['value'] === '1000.00'
                && $payload['payment_source']['card']['experience_context']['shipping_preference'] === 'NO_SHIPPING'
                && !isset($payload['application_context']);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutAppliesPromoCodeDiscountToTransactionTotalAmount()
    {
        // Keep this test compatible with sqlite test schema variants.
        if (!Schema::hasColumn('tickets', 'visit_policy')) {
            Schema::table('tickets', function ($table) {
                $table->string('visit_policy')->nullable();
            });
        }
        if (!Schema::hasColumn('tickets', 'is_bundle')) {
            Schema::table('tickets', function ($table) {
                $table->boolean('is_bundle')->default(false);
            });
        }
        if (!Schema::hasColumn('tickets', 'bundle_quantity')) {
            Schema::table('tickets', function ($table) {
                $table->integer('bundle_quantity')->nullable();
            });
        }
        if (!Schema::hasColumn('transaction_orders', 'valid_until')) {
            Schema::table('transaction_orders', function ($table) {
                $table->dateTime('valid_until')->nullable();
            });
        }
        if (!Schema::hasColumn('temp_transactions', 'promo_code_uuid')) {
            Schema::table('temp_transactions', function ($table) {
                $table->uuid('promo_code_uuid')->nullable();
            });
        }
        if (!Schema::hasColumn('temp_transactions', 'promo_code_discount')) {
            Schema::table('temp_transactions', function ($table) {
                $table->decimal('promo_code_discount', 12, 2)->default(0);
            });
        }

        $organization = Organization::create([
            'name' => 'Promo Test Organization',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Doe',
            'email' => 'promo-org@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $openEvent = Event::create([
            'organization_uuid' => $organization->uuid,
            'event_name' => 'Promo Discount Event',
            'event_description' => 'Event for promo code discount test',
            'contact_email' => 'promo@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $openSchedule = Schedule::create([
            'event_uuid' => $openEvent->uuid,
            'date_from' => '2025-07-01',
            'date_to' => '2025-07-01',
            'status' => 'published',
        ]);

        $openScheduleTime = ScheduleTime::create([
            'schedule_uuid' => $openSchedule->uuid,
            'time_start' => '09:00:00',
            'time_end' => '11:00:00',
            'status' => 'published',
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $openEvent->uuid,
            'schedule_uuid' => $openSchedule->uuid,
            'schedule_time_uuid' => $openScheduleTime->uuid,
            'code' => 'PROMO-TKT-001',
            'name' => 'Promo Ticket',
            'price' => 1000.00,
            'is_bundle' => false,
            'display_order' => 1,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => true,
        ]);

        $promoCode = PromoCode::create([
            'organization_uuid' => $organization->uuid,
            'code' => 'PROMO10',
            'description' => '10% off',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10.00,
            'is_unlimited' => true,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addDays(30),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Subtotal: 1000 * 2 = 2000. Promo: 10% = 200. Total = 1800.
        $tempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $openEvent->uuid,
            'schedule_uuid' => $openSchedule->uuid,
            'schedule_time_uuid' => $openScheduleTime->uuid,
            'promo_code_uuid' => $promoCode->uuid,
            'promo_code_discount' => 200.00,
            'sub_total' => 2000.00,
            'discount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 1800.00,
        ]);

        TempTransactionOrder::create([
            'user_uuid' => $this->user->uuid,
            'temp_transaction_uuid' => $tempTransaction->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'quantity' => 2,
            'price' => 1000.00,
            'discount' => 0.00,
            'total_amount' => 2000.00,
        ]);

        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_promo_123',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_promo_123',
                        'status' => 'active',
                        'line_items' => [
                            ['amount' => 180000, 'currency' => 'PHP']
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $tempTransaction->uuid,
                'payment_provider' => 'paymongo',
                'payment_methods' => ['card'],
            ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $this->assertEquals(1800.00, (float) $response->json('transaction.total_amount'));

        $this->assertDatabaseHas('transactions', [
            'payment_provider' => 'paymongo',
            'payment_id' => 'cs_promo_123',
            'promo_code_uuid' => $promoCode->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutWithInvalidTempTransaction()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => 'invalid-uuid',
                'payment_provider' => 'paymongo'
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['temp_transaction_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutWithInvalidPaymentProvider()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $this->tempTransaction->uuid,
                'payment_provider' => 'invalid_provider'
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_provider']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutHandlesPaymentServiceFailure()
    {
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'errors' => [
                    ['detail' => 'Payment service unavailable']
                ]
            ], 500)
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $this->tempTransaction->uuid,
                'payment_provider' => 'paymongo'
            ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Checkout failed. Please try again.'
            ]);

        $this->assertDatabaseMissing('transactions', [
            'temp_transaction_uuid' => $this->tempTransaction->uuid
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutFreeRejectsNonFreeTransactions()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout-free', [
                'temp_transaction_uuid' => $this->tempTransaction->uuid,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'This transaction requires payment. Please use the regular checkout.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanGetTempTransactionsForAuthenticatedUser()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/customer/temp-transactions?event_uuid=' . $this->event->uuid);

        $response->assertStatus(200);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowTempTransactionByUuid()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/customer/temp-transactions/' . $this->tempTransaction->uuid);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'user_uuid',
                    'event_uuid',
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function completePaymentReturnsSuccessForFreeTransaction()
    {
        $transaction = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_provider' => 'free',
            'payment_method' => 'free',
            'order_number' => 'ORD-FREE-TEST-001',
            'total_amount' => 0.00,
            'sub_total' => 0.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/transactions/' . $transaction->uuid . '/complete');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'payment_status' => 'paid',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function completePaymentSucceedsWhenUserAlreadyHasTicketAndNoAffiliateConversionData(): void
    {
        // Fake a successful PayMongo checkout session status check.
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/*' => Http::response([
                'data' => [
                    'id' => 'cs_test_123',
                    'attributes' => [
                        'status' => 'paid',
                        'line_items' => [
                            ['amount' => 100000, 'currency' => 'PHP'],
                        ],
                        'payment_intent' => [
                            'id' => 'pi_test_123',
                            'attributes' => [
                                'status' => 'succeeded',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Ensure an event ticket exists for the user's ticket.
        $eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'code' => 'TKT-EXIST-001',
            'name' => 'Existing Ticket Type',
            'price' => 1000.00,
            'is_bundle' => false,
            'display_order' => 1,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => true,
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_provider' => 'paymongo',
            'payment_id' => 'cs_test_123',
            'payment_method' => 'card',
            'order_number' => 'ORD-PAY-TEST-EXIST-001',
            'total_amount' => 1000.00,
            'sub_total' => 1000.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
            // Explicitly no affiliate conversion data.
            'affiliate_partner_uuid' => null,
        ]);

        // User already has a ticket record for this transaction.
        $ticket = Ticket::create([
            'user_uuid' => $this->user->uuid,
            'organization_uuid' => null,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'ticket_number' => 'TKT-EXISTING-0001',
            'status' => GeneralConstants::TICKET_STATUSES['PENDING'],
            'attendee_name' => $this->user->full_name,
            'attendee_email' => $this->user->email,
            'attendee_contact' => $this->user->phone_number,
            'price' => 1000.00,
            'discount' => 0.00,
            'type' => Ticket::TYPES['PAID'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/transactions/' . $transaction->uuid . '/complete');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'payment_status' => 'succeeded',
            ]);

        // Ticket should not be deleted on successful payment completion.
        $this->assertDatabaseHas('tickets', [
            'uuid' => $ticket->uuid,
            'transaction_uuid' => $transaction->uuid,
            'user_uuid' => $this->user->uuid,
        ]);

        // Ensure no affiliate conversion was recorded.
        $this->assertEquals(0, AffiliateConversion::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function affiliateUserBuyingWithoutAffiliateCodeDoesNotEarnCommission(): void
    {
        // Mark the buyer as an approved affiliate.
        $this->seedApprovedAffiliate($this->user, 'SELF1');

        // Ensure the event is affiliate-enabled with a non-zero commission percent.
        $this->event->update([
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
        ]);

        // Fake successful PayMongo payment status.
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/*' => Http::response([
                'data' => [
                    'id' => 'cs_self_123',
                    'attributes' => [
                        'status' => 'paid',
                        'line_items' => [
                            ['amount' => 100000, 'currency' => 'PHP'],
                        ],
                        'payment_intent' => [
                            'id' => 'pi_self_123',
                            'attributes' => [
                                'status' => 'succeeded',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_provider' => 'paymongo',
            'payment_id' => 'cs_self_123',
            'payment_method' => 'card',
            'order_number' => 'ORD-SELF-' . uniqid(),
            'total_amount' => 1000.00,
            'sub_total' => 1000.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
            // No affiliate link/code used => no partner attribution on the transaction.
            'affiliate_partner_uuid' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/transactions/' . $transaction->uuid . '/complete');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'payment_status' => 'succeeded',
            ]);

        // Even though the buyer is an affiliate and the event has an affiliate program,
        // a purchase without affiliate attribution must not create a commission.
        $this->assertEquals(0, AffiliateConversion::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function purchaseWithAffiliateCodeRecordsCorrectCommissionForCorrectPartner(): void
    {
        // Create an approved affiliate partner with a code.
        $partner = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);
        $this->seedApprovedAffiliate($partner, 'PARTNERX');

        // Ensure the event is affiliate-enabled with a known commission percent.
        $this->event->update([
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 12.5,
        ]);

        // Fake successful PayMongo payment status.
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/*' => Http::response([
                'data' => [
                    'id' => 'cs_aff_123',
                    'attributes' => [
                        'status' => 'paid',
                        'line_items' => [
                            ['amount' => 100000, 'currency' => 'PHP'],
                        ],
                        'payment_intent' => [
                            'id' => 'pi_aff_123',
                            'attributes' => [
                                'status' => 'succeeded',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $totalAmount = 1999.99;
        $commissionPercent = 12.5;
        $expectedCommission = round($totalAmount * ($commissionPercent / 100), 2);

        // Simulate a purchase "with affiliate code" by ensuring the transaction is attributed to the partner.
        // (Attribution is stored as affiliate_partner_uuid and later used to record conversions on payment completion.)
        $transaction = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_provider' => 'paymongo',
            'payment_id' => 'cs_aff_123',
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-' . uniqid(),
            'total_amount' => $totalAmount,
            'sub_total' => $totalAmount,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
            'affiliate_partner_uuid' => $partner->uuid,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/transactions/' . $transaction->uuid . '/complete');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'payment_status' => 'succeeded',
            ]);

        $this->assertDatabaseHas('affiliate_conversions', [
            'partner_user_uuid' => $partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => $totalAmount,
            'commission_percent' => $commissionPercent,
            'commission_amount' => $expectedCommission,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cancelPaymentUpdatesTransactionStatus()
    {
        $transaction = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_provider' => 'paymongo',
            'payment_method' => 'card',
            'order_number' => 'ORD-CANCEL-TEST-001',
            'total_amount' => 1000.00,
            'sub_total' => 1000.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/transactions/' . $transaction->uuid . '/cancel');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Payment cancelled successfully',
            ]);

        $this->assertDatabaseHas('transactions', [
            'uuid' => $transaction->uuid,
            'status' => Transaction::STATUS['CANCELLED'],
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutFreeGeneratesExpectedTicketsAndCouponsForBundleAndOnceOnlyRules()
    {
        // Keep this test compatible with sqlite test schema variants.
        if (!Schema::hasColumn('tickets', 'visit_policy')) {
            Schema::table('tickets', function ($table) {
                $table->string('visit_policy')->nullable();
            });
        }
        if (!Schema::hasColumn('tickets', 'is_bundle')) {
            Schema::table('tickets', function ($table) {
                $table->boolean('is_bundle')->default(false);
            });
        }
        if (!Schema::hasColumn('tickets', 'bundle_quantity')) {
            Schema::table('tickets', function ($table) {
                $table->integer('bundle_quantity')->nullable();
            });
        }
        if (!Schema::hasColumn('transaction_orders', 'valid_until')) {
            Schema::table('transaction_orders', function ($table) {
                $table->dateTime('valid_until')->nullable();
            });
        }

        $openEvent = Event::create([
            'event_name' => 'Open Ticket Event',
            'event_description' => 'Event for coupon distribution test',
            'contact_email' => 'open@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $openSchedule = Schedule::create([
            'event_uuid' => $openEvent->uuid,
            'date_from' => '2025-07-01',
            'date_to' => '2025-07-01',
            'status' => 'published',
        ]);

        $openScheduleTime = ScheduleTime::create([
            'schedule_uuid' => $openSchedule->uuid,
            'time_start' => '09:00:00',
            'time_end' => '11:00:00',
            'status' => 'published',
        ]);

        // Non-bundle ticket: quantity 2 => 2 generated tickets, each should get all coupons.
        $nonBundleTicket = EventTicket::create([
            'event_uuid' => $openEvent->uuid,
            'schedule_uuid' => $openSchedule->uuid,
            'schedule_time_uuid' => $openScheduleTime->uuid,
            'code' => 'NB-TKT-001',
            'name' => 'Non Bundle Ticket',
            'price' => 0,
            'is_bundle' => false,
            'bundle_quantity' => null,
            'display_order' => 1,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => true,
        ]);

        $nonBundleCouponOnce = EventTicketCoupon::create([
            'event_ticket_uuid' => $nonBundleTicket->uuid,
            'name' => 'NB Once',
            'once_only' => true,
        ]);
        $nonBundleCouponMulti = EventTicketCoupon::create([
            'event_ticket_uuid' => $nonBundleTicket->uuid,
            'name' => 'NB Multi',
            'once_only' => false,
        ]);

        $nonBundleTempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $openEvent->uuid,
            'schedule_uuid' => $openSchedule->uuid,
            'schedule_time_uuid' => $openScheduleTime->uuid,
            'total_amount' => 0.00,
            'sub_total' => 0.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        TempTransactionOrder::create([
            'user_uuid' => $this->user->uuid,
            'temp_transaction_uuid' => $nonBundleTempTransaction->uuid,
            'event_ticket_uuid' => $nonBundleTicket->uuid,
            'quantity' => 2,
            'price' => 0.00,
            'discount' => 0.00,
            'total_amount' => 0.00,
        ]);

        $nonBundleCheckoutResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout-free', [
                'temp_transaction_uuid' => $nonBundleTempTransaction->uuid,
            ]);

        $nonBundleCheckoutResponse->assertStatus(201)->assertJson(['success' => true]);
        $nonBundleTransactionUuid = $nonBundleCheckoutResponse->json('transaction.uuid');

        $nonBundleTickets = Ticket::where('transaction_uuid', $nonBundleTransactionUuid)->get();
        $this->assertCount(2, $nonBundleTickets);

        $nonBundleTicketCoupons = TicketCoupon::whereIn('ticket_uuid', $nonBundleTickets->pluck('uuid'))->get();
        $this->assertCount(4, $nonBundleTicketCoupons);
        $this->assertEquals(2, $nonBundleTicketCoupons->where('event_ticket_coupon_uuid', $nonBundleCouponOnce->uuid)->count());
        $this->assertEquals(2, $nonBundleTicketCoupons->where('event_ticket_coupon_uuid', $nonBundleCouponMulti->uuid)->count());

        // Bundle ticket: quantity 1 with bundle_quantity 3 => 3 generated tickets.
        // once_only coupon should be generated once per bundle set => 1 total.
        // non-once_only coupon should be generated per ticket => 3 total.
        $bundleTicket = EventTicket::create([
            'event_uuid' => $openEvent->uuid,
            'schedule_uuid' => $openSchedule->uuid,
            'schedule_time_uuid' => $openScheduleTime->uuid,
            'code' => 'B-TKT-001',
            'name' => 'Bundle Ticket',
            'price' => 0,
            'is_bundle' => true,
            'bundle_quantity' => 3,
            'display_order' => 2,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => true,
        ]);

        $bundleCouponOnce = EventTicketCoupon::create([
            'event_ticket_uuid' => $bundleTicket->uuid,
            'name' => 'B Once',
            'once_only' => true,
        ]);
        $bundleCouponMulti = EventTicketCoupon::create([
            'event_ticket_uuid' => $bundleTicket->uuid,
            'name' => 'B Multi',
            'once_only' => false,
        ]);

        $bundleTempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $openEvent->uuid,
            'schedule_uuid' => $openSchedule->uuid,
            'schedule_time_uuid' => $openScheduleTime->uuid,
            'total_amount' => 0.00,
            'sub_total' => 0.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        TempTransactionOrder::create([
            'user_uuid' => $this->user->uuid,
            'temp_transaction_uuid' => $bundleTempTransaction->uuid,
            'event_ticket_uuid' => $bundleTicket->uuid,
            'quantity' => 1,
            'price' => 0.00,
            'discount' => 0.00,
            'total_amount' => 0.00,
        ]);

        $bundleCheckoutResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout-free', [
                'temp_transaction_uuid' => $bundleTempTransaction->uuid,
            ]);

        $bundleCheckoutResponse->assertStatus(201)->assertJson(['success' => true]);
        $bundleTransactionUuid = $bundleCheckoutResponse->json('transaction.uuid');

        $bundleTickets = Ticket::where('transaction_uuid', $bundleTransactionUuid)->get();
        $this->assertCount(3, $bundleTickets);

        $bundleTicketCoupons = TicketCoupon::whereIn('ticket_uuid', $bundleTickets->pluck('uuid'))->get();
        $this->assertCount(4, $bundleTicketCoupons);
        $this->assertEquals(1, $bundleTicketCoupons->where('event_ticket_coupon_uuid', $bundleCouponOnce->uuid)->count());
        $this->assertEquals(3, $bundleTicketCoupons->where('event_ticket_coupon_uuid', $bundleCouponMulti->uuid)->count());
    }
}
