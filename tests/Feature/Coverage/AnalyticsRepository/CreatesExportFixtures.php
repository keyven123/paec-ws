<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\PromoCode;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Str;

/**
 * Three events covering every export column variant:
 * - "Concert Night": venue "Grand Hall", schedule 2026-06-01 with a 10:00-12:00 time slot
 * - "Street Fair": street address only, schedule without a time slot
 * - "Pop Up": no venue, address or schedule
 */
trait CreatesExportFixtures
{
    protected Event $concert;

    protected Event $streetFair;

    protected Event $popUp;

    protected PromoCode $promoCode;

    protected User $deletedCustomer;

    protected function setUpExportFixtures(): void
    {
        $venue = Venue::create([
            'name' => 'Grand Hall',
            'code' => 'grand-hall',
            'type' => Venue::TYPES['MUSICAL'],
        ]);

        $this->concert = $this->createEvent($this->organization, 'Concert Night', ['venue_uuid' => $venue->uuid]);
        $this->streetFair = $this->createEvent($this->organization, 'Street Fair', ['address' => '123 Main St'], withScheduleTime: false);
        $this->popUp = $this->createEvent($this->organization, 'Pop Up', withSchedule: false);

        $this->promoCode = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'SAVE10',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now()->subMonth(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->deletedCustomer = $this->createCustomer('gone@test.com');
        $this->deletedCustomer->delete();
    }

    protected function createEventTicket(Event $event, string $name, float $price): EventTicket
    {
        return EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => Str::upper(Str::slug($name)).'-'.Str::random(4),
            'name' => $name,
            'description' => $name,
            'price' => $price,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    protected function createOrder(Transaction $transaction, EventTicket $eventTicket, int $quantity, float $price, float $discount = 0): TransactionOrder
    {
        return TransactionOrder::create([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'quantity' => $quantity,
            'price' => $price,
            'discount' => $discount,
            'total_amount' => $quantity * $price - $discount,
        ]);
    }

    protected function createIssuedTicket(Transaction $transaction, EventTicket $eventTicket, string $ticketNumber, string $type = 'paid', string $status = 'active'): Ticket
    {
        return Ticket::create([
            'user_uuid' => $transaction->user_uuid,
            'organization_uuid' => $transaction->organization_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $transaction->event_uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'ticket_number' => $ticketNumber,
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee@test.com',
            'status' => $status,
            'type' => $type,
            'price' => $eventTicket->price,
            'discount' => 0,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function csvLines(string $csv): array
    {
        return explode("\n", rtrim($csv, "\n"));
    }
}
