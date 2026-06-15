<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\TempTransaction;
use App\Models\TempTransactionOrder;
use App\Models\User;
use App\Support\OrganizationPaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckoutOrganizationPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Role $customerRole;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->user = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);

        $this->ensureCheckoutSchemaForSqlite();
    }

    /**
     * SQLite migrations in CI may lag MySQL; mirror guards from PaymentServiceTest.
     */
    private function ensureCheckoutSchemaForSqlite(): void
    {
        if (! Schema::hasColumn('tickets', 'visit_policy')) {
            Schema::table('tickets', function ($table) {
                $table->string('visit_policy')->nullable();
            });
        }
        if (! Schema::hasColumn('tickets', 'is_bundle')) {
            Schema::table('tickets', function ($table) {
                $table->boolean('is_bundle')->default(false);
            });
        }
        if (! Schema::hasColumn('tickets', 'bundle_quantity')) {
            Schema::table('tickets', function ($table) {
                $table->integer('bundle_quantity')->nullable();
            });
        }
        if (! Schema::hasColumn('transaction_orders', 'valid_until')) {
            Schema::table('transaction_orders', function ($table) {
                $table->dateTime('valid_until')->nullable();
            });
        }
    }

    /**
     * @param array<int, array<string, mixed>>|null $organizationPaymentMethods null = omit column (legacy DB null)
     * @return array{organization: Organization, event: Event, tempTransaction: TempTransaction}
     */
    private function createPaidCheckoutContext(?array $organizationPaymentMethods): array
    {
        $category = Category::create([
            'name' => 'Conference',
            'code' => 'conference',
            'type' => Category::TYPES['EVENT'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $section = EventSection::create([
            'name' => EventSection::FEATURED_SECTION,
            'title' => 'Featured',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $orgPayload = [
            'name' => 'Checkout Test Org',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'checkout-org@test.com',
            'bank_name' => 'Test Bank',
            'bank_branch' => 'Main',
            'bank_address' => 'Bank Address',
            'bank_account_name' => 'Jane Doe',
            'bank_account_number' => '1234567890',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ];
        if ($organizationPaymentMethods !== null) {
            $orgPayload['payment_methods'] = $organizationPaymentMethods;
        }

        $organization = Organization::create($orgPayload);

        $event = Event::create([
            'organization_uuid' => $organization->uuid,
            'category_uuid' => $category->uuid,
            'event_section_uuid' => $section->uuid,
            'event_name' => 'Org Payment Methods Event',
            'event_description' => 'Test',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'code' => 'ORG-PM-TKT',
            'name' => 'General',
            'price' => 500.00,
            'is_bundle' => false,
            'display_order' => 1,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => true,
        ]);

        $tempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'organization_uuid' => $organization->uuid,
            'total_amount' => 500.00,
            'sub_total' => 500.00,
            'tax_amount' => 0.00,
        ]);

        TempTransactionOrder::create([
            'user_uuid' => $this->user->uuid,
            'temp_transaction_uuid' => $tempTransaction->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'quantity' => 1,
            'price' => 500.00,
            'discount' => 0.00,
            'total_amount' => 500.00,
        ]);

        return compact('organization', 'event', 'tempTransaction');
    }

    #[Test]
    public function paymongoCheckoutSendsOnlyOrganizerEnabledPaymentMethodTypes(): void
    {
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_org_pm_1',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_org_pm_1',
                        'status' => 'active',
                        'line_items' => [
                            ['amount' => 50000, 'currency' => 'PHP'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $ctx = $this->createPaidCheckoutContext([
            ['name' => 'gcash', 'value' => true, 'provider' => 'paymongo'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $ctx['tempTransaction']->uuid,
                'payment_provider' => 'paymongo',
            ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if (! str_contains($request->url(), 'api.paymongo.com/v1/checkout_sessions')) {
                return false;
            }
            $body = json_decode($request->body(), true);
            $types = $body['data']['attributes']['payment_method_types'] ?? null;

            return is_array($types) && $types === ['gcash'];
        });
    }

    #[Test]
    public function paymongoCheckoutIntersectsClientPaymentMethodsWithOrganizerEnabledList(): void
    {
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_org_pm_2',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_org_pm_2',
                        'status' => 'active',
                        'line_items' => [
                            ['amount' => 50000, 'currency' => 'PHP'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $ctx = $this->createPaidCheckoutContext([
            ['name' => 'gcash', 'value' => true, 'provider' => 'paymongo'],
            ['name' => 'card', 'value' => true, 'provider' => 'paymongo'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $ctx['tempTransaction']->uuid,
                'payment_provider' => 'paymongo',
                'payment_methods' => ['card', 'paymaya'],
            ]);

        $response->assertStatus(201);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if (! str_contains($request->url(), 'api.paymongo.com/v1/checkout_sessions')) {
                return false;
            }
            $body = json_decode($request->body(), true);
            $types = $body['data']['attributes']['payment_method_types'] ?? null;

            return is_array($types) && $types === ['card'];
        });
    }

    #[Test]
    public function paymongoCheckoutReturns422WhenOrganizerHasNoEnabledPaymongoMethods(): void
    {
        Http::fake();

        $ctx = $this->createPaidCheckoutContext([
            ['name' => 'paypal', 'value' => true, 'provider' => 'paypal'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $ctx['tempTransaction']->uuid,
                'payment_provider' => 'paymongo',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_provider']);

        Http::assertNothingSent();
    }

    #[Test]
    public function paypalCheckoutReturns422WhenOrganizerDisablesPaypal(): void
    {
        Http::fake();

        $ctx = $this->createPaidCheckoutContext([
            ['name' => 'gcash', 'value' => true, 'provider' => 'paymongo'],
            ['name' => 'paypal', 'value' => false, 'provider' => 'paypal'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $ctx['tempTransaction']->uuid,
                'payment_provider' => 'paypal',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_provider']);

        Http::assertNothingSent();
    }

    #[Test]
    public function paypalCardCheckoutReturns422WhenOrganizerDisablesPaypal(): void
    {
        Http::fake();

        $ctx = $this->createPaidCheckoutContext([
            ['name' => 'gcash', 'value' => true, 'provider' => 'paymongo'],
            ['name' => 'paypal', 'value' => false, 'provider' => 'paypal'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout-paypal-card', [
                'temp_transaction_uuid' => $ctx['tempTransaction']->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['temp_transaction_uuid']);

        Http::assertNothingSent();
    }

    #[Test]
    public function paymongoCheckoutReturns422WhenRequestedMethodsAreNotEnabledForOrganizer(): void
    {
        Http::fake();

        $ctx = $this->createPaidCheckoutContext([
            ['name' => 'gcash', 'value' => true, 'provider' => 'paymongo'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer/temp-transactions/checkout', [
                'temp_transaction_uuid' => $ctx['tempTransaction']->uuid,
                'payment_provider' => 'paymongo',
                'payment_methods' => ['paymaya'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_methods']);

        Http::assertNothingSent();
    }

    #[Test]
    public function publicEventPayloadIncludesNormalizedOrganizerPaymentMethods(): void
    {
        $category = Category::create([
            'name' => 'Music',
            'code' => 'music',
            'type' => Category::TYPES['EVENT'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $section = EventSection::create([
            'name' => EventSection::UPCOMING_SECTION,
            'title' => 'Upcoming',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $organization = Organization::create([
            'name' => 'Public Event Org',
            'representative_first_name' => 'A',
            'representative_last_name' => 'B',
            'address' => 'Addr',
            'contact_number' => '09170000000',
            'email' => 'public-org@test.com',
            'bank_name' => 'Bank',
            'bank_branch' => 'Main',
            'bank_address' => 'BA',
            'bank_account_name' => 'AB',
            'bank_account_number' => '000',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'payment_methods' => [
                ['name' => 'card', 'value' => true, 'provider' => 'paymongo'],
            ],
        ]);

        $event = Event::create([
            'organization_uuid' => $organization->uuid,
            'category_uuid' => $category->uuid,
            'event_section_uuid' => $section->uuid,
            'event_name' => 'Public Payment Methods Event',
            'event_description' => 'Desc',
            'contact_email' => 'pub@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'slug' => 'public-pm-event',
        ]);

        $response = $this->getJson('/api/v1/public/events/' . $event->uuid);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'payment_methods' => [
                        '*' => ['name', 'value', 'provider'],
                    ],
                ],
            ]);

        $normalized = OrganizationPaymentMethods::normalize($organization->fresh()->payment_methods);
        $this->assertEquals($normalized, $response->json('data.payment_methods'));

        $card = collect($response->json('data.payment_methods'))->firstWhere('name', 'card');
        $this->assertNotNull($card);
        $this->assertTrue($card['value']);
        $this->assertSame('paymongo', $card['provider']);
    }
}
