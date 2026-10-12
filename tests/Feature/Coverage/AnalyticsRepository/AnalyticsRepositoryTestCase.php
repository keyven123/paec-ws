<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use App\Constants\GeneralConstants;
use App\Http\Repositories\AnalyticsRepository;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Shared fixtures for AnalyticsRepository tests. "Now" is frozen at Wed 2026-05-20 15:30 (Asia/Manila).
 *
 * The default connection is swapped for MySqlDialectSqliteConnection so MySQL-only bucket SQL runs on SQLite.
 */
abstract class AnalyticsRepositoryTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Organization $otherOrganization;

    protected User $customer;

    protected function setUp(): void
    {
        Connection::resolverFor('sqlite', fn ($pdo, $database, $prefix, $config) => new MySqlDialectSqliteConnection($pdo, $database, $prefix, $config));

        parent::setUp();

        MySqlDialectSqliteConnection::registerMySqlFunctions(DB::connection()->getPdo());

        Carbon::setTestNow(Carbon::parse('2026-05-20 15:30:00', GeneralConstants::TIMEZONE));

        $this->organization = $this->createOrganization('Analytics Org');
        $this->otherOrganization = $this->createOrganization('Other Analytics Org');

        $this->customer = $this->createCustomer('analytics-customer@test.com');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();

        $property = new ReflectionProperty(Connection::class, 'resolvers');
        $resolvers = $property->getValue();
        unset($resolvers['sqlite']);
        $property->setValue(null, $resolvers);
    }

    protected function repository(): AnalyticsRepository
    {
        return app(AnalyticsRepository::class);
    }

    protected function dialectConnection(): MySqlDialectSqliteConnection
    {
        $connection = DB::connection();
        $this->assertInstanceOf(MySqlDialectSqliteConnection::class, $connection);

        return $connection;
    }

    protected function createOrganization(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'representative_first_name' => 'Rep',
            'representative_last_name' => 'Resentative',
            'email' => Str::slug($name).'@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);
    }

    protected function createCustomer(string $email, ?Carbon $createdAt = null, array $attributes = []): User
    {
        $role = Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer'],
        );

        $user = new User(array_merge([
            'role_uuid' => $role->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => 'Chart',
            'last_name' => 'Buyer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));

        if ($createdAt !== null) {
            $user->created_at = $createdAt;
            $user->updated_at = $createdAt;
        }

        $user->save();

        return $user;
    }

    protected function createEvent(Organization $organization, string $name, array $attributes = [], bool $withSchedule = true, bool $withScheduleTime = true): Event
    {
        $event = Event::create(array_merge([
            'event_name' => $name,
            'event_description' => 'Test',
            'contact_email' => Str::slug($name).'@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $organization->uuid,
        ], $attributes));

        if ($withSchedule) {
            $schedule = Schedule::create([
                'event_uuid' => $event->uuid,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-02',
                'status' => 'published',
            ]);

            if ($withScheduleTime) {
                ScheduleTime::create([
                    'schedule_uuid' => $schedule->uuid,
                    'time_start' => '10:00:00',
                    'time_end' => '12:00:00',
                    'status' => 'published',
                ]);
            }
        }

        return $event;
    }

    protected function createTransaction(?Event $event, float $amount, string $createdAt, array $attributes = []): Transaction
    {
        $schedule = $event ? Schedule::query()->where('event_uuid', $event->uuid)->first() : null;
        $scheduleTime = $schedule ? ScheduleTime::query()->where('schedule_uuid', $schedule->uuid)->first() : null;
        $at = Carbon::parse($createdAt, GeneralConstants::TIMEZONE);

        $transaction = new Transaction(array_merge([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $event?->uuid,
            'schedule_uuid' => $schedule?->uuid,
            'schedule_time_uuid' => $scheduleTime?->uuid,
            'organization_uuid' => $event?->organization_uuid ?? $this->organization->uuid,
            'order_number' => 'ORD-'.Str::upper(Str::random(10)),
            'total_amount' => $amount,
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'promo_code_discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => $at,
        ], $attributes));

        $transaction->created_at = $at;
        $transaction->updated_at = $at;
        $transaction->save();

        return $transaction;
    }

    /**
     * Paid event revenue: org A "Chart Event" 500 (2025-07-01, sub_total 450), 300 (2026-03-15), 400 (2026-05-11),
     * 100 (2026-05-19 10:00), 250 (2026-05-19 14:00); org B "Other Event" 999 (2026-05-19 11:00).
     * Failed (2026-05-19 16:00) and payment-cancelled (2026-05-12 08:00) on Chart Event; a pending one and a paid
     * non-event transaction that every query must ignore.
     *
     * @return array{chart: Event, other: Event}
     */
    protected function seedSeriesFixtures(): array
    {
        $chart = $this->createEvent($this->organization, 'Chart Event');
        $other = $this->createEvent($this->otherOrganization, 'Other Event');

        $this->createTransaction($chart, 500, '2025-07-01 12:00:00', ['sub_total' => 450]);
        $this->createTransaction($chart, 300, '2026-03-15 12:00:00');
        $this->createTransaction($chart, 400, '2026-05-11 09:00:00');
        $this->createTransaction($chart, 100, '2026-05-19 10:00:00');
        $this->createTransaction($chart, 250, '2026-05-19 14:00:00');
        $this->createTransaction($other, 999, '2026-05-19 11:00:00');

        $this->createTransaction($chart, 75, '2026-05-19 16:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['FAILED'],
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
            'paid_at' => null,
        ]);
        $this->createTransaction($chart, 60, '2026-05-12 08:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
            'paid_at' => null,
        ]);
        $this->createTransaction($chart, 80, '2026-05-19 12:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
            'paid_at' => null,
        ]);
        $this->createTransaction(null, 700, '2026-05-19 09:00:00');

        return ['chart' => $chart, 'other' => $other];
    }

    /**
     * @param  array<int, array<string, mixed>>  $series
     * @return array<string, mixed>
     */
    protected function keyByDate(array $series, string $field): array
    {
        $byDate = [];
        foreach ($series as $row) {
            $byDate[$row['date']] = $row[$field];
        }

        return $byDate;
    }
}
