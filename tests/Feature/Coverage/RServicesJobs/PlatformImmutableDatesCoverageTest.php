<?php

namespace Tests\Feature\Coverage\RServicesJobs;

use App\Constants\GeneralConstants;
use App\Models\Dataset;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Schedule;
use App\Models\Transaction;
use App\Services\Platform\AdminOperatorConsoleService;
use App\Services\Platform\AdminTransactionPnLService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Apps may switch Eloquent date casts to CarbonImmutable via Date::use(); the platform
 * reports must then re-parse cast values that are not mutable Carbon instances.
 */
class PlatformImmutableDatesCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT', static function ($value, $format) {
            if ($value === null) {
                return null;
            }

            return date(strtr((string) $format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's']), strtotime((string) $value));
        }, 2);

        Dataset::create(['name' => 'merchant_commission_percentage', 'value' => '10']);
        Date::use(CarbonImmutable::class);
    }

    protected function tearDown(): void
    {
        Date::useDefault();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeEvent(?EventSection $section, array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Immutable '.Str::random(4),
            'event_description' => 'Test',
            'contact_email' => 'e@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'event_section_uuid' => $section?->uuid,
        ], $attributes));
    }

    private function makeTx(Event $event, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => (string) Str::uuid(),
            'event_uuid' => $event->uuid,
            'order_number' => 'ORD-IMM-'.Str::random(10),
            'sub_total' => 1000,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 1000,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'payment_provider' => 'paypal',
        ], $attributes));
    }

    #[Test]
    public function operatorConsoleFloatHeldParsesImmutableScheduleAndPaymentDates(): void
    {
        $tz = config('app.timezone');
        $event = $this->makeEvent(null);
        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);
        $this->makeTx($event, [
            'schedule_uuid' => $schedule->uuid,
            'paid_at' => Carbon::parse('2026-05-20 10:00:00', $tz),
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, Transaction::first()->paid_at);

        $result = app(AdminOperatorConsoleService::class)->build(Carbon::parse('2026-07-15 12:00:00', $tz));

        $lines = collect($result['float_held']['lines'])->keyBy('key');
        $this->assertSame(0.0, $lines['pre_event_escrow']['value']);
        $this->assertSame(900.0, $lines['pending_payout']['value']);
        $this->assertSame(9.0, $lines['reserve']['value']);
        $this->assertSame(909.0, $result['float_held']['total']);
    }

    #[Test]
    public function transactionPnLTracksLatestPaidDateAcrossImmutableTimestamps(): void
    {
        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-03-25 12:00:00', $tz));

        $section = EventSection::create(['name' => EventSection::FEATURED_SECTION, 'title' => 'Featured']);
        $event = $this->makeEvent($section, [
            'event_name' => 'Immutable Concert',
            'published_at' => Carbon::parse('2026-03-01 08:00:00', $tz),
        ]);
        $this->makeTx($event, ['total_amount' => 400, 'paid_at' => Carbon::parse('2026-03-05 10:00:00', $tz)]);
        $this->makeTx($event, ['total_amount' => 600, 'paid_at' => Carbon::parse('2026-03-10 10:00:00', $tz)]);

        $result = (new AdminTransactionPnLService())->build(AdminTransactionPnLService::VIEW_EVENTS, '2026-03');

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame($event->uuid, $row['id']);
        $this->assertSame('Immutable Concert', $row['name']);
        $this->assertSame('Mar 10', $row['date_label']);
        $this->assertSame(1000.0, $row['gmv']);
        $this->assertSame(100.0, $row['net_revenue']);
    }
}
