<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TempTransaction;
use App\Models\User;
use App\Notifications\TempTransactionMarketingFollowupNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendTempTransactionMarketingFollowupCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $role = Role::create([
            'name' => 'Customer',
            'code' => 'customer-marketing-followup-test',
            'is_admin' => false,
        ]);

        $this->user = User::factory()->create([
            'role_uuid' => $role->uuid,
            'first_name' => 'Jane',
            'email' => 'jane-marketing@test.com',
        ]);

        $this->organization = Organization::create([
            'name' => 'Marketing Followup Org',
            'representative_first_name' => 'Test',
            'representative_last_name' => 'Org',
            'email' => 'marketing-org@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->event = Event::create([
            'organization_uuid' => $this->organization->uuid,
            'event_name' => 'Summer Concert',
            'event_description' => 'Marketing follow-up test event',
            'contact_email' => 'event-marketing@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_sends_followup_email_for_temp_transactions_created_forty_minutes_ago(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Asia/Manila'));

        $eligible = $this->createTempTransactionAtMinutesAgo(42);

        $this->artisan('app:send-temp-transaction-marketing-followup')->assertSuccessful();

        Notification::assertSentTo(
            $this->user,
            TempTransactionMarketingFollowupNotification::class,
            fn ($notification) => $notification->tempTransaction->uuid === $eligible->uuid,
        );

        $this->assertNotNull($eligible->fresh()->marketing_followup_sent_at);
    }

    #[Test]
    public function it_does_not_send_for_temp_transactions_outside_the_forty_minute_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Asia/Manila'));

        $tooRecent = $this->createTempTransactionAtMinutesAgo(30);
        $tooOld = $this->createTempTransactionAtMinutesAgo(50);

        $this->artisan('app:send-temp-transaction-marketing-followup')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($tooRecent->fresh()->marketing_followup_sent_at);
        $this->assertNull($tooOld->fresh()->marketing_followup_sent_at);
    }

    #[Test]
    public function it_does_not_send_followup_twice_for_the_same_temp_transaction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Asia/Manila'));

        $eligible = $this->createTempTransactionAtMinutesAgo(42);
        $eligible->update(['marketing_followup_sent_at' => now()->subMinute()]);

        $this->artisan('app:send-temp-transaction-marketing-followup')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function notification_uses_checkout_url_and_event_name(): void
    {
        config(['app.frontend_url' => 'https://ticketoc.test']);

        $tempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'organization_uuid' => $this->organization->uuid,
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        $notification = new TempTransactionMarketingFollowupNotification($tempTransaction);
        $mail = $notification->toMail($this->user);
        $viewData = $mail->viewData['data'] ?? $mail->data();

        $this->assertSame(
            'https://ticketoc.test/checkout/' . $tempTransaction->uuid,
            $viewData['checkout_url'],
        );
        $this->assertSame('Summer Concert', $viewData['event_name']);
        $this->assertSame('Jane', $viewData['first_name']);
        $this->assertSame(
            'Ticketoc: Complete Your Ticket Reservation Before It Expires',
            $mail->subject,
        );
    }

    private function createTempTransactionAtMinutesAgo(int $minutesAgo): TempTransaction
    {
        $tempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'organization_uuid' => $this->organization->uuid,
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        $createdAt = now()->subMinutes($minutesAgo);

        DB::table('temp_transactions')->where('uuid', $tempTransaction->uuid)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $tempTransaction->fresh();
    }
}
