<?php

namespace Tests\Feature\Coverage\GapOrganizer;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Schedule;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerDashboardControllerCoverageTest extends TestCase
{
    use OrganizerGapFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/organizer/dashboard';

    private Organization $organization;

    private Organization $otherOrganization;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00'));

        $this->organization = $this->makeOrganization('Dashboard Merchant');
        $this->otherOrganization = $this->makeOrganization('Other Dashboard Merchant');
        $this->customer = $this->makeCustomer('dash-customer@example.com', 'Dana');

        $this->loginOrganizerAdmin($this->organization, ['organizers' => ['view']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function setCreatedAt(string $table, string $uuid, string $createdAt): void
    {
        DB::table($table)->where('uuid', $uuid)->update(['created_at' => $createdAt]);
    }

    private function addSchedule(Event $event, string $dateFrom): Schedule
    {
        return Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => $dateFrom,
            'date_to' => $dateFrom,
            'status' => 'published',
        ]);
    }

    #[Test]
    public function recentActivitiesMergesPurchasesAndEventsNewestFirst(): void
    {
        $event = $this->makeOrgEvent($this->organization, 'Spring Fest');
        $this->setCreatedAt('events', $event->uuid, '2026-05-01 09:00:00');

        $online = $this->makeOrgTransaction($this->organization, $event, $this->customer, 300, [
            'payment_order_id' => 'PAY-ORDER-1',
        ]);
        $this->setCreatedAt('transactions', $online->uuid, '2026-05-03 09:00:00');

        $manual = $this->makeOrgTransaction($this->organization, null, null, 150);
        $this->setCreatedAt('transactions', $manual->uuid, '2026-05-02 09:00:00');

        $this->makeOrgTransaction($this->organization, $event, $this->customer, 99, [
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);
        $this->makeOrgEvent($this->otherOrganization, 'Foreign Fest');

        $response = $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/recent-activities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Recent activity feed')
            ->assertJsonCount(3, 'activities');

        $activities = $response->json('activities');

        $this->assertSame('purchase', $activities[0]['type']);
        $this->assertSame(
            $this->customer->full_name . ' purchased tickets for Spring Fest with total amount of 300.00',
            $activities[0]['message'],
        );

        $this->assertSame('purchase', $activities[1]['type']);
        $this->assertSame('System manually added ticket to user User for an event', $activities[1]['message']);

        $this->assertSame('event', $activities[2]['type']);
        $this->assertSame('New event created: Spring Fest', $activities[2]['message']);
    }

    #[Test]
    public function recentActivitiesIsEmptyForOrganizationWithoutData(): void
    {
        $this->makeOrgEvent($this->otherOrganization, 'Foreign Only');

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/recent-activities')
            ->assertOk()
            ->assertJsonCount(0, 'activities');
    }

    #[Test]
    public function upcomingEventsReturnsPublishedEventsWithFutureSchedules(): void
    {
        $upcoming = $this->makeOrgEvent($this->organization, 'Upcoming Show', [
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);
        $this->addSchedule($upcoming, '2026-06-01');

        $past = $this->makeOrgEvent($this->organization, 'Past Show', [
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);
        $this->addSchedule($past, '2026-04-01');

        $draft = $this->makeOrgEvent($this->organization, 'Draft Show');
        $this->addSchedule($draft, '2026-07-01');

        $foreign = $this->makeOrgEvent($this->otherOrganization, 'Foreign Show', [
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);
        $this->addSchedule($foreign, '2026-07-01');

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/upcoming-events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $upcoming->uuid);
    }

    #[Test]
    public function dashboardStatsCountsOnlyOrganizationData(): void
    {
        $published = $this->makeOrgEvent($this->organization, 'Published Event', [
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);
        $this->makeOrgEvent($this->organization, 'Draft Event');
        $this->makeOrgEvent($this->otherOrganization, 'Foreign Event', [
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $paid = $this->makeOrgTransaction($this->organization, $published, $this->customer, 400, [
            'payment_provider' => 'paymongo',
        ]);
        $manual = $this->makeOrgTransaction($this->organization, $published, $this->customer, 100);
        $this->makeOrgTransaction($this->otherOrganization, null, $this->customer, 900, [
            'payment_provider' => 'paymongo',
        ]);

        $eventTicket = $this->makeEventTicket($published);

        foreach ([[$paid, 'TKT-PAID-1'], [$paid, 'TKT-PAID-2'], [$manual, 'TKT-MANUAL-1']] as [$tx, $number]) {
            Ticket::create([
                'user_uuid' => $this->customer->uuid,
                'organization_uuid' => $this->organization->uuid,
                'transaction_uuid' => $tx->uuid,
                'event_uuid' => $published->uuid,
                'event_ticket_uuid' => $eventTicket->uuid,
                'ticket_number' => $number,
                'qr_code' => 'QR-' . $number,
                'attendee_name' => 'Dana Customer',
                'attendee_email' => $this->customer->email,
                'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            ]);
        }

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Dashboard statistics')
            ->assertJsonPath('data.total_events', 2)
            ->assertJsonPath('data.active_events', 1)
            ->assertJsonPath('data.tickets_sold', 2)
            ->assertJsonPath('data.total_revenue', 500);
    }

    #[Test]
    public function dashboardRequiresOrganizersViewPermission(): void
    {
        $this->loginOrganizerAdmin($this->organization, ['users' => ['view']]);

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/stats')
            ->assertForbidden();
    }
}
