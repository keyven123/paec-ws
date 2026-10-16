<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Http\Controllers\Customer\CartController;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\PaymentGateway;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\CartReminderNotification;
use App\Notifications\PaymentCancelledNotification;
use App\Notifications\PaymentSuccessfulNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\GapCheckout\GapCheckoutFixtures;
use Tests\TestCase;

class CartCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use GapCheckoutFixtures;

    private const CART = '/api/v1/customer/cart';
    private const PAYNAMICS_CREATE = 'https://payin.payserv.net/paygate/transactions/';
    private const PAYNAMICS_LINK = 'https://payin.payserv.net/paygate/pay/cart123';

    private User $customer;
    private Event $arcade;
    private Event $waterPark;
    private EventTicket $arcadePass;
    private EventTicket $waterParkPass;
    private PaymentGateway $paynamics;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Notification::fake();
        $this->useEmptyCheckoutHandlerRegistry();
        config(['services.paynamics.testing_url' => 'https://payin.payserv.net']);

        $this->customer = $this->createCustomer(['first_name' => 'Carla']);
        $this->arcade = $this->createEvent(['event_name' => 'Neon Arcade']);
        $this->waterPark = $this->createEvent(['event_name' => 'Splash Water Park']);
        $this->arcadePass = $this->createEventTicket($this->arcade, ['name' => 'Arcade Pass', 'price' => 500]);
        $this->waterParkPass = $this->createEventTicket($this->waterPark, ['name' => 'Water Park Day', 'price' => 300]);

        $this->paynamics = PaymentGateway::create([
            'name' => 'Paynamics',
            'provider' => 'paynamics',
            'environment' => 'testing',
            'gateway_id' => 'paynamics-cart',
            'public_key' => 'MERCHANT-CART',
            'secret_key' => 'cart-merchant-key',
            'api_username' => 'cart-user',
            'api_password' => 'cart-password',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->arcade->paymentGateways()->sync([$this->paynamics->uuid]);
        $this->waterPark->paymentGateways()->sync([$this->paynamics->uuid]);
    }

    private function itemPayload(EventTicket $ticket, int $quantity = 1, array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => $ticket->event_uuid,
            'visit_date' => now()->addDays(5)->toDateString(),
            'tickets' => [['event_ticket_uuid' => $ticket->uuid, 'quantity' => $quantity]],
            'details' => [
                'attractionName' => 'Shown name',
                'location' => 'Pasay City',
                'tickets' => [['ticketTypeId' => $ticket->uuid, 'name' => $ticket->name, 'unitPrice' => 500, 'quantity' => $quantity]],
            ],
        ], $overrides);
    }

    private function addToCart(EventTicket $ticket, int $quantity = 1, array $overrides = []): string
    {
        return $this->actingAs($this->customer, 'api')
            ->postJson(self::CART . '/items', $this->itemPayload($ticket, $quantity, $overrides))
            ->assertCreated()
            ->json('item_uuid');
    }

    private function fillCart(): void
    {
        $this->addToCart($this->arcadePass, 2);
        $this->addToCart($this->waterParkPass, 1);
    }

    /**
     * Answers the payment link request, then every status query with the given Paynamics code.
     */
    private function fakePaynamics(string $queryCode = 'GR033'): void
    {
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($queryCode) {
            if ($request->url() === self::PAYNAMICS_CREATE) {
                return Http::response([
                    'response_code' => 'GR033',
                    'response_message' => 'Transaction Pending',
                    'payment_action_info' => self::PAYNAMICS_LINK,
                ]);
            }

            return Http::response([
                'response_code' => $queryCode,
                'response_message' => 'message',
                'request_id' => $request['org_trxid2'],
            ]);
        });
    }

    private function checkoutCart(array $payload = [])
    {
        return $this->actingAs($this->customer, 'api')->postJson(self::CART . '/checkout', array_merge([
            'payment_gateway_uuid' => $this->paynamics->uuid,
            'payment_channel' => 'gcash',
        ], $payload));
    }

    private function cart(): Cart
    {
        return Cart::query()->where('user_uuid', $this->customer->uuid)->firstOrFail();
    }

    #[Test]
    public function a_signed_in_customer_starts_with_an_empty_cart(): void
    {
        $this->actingAs($this->customer, 'api')->getJson(self::CART)
            ->assertOk()
            ->assertExactJson(['items' => []]);

        $this->app['auth']->forgetGuards();
        $this->getJson(self::CART)->assertUnauthorized();
    }

    #[Test]
    public function cart_rejects_visit_dates_after_ticket_available_to(): void
    {
        $this->arcadePass->update([
            'available_from' => '2026-01-01 00:00:00',
            'available_to' => '2026-12-31 20:35:00',
        ]);

        $this->actingAs($this->customer, 'api')
            ->postJson(self::CART . '/items', $this->itemPayload($this->arcadePass, 1, [
                'visit_date' => '2026-12-31',
            ]))
            ->assertCreated();

        $this->actingAs($this->customer, 'api')
            ->postJson(self::CART . '/items', $this->itemPayload($this->arcadePass, 1, [
                'visit_date' => '2027-01-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tickets.0.event_ticket_uuid']);
    }

    #[Test]
    public function adding_an_activity_keeps_its_tickets_and_restarts_the_reminder_wait(): void
    {
        $this->addToCart($this->arcadePass);
        $this->cart()->update(['reminder_sent_at' => now(), 'last_changed_at' => now()->subHours(3)]);

        $uuid = $this->addToCart($this->waterParkPass, 3, ['event_location_uuid' => null]);

        $this->actingAs($this->customer, 'api')->getJson(self::CART)
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.1.uuid', $uuid)
            ->assertJsonPath('items.1.visit_date', now()->addDays(5)->toDateString())
            ->assertJsonPath('items.1.event_name', $this->waterPark->event_name)
            ->assertJsonPath('items.1.tickets.0.event_ticket_uuid', $this->waterParkPass->uuid)
            ->assertJsonPath('items.1.tickets.0.quantity', 3)
            ->assertJsonPath('items.1.tickets.0.name', 'Water Park Day')
            ->assertJsonPath('items.1.tickets.0.unit_price', 300)
            ->assertJsonPath('items.1.tickets.0.original_unit_price', 300)
            ->assertJsonPath('items.1.details.location', 'Pasay City')
            ->assertJsonPath('items.1.details.tickets.0.name', 'Water Park Day');

        $cart = $this->cart();
        $this->assertNull($cart->reminder_sent_at);
        $this->assertTrue($cart->last_changed_at->gt(now()->subMinute()));
    }

    #[Test]
    public function the_cart_prices_tickets_from_the_server_when_saved_details_are_incomplete(): void
    {
        Cart::forUser($this->customer)->items()->create([
            'event_uuid' => $this->waterPark->uuid,
            'visit_date' => now()->addDays(5)->toDateString(),
            'tickets' => [
                ['event_ticket_uuid' => $this->waterParkPass->uuid, 'quantity' => 2],
                ['event_ticket_uuid' => (string) Str::uuid(), 'quantity' => 1],
            ],
            'details' => ['attractionName' => 'Water Park', 'location' => 'Pasay City'],
        ]);

        $this->actingAs($this->customer, 'api')->getJson(self::CART)
            ->assertOk()
            ->assertJsonPath('items.0.event_name', $this->waterPark->event_name)
            ->assertJsonPath('items.0.tickets.0.name', 'Water Park Day')
            ->assertJsonPath('items.0.tickets.0.unit_price', 300)
            ->assertJsonPath('items.0.tickets.1.name', null)
            ->assertJsonPath('items.0.tickets.1.unit_price', null);
    }

    #[Test]
    public function the_cart_holds_a_limited_number_of_activities(): void
    {
        for ($i = 0; $i < CartController::MAX_ITEMS; $i++) {
            $this->addToCart($this->arcadePass);
        }

        $this->actingAs($this->customer, 'api')
            ->postJson(self::CART . '/items', $this->itemPayload($this->arcadePass))
            ->assertUnprocessable()
            ->assertJsonPath('errors.cart.0', 'Your cart can hold up to 10 activities. Check out or remove one first.');
    }

    #[Test]
    public function only_bookable_activities_and_dates_can_be_added(): void
    {
        $seated = $this->createEvent(['event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION']]);
        $seatedTicket = $this->createEventTicket($seated);
        $closedToday = $this->createEvent(['today_cutoff_time' => '00:00:00']);
        $closedTicket = $this->createEventTicket($closedToday);

        $this->actingAs($this->customer, 'api');
        $this->postJson(self::CART . '/items', $this->itemPayload($seatedTicket))
            ->assertUnprocessable()
            ->assertJsonPath('errors.event_uuid.0', 'Activities with seat selection cannot be added to the cart.');
        $this->postJson(self::CART . '/items', $this->itemPayload($closedTicket, 1, ['visit_date' => now(config('app.timezone'))->toDateString()]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit_date.0', "Today's visit date is no longer available. Please choose another date.");
        $this->postJson(self::CART . '/items', $this->itemPayload($this->arcadePass, 1, ['event_uuid' => $this->waterPark->uuid]))
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors) => isset($errors['tickets.0.event_ticket_uuid']));

        $this->assertSame(0, CartItem::count());
    }

    #[Test]
    public function quantities_can_be_changed_and_activities_removed(): void
    {
        $uuid = $this->addToCart($this->arcadePass);
        $this->cart()->update(['reminder_sent_at' => now()]);

        $this->actingAs($this->customer, 'api')
            ->putJson(self::CART . "/items/{$uuid}", $this->itemPayload($this->arcadePass, 4))
            ->assertOk()
            ->assertJsonPath('item_uuid', $uuid)
            ->assertJsonPath('items.0.tickets.0.quantity', 4);
        $this->assertNull($this->cart()->reminder_sent_at);

        $this->deleteJson(self::CART . "/items/{$uuid}")
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    #[Test]
    public function customers_cannot_touch_another_customers_cart(): void
    {
        $uuid = $this->addToCart($this->arcadePass);
        $other = $this->createCustomer();

        $this->actingAs($other, 'api')
            ->putJson(self::CART . "/items/{$uuid}", $this->itemPayload($this->arcadePass, 2))
            ->assertNotFound();
        $this->actingAs($other, 'api')->deleteJson(self::CART . "/items/{$uuid}")->assertNotFound();

        $this->assertSame(1, CartItem::count());
    }

    #[Test]
    public function an_empty_cart_cannot_be_checked_out(): void
    {
        $this->checkoutCart()
            ->assertUnprocessable()
            ->assertJsonPath('errors.cart.0', 'Your cart is empty.');
    }

    #[Test]
    public function an_activity_that_was_unpublished_or_deleted_blocks_checkout(): void
    {
        $this->addToCart($this->arcadePass);
        $this->arcade->update(['status' => GeneralConstants::EVENT_STATUSES['DRAFT']]);

        $this->checkoutCart()
            ->assertUnprocessable()
            ->assertJsonPath('errors.cart.0', 'Shown name is no longer available. Please remove it from your cart.');

        CartItem::query()->update(['details' => null]);
        $this->arcade->delete();

        $this->checkoutCart()
            ->assertUnprocessable()
            ->assertJsonPath('errors.cart.0', 'An activity is no longer available. Please remove it from your cart.');
    }

    #[Test]
    public function a_visit_date_that_has_passed_blocks_checkout(): void
    {
        $this->addToCart($this->arcadePass);
        CartItem::query()->update(['visit_date' => now()->subDays(2)->toDateString()]);

        $this->checkoutCart()
            ->assertUnprocessable()
            ->assertJsonPath('errors.cart.0', 'The date of visit for Neon Arcade is no longer available. Please remove it and book it again with a new date.');
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function a_promo_code_that_ran_out_is_removed_before_paying(): void
    {
        $organization = $this->createOrganization();
        $active = $this->createPromoCode(['organization_uuid' => $organization->uuid]);
        $usedUp = $this->createPromoCode(['organization_uuid' => $organization->uuid, 'is_unlimited' => false, 'max_use' => 1, 'used_count' => 1]);
        $this->addToCart($this->arcadePass, 1, ['promo_code_uuid' => $active->uuid]);
        $this->addToCart($this->waterParkPass, 1, [
            'promo_code_uuid' => $usedUp->uuid,
            'details' => ['appliedPromoCode' => ['uuid' => $usedUp->uuid, 'code' => $usedUp->code], 'promoDiscount' => 30],
        ]);
        $this->cart()->update(['reminder_sent_at' => now()]);

        $this->checkoutCart()
            ->assertUnprocessable()
            ->assertJsonPath('errors.cart.0', 'The promo code for Splash Water Park is no longer available and was removed. Please review your total.');

        $items = $this->cart()->items;
        $this->assertSame($active->uuid, $items[0]->promo_code_uuid);
        $this->assertNull($items[1]->promo_code_uuid);
        $this->assertSame(['appliedPromoCode' => null, 'promoDiscount' => 0], $items[1]->details);
        $this->assertNull($this->cart()->reminder_sent_at);
    }

    #[Test]
    public function a_paid_cart_needs_a_payment_method_every_activity_accepts(): void
    {
        $this->fillCart();

        $this->checkoutCart(['payment_gateway_uuid' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_gateway_uuid.0', 'Please select a payment method.');

        $this->waterPark->paymentGateways()->detach();
        $this->checkoutCart()
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_gateway_uuid.0', 'This payment method is not available for Splash Water Park.');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(2, CartItem::count());
    }

    #[Test]
    public function paynamics_needs_an_enabled_payment_option(): void
    {
        $this->fillCart();

        $this->checkoutCart(['payment_channel' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_channel.0', 'Choose how you want to pay with Paynamics.');

        $this->paynamics->update(['payment_channels' => ['maya']]);
        $this->checkoutCart()
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_channel.0', 'This payment option is not available right now. Please choose another one.');
    }

    #[Test]
    public function the_whole_cart_is_paid_with_one_paynamics_payment(): void
    {
        $this->fakePaynamics();
        $this->fillCart();

        $response = $this->checkoutCart()
            ->assertCreated()
            ->assertJsonPath('payment.provider', 'paynamics')
            ->assertJsonPath('redirect_url', self::PAYNAMICS_LINK)
            ->assertJsonCount(2, 'orders');

        $orders = Transaction::query()->orderBy('created_at')->get();
        $this->assertCount(2, $orders);
        $lead = Transaction::findOrFail($response->json('transaction.uuid'));
        $this->assertSame(
            [$this->arcade->uuid, $this->waterPark->uuid],
            collect($response->json('orders'))->pluck('transaction.event_uuid')->all(),
        );
        $this->assertSame($this->cart()->items->pluck('uuid')->all(), collect($response->json('orders'))->pluck('cart_item_uuid')->all());

        foreach ($orders as $order) {
            $this->assertSame($lead->payment_group_uuid, $order->payment_group_uuid);
            $this->assertSame($lead->payment_order_id, $order->payment_id);
            $this->assertSame($this->paynamics->uuid, $order->payment_gateway_uuid);
            $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $order->payment_status);
        }

        $amount = number_format((float) $orders->sum('total_amount'), 2, '.', '');
        Http::assertSent(fn (Request $request) => $request->url() === self::PAYNAMICS_CREATE
            && $request['transaction']['amount'] === $amount
            && $request['transaction']['request_id'] === $lead->payment_order_id
            && str_ends_with($request['transaction']['response_url'], '/payment/success/' . $lead->uuid)
            && $request['order_details']['orders'][0]['itemname'] === 'Ticket purchase for Neon Arcade, Splash Water Park'
            && $request['customer_info']['fname'] === 'Carla');
        Http::assertSentCount(1);

        $this->assertSame(2, CartItem::count(), 'The cart is kept until the payment goes through.');
    }

    #[Test]
    public function paying_the_cart_confirms_every_order_and_empties_the_cart(): void
    {
        $this->fakePaynamics();
        $this->fillCart();
        $lead = $this->checkoutCart()->assertCreated()->json('transaction.uuid');
        $this->fakePaynamics('GR001');

        $orders = $this->actingAs($this->customer, 'api')->postJson("/api/v1/customer/transactions/{$lead}/complete")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'orders')
            ->json('orders');
        $ticketsPerActivity = collect($orders)->mapWithKeys(fn (array $order) => [$order['transaction']['event_uuid'] => count($order['tickets'])]);
        $this->assertSame(2, $ticketsPerActivity[$this->arcade->uuid]);
        $this->assertSame(1, $ticketsPerActivity[$this->waterPark->uuid]);

        $this->assertSame(2, Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])->count());
        $this->assertSame(3, Ticket::count());
        $this->assertSame(0, CartItem::count());
        Notification::assertSentToTimes($this->customer, PaymentSuccessfulNotification::class, 2);

        $this->actingAs($this->customer, 'api')->postJson("/api/v1/customer/transactions/{$lead}/complete")
            ->assertJsonPath('message', 'Payment already completed')
            ->assertJsonCount(2, 'orders');
        $this->assertSame(3, Ticket::count());
    }

    #[Test]
    public function the_paynamics_notification_for_the_cart_payment_confirms_every_order(): void
    {
        $this->fakePaynamics();
        $this->fillCart();
        $this->checkoutCart()->assertCreated();
        $this->fakePaynamics('GR001');
        $sibling = Transaction::query()->where('event_uuid', $this->waterPark->uuid)->firstOrFail();

        $this->postJson('/api/v1/payments/paynamics/notification', ['request_id' => $sibling->payment_id])
            ->assertOk()
            ->assertJsonPath('payment_status', 'paid');

        $this->assertSame(2, Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])->count());

        $this->artisan('app:auto-verify-pending-transaction')->assertSuccessful();
        $this->assertSame(3, Ticket::count());
    }

    #[Test]
    public function a_failed_cart_payment_cancels_every_order_and_keeps_the_cart(): void
    {
        $this->fakePaynamics();
        $this->fillCart();
        $lead = $this->checkoutCart()->assertCreated()->json('transaction.uuid');
        $this->fakePaynamics('GR003');

        $this->actingAs($this->customer, 'api')->postJson("/api/v1/customer/transactions/{$lead}/complete")
            ->assertJsonPath('message', 'Payment failed');

        $this->assertSame(2, Transaction::where('payment_status', Transaction::PAYMENT_STATUS['FAILED'])->count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(2, CartItem::count());
        Notification::assertSentToTimes($this->customer, PaymentCancelledNotification::class, 2);
    }

    #[Test]
    public function cancelling_the_cart_payment_cancels_every_order(): void
    {
        $this->fakePaynamics();
        $this->fillCart();
        $lead = $this->checkoutCart()->assertCreated()->json('transaction.uuid');

        $this->actingAs($this->customer, 'api')->postJson("/api/v1/customer/transactions/{$lead}/cancel")
            ->assertOk()
            ->assertJsonPath('transaction.uuid', $lead)
            ->assertJsonPath('transaction.payment_status', Transaction::PAYMENT_STATUS['CANCELLED']);

        $this->assertSame(2, Transaction::where('payment_status', Transaction::PAYMENT_STATUS['CANCELLED'])->count());
        $this->assertSame(0, Ticket::count());
        Notification::assertSentToTimes($this->customer, PaymentCancelledNotification::class, 2);
    }

    #[Test]
    public function a_payment_option_paynamics_turns_down_is_reported_and_nothing_is_kept(): void
    {
        $this->fillCart();
        Http::fake([self::PAYNAMICS_CREATE => Http::response(['response_code' => 'GR150', 'response_message' => 'Channel unavailable'])]);

        $this->checkoutCart()
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_channel.0', 'This payment option is not available right now. Please choose another one.');
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function a_gateway_error_rolls_the_cart_checkout_back(): void
    {
        $this->fillCart();
        Http::fake([self::PAYNAMICS_CREATE => Http::response(['response_code' => 'GR999', 'response_message' => 'Merchant disabled'])]);

        $this->checkoutCart()
            ->assertStatus(500)
            ->assertJsonPath('message', 'Checkout failed. Please try again.')
            ->assertJsonPath('error', 'Payment creation failed: Paynamics: Merchant disabled');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(2, CartItem::count());
    }

    #[Test]
    public function the_cart_can_be_paid_with_paymongo(): void
    {
        $paymongo = $this->createGateway('paymongo');
        $this->arcade->paymentGateways()->sync([$paymongo->uuid]);
        $this->addToCart($this->arcadePass);
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_cart',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_cart',
                        'status' => 'active',
                        'line_items' => [['amount' => 50000, 'currency' => 'PHP']],
                    ],
                ],
            ]),
        ]);

        $this->checkoutCart(['payment_gateway_uuid' => $paymongo->uuid, 'payment_channel' => null])
            ->assertCreated()
            ->assertJsonPath('redirect_url', 'https://checkout.paymongo.com/cs_cart');

        Http::assertSent(fn (Request $request) => $request['data']['attributes']['statement_descriptor'] === 'TICKET'
            && $request['data']['attributes']['payment_method_types'] !== []);
        $this->assertSame('cs_cart', Transaction::firstOrFail()->payment_id);
    }

    #[Test]
    public function the_cart_can_be_paid_with_paypal(): void
    {
        $paypal = $this->createGateway('paypal');
        $this->arcade->paymentGateways()->sync([$paypal->uuid]);
        $this->addToCart($this->arcadePass);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER-CART',
                'status' => 'CREATED',
                'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-CART']],
            ], 201),
        ]);

        $this->checkoutCart(['payment_gateway_uuid' => $paypal->uuid, 'payment_channel' => null])
            ->assertCreated()
            ->assertJsonPath('payment.provider', 'paypal')
            ->assertJsonPath('redirect_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-CART');
    }

    #[Test]
    public function a_free_cart_is_confirmed_straight_away(): void
    {
        $this->arcadePass->update(['price' => 0]);
        $this->waterParkPass->update(['price' => 0]);
        $this->fillCart();

        $this->checkoutCart(['payment_gateway_uuid' => null, 'payment_channel' => null])
            ->assertCreated()
            ->assertJsonPath('payment', ['provider' => 'free', 'status' => 'paid'])
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.transaction.event_uuid', $this->arcade->uuid)
            ->assertJsonCount(2, 'orders.0.tickets');

        $this->assertSame(2, Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])->count());
        $this->assertSame(0, CartItem::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_reminder_is_sent_once_an_hour_after_the_last_cart_change(): void
    {
        $this->addToCart($this->arcadePass, 2);
        $waiting = $this->cart();
        $waiting->update(['last_changed_at' => now()->subMinutes(61)]);

        $fresh = Cart::forUser($this->createCustomer());
        $fresh->items()->create(['event_uuid' => $this->arcade->uuid, 'visit_date' => now()->addDay(), 'tickets' => []]);
        $fresh->touchChanged();

        $empty = Cart::forUser($this->createCustomer());
        $empty->update(['last_changed_at' => now()->subHours(2)]);

        $this->artisan('app:send-cart-reminders')
            ->expectsOutput('Sent 1 cart reminder(s).')
            ->assertSuccessful();

        Notification::assertSentTo($this->customer, CartReminderNotification::class, fn ($notification) => $notification->cartUuid === $waiting->uuid);
        Notification::assertCount(1);
        $this->assertNotNull($waiting->fresh()->reminder_sent_at);

        $this->artisan('app:send-cart-reminders')->expectsOutput('Sent 0 cart reminder(s).');
        Notification::assertCount(1);
    }

    #[Test]
    public function the_reminder_email_lists_what_is_waiting_in_the_cart(): void
    {
        $this->addToCart($this->arcadePass, 2);
        $this->addToCart($this->waterParkPass, 1, ['details' => null, 'visit_date' => '2030-03-09']);
        $notification = new CartReminderNotification($this->cart()->uuid);

        $mail = $notification->toMail($this->customer);

        $this->assertSame('Your PAEC cart is waiting for payment', $mail->subject);
        $this->assertSame('emails.paec.cart-reminder', $mail->view);
        $data = $mail->viewData['data'];
        $this->assertSame('Carla', $data['customer_name']);
        $this->assertSame('2 activities', $data['activity_count_label']);
        $this->assertSame(config('app.frontend_url') . '/checkout', $data['checkout_url']);
        $this->assertSame(['name' => 'Neon Arcade', 'location' => 'Pasay City', 'visit_date' => now()->addDays(5)->format('D, F j, Y'), 'tickets' => '2 × Arcade Pass'], $data['items'][0]);
        $this->assertSame(['name' => 'Splash Water Park', 'location' => null, 'visit_date' => 'Sat, March 9, 2030', 'tickets' => '1 × Water Park Day'], $data['items'][1]);
        $this->assertSame(['cart_uuid' => $this->cart()->uuid], $notification->toArray($this->customer));

        $html = view('emails.paec.cart-reminder', ['data' => $data])->render();
        $this->assertStringContainsString('Complete my booking', $html);
        $this->assertStringContainsString('You received this email because you added tickets to your PAEC cart.', $html);
        $this->assertStringNotContainsString('include your order number', $html);

        CartItem::query()->delete();
        $this->addToCart($this->arcadePass);
        $this->assertSame('1 activity', $notification->toMail($this->customer)->viewData['data']['activity_count_label']);
    }
}
