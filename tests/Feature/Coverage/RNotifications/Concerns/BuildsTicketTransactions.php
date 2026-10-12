<?php

namespace Tests\Feature\Coverage\RNotifications\Concerns;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use App\Services\TicketEmailExportService;
use Illuminate\Support\Str;

trait BuildsTicketTransactions
{
    protected const PORTRAIT_URL = 'https://cdn.example.test/events/portrait.jpg';

    protected function createPaidTransaction(bool $withPortrait = true, string $qrCode = ''): Transaction
    {
        $role = Role::create([
            'name' => 'Customer',
            'code' => 'customer-rnotif-' . Str::uuid()->toString(),
            'is_admin' => false,
        ]);
        $user = User::factory()->create(['role_uuid' => $role->uuid]);

        $portrait = $withPortrait
            ? Upload::create([
                'uploadable_type' => 'event',
                'uploadable_uuid' => (string) Str::uuid(),
                'collection' => 'portrait',
                'type' => 'image',
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'disk' => 'public',
                'path' => self::PORTRAIT_URL,
                'order_number' => 0,
            ])
            : null;

        $event = Event::create([
            'event_name' => 'Coverage Concert',
            'event_description' => 'Notification coverage event',
            'contact_email' => 'events@example.test',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'portrait_image_uuid' => $portrait?->uuid,
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-12-24',
            'date_to' => '2026-12-24',
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
            'name' => 'VIP',
            'description' => 'VIP ticket',
            'price' => 2500,
            'is_bundle' => false,
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'order_number' => 'ORD-RNOTIF-0001',
            'sub_total' => 2500,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 2500,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => '2026-10-01 10:30:00',
        ]);

        Ticket::create([
            'user_uuid' => $user->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => 'Juan Dela Cruz',
            'attendee_email' => 'juan@example.test',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'qr_code' => $qrCode,
        ]);

        return $transaction->fresh(['user', 'event', 'tickets']);
    }

    /**
     * TicketEmailExportService is final; the notifications only call these two methods on the resolved instance.
     *
     * @param  array{data: string, filename: string, mime: string}|null  $ticketReturn
     * @param  array{data: string, filename: string, mime: string}|null  $couponReturn
     */
    protected function bindExportServiceStub(?array $ticketReturn = null, ?array $couponReturn = null): object
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
}
