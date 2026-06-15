<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Http\Repositories\AnalyticsRepository;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAdminWithAnalyticsPermissions;
use Tests\TestCase;

class AnalyticsSeriesApiTest extends TestCase
{
    use CreatesAdminWithAnalyticsPermissions;
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private Event $event;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAnalyticsAdmin();

        config(['app.timezone' => GeneralConstants::TIMEZONE]);
        Carbon::setTestNow(Carbon::parse('2026-05-20 15:30:00', GeneralConstants::TIMEZONE));

        $this->organization = Organization::create([
            'name' => 'Analytics Org',
            'representative_first_name' => 'A',
            'representative_last_name' => 'B',
            'email' => 'analytics-org@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);

        $this->otherOrganization = Organization::create([
            'name' => 'Other Analytics Org',
            'representative_first_name' => 'C',
            'representative_last_name' => 'D',
            'email' => 'other-analytics@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->customer = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'analytics-customer@test.com',
            'password' => 'password123',
            'first_name' => 'Chart',
            'last_name' => 'Buyer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->event = $this->createEvent($this->organization, 'Chart Event');
        $otherEvent = $this->createEvent($this->otherOrganization, 'Other Event');

        $this->createPaidTransaction(
            $this->event,
            100.0,
            Carbon::parse('2026-05-19 10:00:00', GeneralConstants::TIMEZONE),
        );
        $this->createPaidTransaction(
            $this->event,
            250.0,
            Carbon::parse('2026-05-19 14:00:00', GeneralConstants::TIMEZONE),
        );
        $this->createPaidTransaction(
            $otherEvent,
            999.0,
            Carbon::parse('2026-05-19 11:00:00', GeneralConstants::TIMEZONE),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_rejects_invalid_granularity_on_transaction_revenue_series(): void
    {
        $this->analyticsGet('/api/v1/analytics/transaction-revenue-series', [
            'granularity' => 'quarterly',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['granularity']);
    }

    #[Test]
    public function it_requires_end_date_when_start_date_is_provided(): void
    {
        $this->analyticsGet('/api/v1/analytics/transaction-revenue-series', [
            'granularity' => 'daily',
            'start_date' => '2026-05-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    #[Test]
    public function it_returns_hourly_transaction_revenue_for_a_custom_date_range(): void
    {
        $response = $this->analyticsGet('/api/v1/analytics/transaction-revenue-series', [
            'granularity' => 'hourly',
            'start_date' => '2026-05-19',
            'end_date' => '2026-05-19',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.granularity', 'hourly')
            ->assertJsonPath('data.start_date', '2026-05-19 00:00')
            ->assertJsonPath('data.end_date', '2026-05-19 23:00');

        $series = $response->json('data.series');
        $this->assertIsArray($series);
        $this->assertCount(24, $series);

        $amountsByHour = $this->seriesAmountsByHour($series);
        $this->assertSame(100.0, $amountsByHour['2026-05-19 10:00'] ?? 0.0);
        $this->assertSame(250.0, $amountsByHour['2026-05-19 14:00'] ?? 0.0);
        $this->assertSame(0.0, $amountsByHour['2026-05-19 09:00'] ?? 0.0);
    }

    #[Test]
    public function it_defaults_hourly_transaction_revenue_to_the_last_twenty_four_hours(): void
    {
        $this->createPaidTransaction(
            $this->event,
            50.0,
            Carbon::parse('2026-05-20 14:00:00', GeneralConstants::TIMEZONE),
        );

        $response = $this->analyticsGet('/api/v1/analytics/transaction-revenue-series', [
            'granularity' => 'hourly',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.granularity', 'hourly')
            ->assertJsonPath('data.start_date', '2026-05-19 16:00')
            ->assertJsonPath('data.end_date', '2026-05-20 15:00');

        $series = $response->json('data.series');
        $this->assertCount(24, $series);

        $amountsByHour = $this->seriesAmountsByHour($series);
        $this->assertSame(50.0, $amountsByHour['2026-05-20 14:00'] ?? 0.0);
        $this->assertArrayNotHasKey('2026-05-19 10:00', $amountsByHour);
    }

    #[Test]
    public function it_filters_transaction_revenue_series_by_organization(): void
    {
        $response = $this->analyticsGet('/api/v1/analytics/transaction-revenue-series', [
            'granularity' => 'hourly',
            'start_date' => '2026-05-19',
            'end_date' => '2026-05-19',
            'organization_uuid' => $this->organization->uuid,
        ]);

        $response->assertStatus(200);

        $amountsByHour = $this->seriesAmountsByHour($response->json('data.series'));
        $this->assertSame(100.0, $amountsByHour['2026-05-19 10:00'] ?? 0.0);
        $this->assertSame(250.0, $amountsByHour['2026-05-19 14:00'] ?? 0.0);
        $this->assertSame(0.0, $amountsByHour['2026-05-19 11:00'] ?? 0.0);
    }

    #[Test]
    public function it_returns_hourly_successful_failed_counts_for_a_custom_range(): void
    {
        $this->createFailedTransaction(
            $this->event,
            Carbon::parse('2026-05-19 16:00:00', GeneralConstants::TIMEZONE),
        );

        $response = $this->analyticsGet('/api/v1/analytics/successful-failed-transaction-counts', [
            'granularity' => 'hourly',
            'start_date' => '2026-05-19',
            'end_date' => '2026-05-19',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.granularity', 'hourly');

        $countsByHour = collect($response->json('data.series'))->mapWithKeys(
            fn (array $row) => [$row['date'] => $row],
        )->all();
        $this->assertSame(1, $countsByHour['2026-05-19 10:00']['successful_count'] ?? 0);
        $this->assertSame(1, $countsByHour['2026-05-19 16:00']['failed_count'] ?? 0);
    }

    #[Test]
    public function it_returns_hourly_user_signups_for_a_custom_range(): void
    {
        $this->customer->forceFill([
            'created_at' => Carbon::parse('2026-05-18 09:00:00', GeneralConstants::TIMEZONE),
        ])->save();

        $signupAt = Carbon::parse('2026-05-18 15:00:00', GeneralConstants::TIMEZONE);
        $signupUser = new User([
            'role_uuid' => $this->customer->role_uuid,
            'email' => 'signup-hourly@test.com',
            'password' => 'password123',
            'first_name' => 'New',
            'last_name' => 'Signup',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $signupUser->created_at = $signupAt;
        $signupUser->updated_at = $signupAt;
        $signupUser->save();

        $response = $this->analyticsGet('/api/v1/analytics/user-signups-series', [
            'granularity' => 'hourly',
            'start_date' => '2026-05-18',
            'end_date' => '2026-05-18',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.granularity', 'hourly');

        $countsByHour = collect($response->json('data.series'))->mapWithKeys(
            fn (array $row) => [$row['date'] => $row['count']],
        )->all();
        $this->assertSame(1, $countsByHour['2026-05-18 15:00'] ?? 0);
        $this->assertSame(0, $countsByHour['2026-05-18 10:00'] ?? 0);
    }

    #[Test]
    public function it_requires_analytics_view_permission(): void
    {
        $role = Role::create([
            'name' => 'No Analytics',
            'code' => 'no-analytics',
            'is_admin' => true,
        ]);

        $deniedAdmin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'no-analytics@test.com',
            'password' => 'password123',
            'first_name' => 'No',
            'last_name' => 'Access',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $token = auth('admin')->login($deniedAdmin) ?? '';

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/analytics/transaction-revenue-series?granularity=hourly')
            ->assertStatus(403);
    }

    #[Test]
    public function it_exports_transaction_revenue_series_csv_with_filters(): void
    {
        $response = $this->analyticsGet('/api/v1/analytics/transaction-revenue-series/export', [
            'granularity' => 'hourly',
            'start_date' => '2026-05-19',
            'end_date' => '2026-05-19',
            'organization_uuid' => $this->organization->uuid,
        ]);

        $response->assertOk()
            ->assertHeader('content-disposition');

        $content = $response->getContent();
        $this->assertStringContainsString('Order Number', $content);
        $this->assertStringContainsString('Tax and Fees', $content);
        $this->assertStringContainsString('Gross Revenue', $content);
        $this->assertStringContainsString('Platform Fee', $content);
        $this->assertStringContainsString('Total Payout', $content);
        $this->assertStringNotContainsString('999.00', $content);
    }

    #[Test]
    public function organizer_transaction_export_omits_admin_only_columns(): void
    {
        $repository = app(AnalyticsRepository::class);

        $csv = $repository->exportTransactionRevenueSeriesCsv(
            'hourly',
            $this->organization->uuid,
            '2026-05-19',
            '2026-05-19',
            includeAdminOnlyColumns: false,
        );

        $this->assertStringNotContainsString('Tax and Fees', $csv);
        $this->assertStringNotContainsString('Gross Revenue', $csv);
        $this->assertStringNotContainsString('Markup', $csv);
        $this->assertStringContainsString('Net Selling Price', $csv);
        $this->assertStringContainsString('Affiliate Commissions', $csv);
        $this->assertStringContainsString('Platform Fee', $csv);
        $this->assertStringContainsString('Total Payout', $csv);
    }

    #[Test]
    public function merchant_revenue_series_excludes_tax_discount_and_promo_from_gross(): void
    {
        $this->createPaidTransaction(
            $this->event,
            1120.0,
            Carbon::parse('2026-05-18 12:00:00', GeneralConstants::TIMEZONE),
            subTotal: 1000.0,
            taxAmount: 120.0,
            discount: 50.0,
            promoDiscount: 25.0,
        );

        $repository = app(AnalyticsRepository::class);

        $gross = $repository->getTransactionRevenueSeries(
            'hourly',
            $this->organization->uuid,
            '2026-05-18',
            '2026-05-18',
            merchantRevenueOnly: false,
        );

        $merchant = $repository->getTransactionRevenueSeries(
            'hourly',
            $this->organization->uuid,
            '2026-05-18',
            '2026-05-18',
            merchantRevenueOnly: true,
        );

        $grossByHour = $this->seriesAmountsByHour($gross['series']);
        $merchantByHour = $this->seriesAmountsByHour($merchant['series']);

        $this->assertSame(1120.0, $grossByHour['2026-05-18 12:00'] ?? 0.0);
        $this->assertSame(925.0, $merchantByHour['2026-05-18 12:00'] ?? 0.0);
    }

    #[Test]
    public function repository_hourly_series_fills_missing_hours_with_zero(): void
    {
        $repository = app(AnalyticsRepository::class);

        $data = $repository->getTransactionRevenueSeries(
            'hourly',
            null,
            '2026-05-19',
            '2026-05-19',
        );

        $this->assertSame('hourly', $data['granularity']);
        $this->assertCount(24, $data['series']);
        $this->assertSame('2026-05-19 12:00', $data['series'][12]['date']);
        $this->assertSame(0.0, $data['series'][0]['total_amount']);
    }

    /**
     * @param  array<int, array{date: string, total_amount: float}>  $series
     * @return array<string, float>
     */
    private function seriesAmountsByHour(array $series): array
    {
        $amounts = [];
        foreach ($series as $row) {
            $amounts[$row['date']] = (float) $row['total_amount'];
        }

        return $amounts;
    }

    private function createEvent(Organization $organization, string $name): Event
    {
        $event = Event::create([
            'event_name' => $name,
            'event_description' => 'Test',
            'contact_email' => strtolower(str_replace(' ', '', $name)) . '@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $organization->uuid,
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);

        ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        return $event;
    }

    private function createPaidTransaction(
        Event $event,
        float $amount,
        Carbon $createdAt,
        float $subTotal = 0,
        float $taxAmount = 0,
        float $discount = 0,
        float $promoDiscount = 0,
    ): Transaction {
        $schedule = Schedule::query()->where('event_uuid', $event->uuid)->firstOrFail();
        $scheduleTime = ScheduleTime::query()->where('schedule_uuid', $schedule->uuid)->firstOrFail();

        $resolvedSubTotal = $subTotal > 0 ? $subTotal : $amount;

        $transaction = new Transaction([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'organization_uuid' => $event->organization_uuid,
            'order_number' => 'ORD-' . uniqid('', true),
            'total_amount' => $amount,
            'sub_total' => $resolvedSubTotal,
            'tax_amount' => $taxAmount,
            'discount' => $discount,
            'promo_code_discount' => $promoDiscount,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => $createdAt,
        ]);

        $transaction->created_at = $createdAt;
        $transaction->updated_at = $createdAt;
        $transaction->save();

        return $transaction;
    }

    private function createFailedTransaction(Event $event, Carbon $createdAt): Transaction
    {
        $schedule = Schedule::query()->where('event_uuid', $event->uuid)->firstOrFail();
        $scheduleTime = ScheduleTime::query()->where('schedule_uuid', $schedule->uuid)->firstOrFail();

        $transaction = new Transaction([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'organization_uuid' => $event->organization_uuid,
            'order_number' => 'ORD-FAIL-' . uniqid('', true),
            'total_amount' => 75.0,
            'sub_total' => 75.0,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['FAILED'],
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
        ]);

        $transaction->created_at = $createdAt;
        $transaction->updated_at = $createdAt;
        $transaction->save();

        return $transaction;
    }
}
