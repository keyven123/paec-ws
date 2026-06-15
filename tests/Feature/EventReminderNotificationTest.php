<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventReminderLog;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Services\TicketEmailExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the MailMessage built by EventReminderNotification::toMail() for each reminder type.
 *
 * The TicketEmailExportService is final, so Mockery cannot subclass it. Instead we register
 * a duck-typed anonymous-class stub against the same container key. This isolates the test
 * from Dompdf / Imagick / remote image fetching while still letting us verify which reminder
 * types attach files.
 */
class EventReminderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN_NOW = '2026-06-20 12:00:00';

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, config('app.timezone')));

        $this->role = Role::create([
            'name' => 'Customer',
            'code' => 'customer-reminder-notif-test-' . Str::uuid()->toString(),
            'is_admin' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function seven_day_reminder_has_friendly_subject_and_no_attachments(): void
    {
        $tx = $this->makeTransactionWithTicket();
        $stub = $this->bindExportServiceStub();

        $message = (new EventReminderNotification($tx->uuid, EventReminderLog::TYPE_7_DAYS))
            ->toMail($tx->user);

        $this->assertSame('[Ticketoc] Your event is just 7 days away', $message->subject);
        $this->assertSame('emails.event-reminder-dark', $message->view);
        $this->assertSame(EventReminderLog::TYPE_7_DAYS, $message->viewData['data']['reminder_type']);
        $this->assertFalse($message->viewData['data']['show_qr_codes']);
        $this->assertEmpty($message->rawAttachments ?? []);

        $this->assertSame(0, $stub->ticketCalls, 'Export service must not be called for 7-day reminder');
        $this->assertSame(0, $stub->couponCalls, 'Export service must not be called for 7-day reminder');
    }

    #[Test]
    public function twelve_hour_reminder_has_friendly_subject_and_no_attachments(): void
    {
        $tx = $this->makeTransactionWithTicket();
        $stub = $this->bindExportServiceStub();

        $message = (new EventReminderNotification($tx->uuid, EventReminderLog::TYPE_12_HOURS))
            ->toMail($tx->user);

        $this->assertSame('[Ticketoc] Your event is in 12 hours', $message->subject);
        $this->assertSame(EventReminderLog::TYPE_12_HOURS, $message->viewData['data']['reminder_type']);
        $this->assertFalse($message->viewData['data']['show_qr_codes']);
        $this->assertEmpty($message->rawAttachments ?? []);

        $this->assertSame(0, $stub->ticketCalls);
        $this->assertSame(0, $stub->couponCalls);
    }

    #[Test]
    public function forty_eight_hour_reminder_attaches_ticket_and_voucher_files(): void
    {
        $tx = $this->makeTransactionWithTicket();
        $stub = $this->bindExportServiceStub(
            ticketReturn: [
                'data' => 'fake-ticket-bytes',
                'filename' => 'ticket-test.pdf',
                'mime' => 'application/pdf',
            ],
            couponReturn: [
                'data' => 'fake-voucher-bytes',
                'filename' => 'coupons-test.pdf',
                'mime' => 'application/pdf',
            ],
        );

        $message = (new EventReminderNotification($tx->uuid, EventReminderLog::TYPE_48_HOURS))
            ->toMail($tx->user);

        $this->assertStringContainsString('48 hours', $message->subject);
        $this->assertSame(EventReminderLog::TYPE_48_HOURS, $message->viewData['data']['reminder_type']);
        $this->assertTrue($message->viewData['data']['show_qr_codes']);

        $this->assertSame(1, $stub->ticketCalls);
        $this->assertSame(1, $stub->couponCalls);

        $rawAttachments = $message->rawAttachments ?? [];
        $this->assertCount(2, $rawAttachments);

        $filenames = array_map(fn ($a) => $a['name'], $rawAttachments);
        $this->assertContains('ticket-test.pdf', $filenames);
        $this->assertContains('coupons-test.pdf', $filenames);
    }

    #[Test]
    public function forty_eight_hour_reminder_includes_tickets_from_all_transactions_in_the_event_group(): void
    {
        $firstTx = $this->makeTransactionWithTicket();
        $secondTx = $this->makeAdditionalTransactionWithTicketForSameOccurrence($firstTx);
        $stub = $this->bindExportServiceStub(
            ticketReturn: [
                'data' => 'fake-ticket-bytes',
                'filename' => 'ticket-test.pdf',
                'mime' => 'application/pdf',
            ],
            couponReturn: [
                'data' => 'fake-voucher-bytes',
                'filename' => 'coupons-test.pdf',
                'mime' => 'application/pdf',
            ],
        );

        $message = (new EventReminderNotification(
            [$firstTx->uuid, $secondTx->uuid],
            EventReminderLog::TYPE_48_HOURS,
        ))->toMail($firstTx->user);

        $this->assertTrue($message->viewData['data']['show_qr_codes']);
        $this->assertCount(2, $message->viewData['data']['tickets']);

        $this->assertSame(2, $stub->ticketCalls);
        $this->assertSame(2, $stub->couponCalls);
        $this->assertCount(4, $message->rawAttachments ?? []);
    }

    #[Test]
    public function forty_eight_hour_reminder_only_includes_active_tickets_in_body_and_attachments(): void
    {
        $tx = $this->makeTransactionWithTicket();
        Ticket::create([
            'user_uuid' => $tx->user_uuid,
            'transaction_uuid' => $tx->uuid,
            'event_uuid' => $tx->event_uuid,
            'event_ticket_uuid' => $tx->tickets->first()->event_ticket_uuid,
            'attendee_name' => 'Expired Holder',
            'attendee_email' => 'expired-' . Str::uuid()->toString() . '@example.com',
            'status' => GeneralConstants::TICKET_STATUSES['EXPIRED'],
            'qr_code' => '',
        ]);

        $stub = $this->bindExportServiceStub(
            ticketReturn: [
                'data' => 'fake-ticket-bytes',
                'filename' => 'ticket-test.pdf',
                'mime' => 'application/pdf',
            ],
            couponReturn: [
                'data' => 'fake-voucher-bytes',
                'filename' => 'coupons-test.pdf',
                'mime' => 'application/pdf',
            ],
        );

        $message = (new EventReminderNotification($tx->uuid, EventReminderLog::TYPE_48_HOURS))
            ->toMail($tx->user);

        $this->assertCount(1, $message->viewData['data']['tickets']);
        $this->assertSame(1, $stub->ticketCalls);
        $this->assertSame(1, $stub->couponCalls);
        $this->assertCount(2, $message->rawAttachments ?? []);
    }

    #[Test]
    public function forty_eight_hour_reminder_skips_attachments_when_export_service_returns_null(): void
    {
        $tx = $this->makeTransactionWithTicket();
        $stub = $this->bindExportServiceStub();

        $message = (new EventReminderNotification($tx->uuid, EventReminderLog::TYPE_48_HOURS))
            ->toMail($tx->user);

        $this->assertEmpty($message->rawAttachments ?? []);
        // QR rendering must still be enabled so the inline QR codes appear in the body.
        $this->assertTrue($message->viewData['data']['show_qr_codes']);

        $this->assertSame(1, $stub->ticketCalls);
        $this->assertSame(1, $stub->couponCalls);
    }

    #[Test]
    public function payload_includes_event_metadata_used_by_the_blade_template(): void
    {
        $tx = $this->makeTransactionWithTicket();
        $this->bindExportServiceStub();

        $message = (new EventReminderNotification($tx->uuid, EventReminderLog::TYPE_7_DAYS))
            ->toMail($tx->user);

        $data = $message->viewData['data'];

        $this->assertArrayHasKey('event_name', $data);
        $this->assertArrayHasKey('event_date', $data);
        $this->assertArrayHasKey('event_time', $data);
        $this->assertArrayHasKey('view_ticket', $data);
        $this->assertArrayHasKey('view_coupons', $data);
        $this->assertArrayHasKey('email_headline', $data);
        $this->assertArrayHasKey('email_body', $data);
        $this->assertArrayHasKey('current_year', $data);

        $this->assertSame($tx->event->event_name, $data['event_name']);
        $this->assertSame($tx->order_number, $data['transaction']['order_number']);
        $this->assertCount(1, $data['tickets']);
    }

    /**
     * Bind a duck-typed stub for TicketEmailExportService into the container.
     *
     * The real class is `final`, so Mockery cannot subclass it. Laravel's container does not
     * type-check `instance()` bindings, and EventReminderNotification only calls
     * `buildAttachment()` / `buildCouponsAttachment()` on the resolved object, so this stub
     * is sufficient to capture call counts and control return values.
     *
     * @param  array{data: string, filename: string, mime: string}|null  $ticketReturn
     * @param  array{data: string, filename: string, mime: string}|null  $couponReturn
     */
    private function bindExportServiceStub(?array $ticketReturn = null, ?array $couponReturn = null): object
    {
        $stub = new class($ticketReturn, $couponReturn)
        {
            public int $ticketCalls = 0;

            public int $couponCalls = 0;

            public function __construct(
                private readonly ?array $ticketReturn,
                private readonly ?array $couponReturn,
            ) {
            }

            public function buildAttachment($transaction, $ticket): ?array
            {
                $this->ticketCalls++;

                return $this->ticketReturn;
            }

            public function buildCouponsAttachment($transaction, $ticket): ?array
            {
                $this->couponCalls++;

                return $this->couponReturn;
            }
        };

        $this->app->instance(TicketEmailExportService::class, $stub);

        return $stub;
    }

    private function makeTransactionWithTicket(): Transaction
    {
        $user = User::factory()->create(['role_uuid' => $this->role->uuid]);

        $event = Event::create([
            'event_name' => 'Reminder Notif Event',
            'event_description' => 'For reminder notification tests',
            'contact_email' => 'reminder@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => now()->copy()->addDays(7)->toDateString(),
            'date_to' => now()->copy()->addDays(7)->toDateString(),
            'status' => Schedule::PUBLISHED_STATUS,
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '19:00:00',
            'time_end' => '21:00:00',
            'status' => ScheduleTime::PUBLISHED_STATUS,
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'code' => 'T-' . Str::uuid()->toString(),
            'name' => 'General',
            'description' => 'Standard ticket',
            'price' => 100,
            'is_bundle' => false,
        ]);

        $tx = Transaction::create([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'order_number' => 'ORD-' . Str::uuid()->toString(),
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now()->subHour(),
        ]);

        // Empty qr_code so TicketQrPngService::pngBinary() short-circuits to null —
        // keeps the notification test free of QR generation overhead.
        Ticket::create([
            'user_uuid' => $user->uuid,
            'transaction_uuid' => $tx->uuid,
            'event_uuid' => $event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee-' . Str::uuid()->toString() . '@example.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'qr_code' => '',
        ]);

        return $tx->fresh(['user', 'event', 'tickets']);
    }

    private function makeAdditionalTransactionWithTicketForSameOccurrence(Transaction $base): Transaction
    {
        $tx = Transaction::create([
            'user_uuid' => $base->user_uuid,
            'event_uuid' => $base->event_uuid,
            'schedule_uuid' => $base->schedule_uuid,
            'schedule_time_uuid' => $base->schedule_time_uuid,
            'order_number' => 'ORD-' . Str::uuid()->toString(),
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now()->subHour(),
        ]);

        Ticket::create([
            'user_uuid' => $base->user_uuid,
            'transaction_uuid' => $tx->uuid,
            'event_uuid' => $base->event_uuid,
            'event_ticket_uuid' => $base->tickets->first()->event_ticket_uuid,
            'attendee_name' => 'Second Attendee',
            'attendee_email' => 'attendee-' . Str::uuid()->toString() . '@example.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'qr_code' => '',
        ]);

        return $tx->fresh(['user', 'event', 'tickets']);
    }
}
