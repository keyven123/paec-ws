<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\CsvHelper;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use App\Helpers\GeneralHelper;
use App\Models\VenueSeat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MigratePurchases extends Command
{
    use CsvHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-purchases';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate purchases';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::beginTransaction();
        $purchases = $this->csvToArray(app_path('Console/data/purchases.csv'));
        $purchaseItems = $this->csvToArray(app_path('Console/data/purchase_items.csv'));
        $users = $this->csvToArray(app_path('Console/data/users.csv'));
        $events = $this->csvToArray(app_path('Console/data/events.csv'));
        $tickets = $this->csvToArray(app_path('Console/data/tickets.csv'));
        foreach ($purchases as $purchase) {
            $searchUser = collect($users)->firstWhere('id', $purchase['user_id']);
            $event = collect($events)->firstWhere('uuid', $purchase['event_id']);
            $user = User::where('email', $searchUser['email'])->first();
            $event = Event::where('event_name', 'LIKE', '%' . $event['event_name'] . '%')->first();
            $ticketTypes = $this->csvToArray(app_path('Console/data/ticket_types.csv'));
            $transaction = Transaction::create([
                'user_uuid' => $user->uuid,
                'event_uuid' => $event->uuid,
                'schedule_uuid' => $event->schedules->first()->uuid,
                'schedule_time_uuid' => $event->schedules->first()->scheduleTimes()->first()->uuid,
                'organization_uuid' => $event->organization_uuid ?? null,
                'payment_order_id' => $purchase['paypal_order_id'],
                'order_number' => $purchase['order_number'],
                'total_amount' => $purchase['total_amount'],
                'sub_total' => $purchase['sub_total'] ?? 0,
                'tax_amount' => $purchase['tax_amount'] ?? 0,
                'discount' => $purchase['discount_amount'] ?? 0,
                'status' => $purchase['payment_status'],
                'payment_status' => $purchase['payment_status'],
                'order_status' => $purchase['order_status'],
            ]);

            $this->info('Transaction created: ' . $purchase['order_number']);
            $searchPurchaseItem = collect($purchaseItems)->where('purchase_uuid', $purchase['uuid']);
            foreach ($searchPurchaseItem as $key => $item) {
                $searchTicketType = collect($ticketTypes)->firstWhere('uuid', $item['ticket_type_id']);
                $eventTicket = EventTicket::where('event_uuid', $event->uuid)
                    ->where('name', $searchTicketType['name'])
                    ->first();
                TransactionOrder::create([
                    'user_uuid' => $user->uuid,
                    'transaction_uuid' => $transaction->uuid,
                    'event_ticket_uuid' => $eventTicket->uuid,
                    'quantity' => $item['quantity'],
                    'price' => $item['unit_price'],
                    'total_amount' => $item['total_price'],
                ]);

                $orderTickets = collect($tickets)->where('purchase_item_id', $item['id']);
                foreach ($orderTickets as $i => $orderTicket) {
                    $seatNo = explode('-', $orderTicket['seat_number']);
                    if (count($seatNo) > 1) {
                        $venueSeat = VenueSeat::where('col', $seatNo[0])
                            ->where('row', $seatNo[1])
                            ->where('venue_uuid', $event->venue_uuid)
                            ->first();
                    }
                    Ticket::create([
                        'user_uuid' => $user->uuid,
                        'transaction_uuid' => $transaction->uuid,
                        'event_uuid' => $event->uuid,
                        'event_ticket_uuid' => $eventTicket->uuid,
                        'venue_seat_uuid' => $venueSeat->uuid ?? null,
                        'organization_uuid' => $event->organization_uuid ?? null,
                        'ticket_number' => GeneralHelper::generateUuidTicketNumber($event->ticket_prefix ?? 'TKT'),
                        'col' => $seatNo[0] ?? null,
                        'row' => $seatNo[1] ?? null,
                        'status' => $orderTicket['status'],
                        'attendee_name' => $orderTicket['attendee_name'],
                        'attendee_email' => $orderTicket['attendee_email'],
                        'attendee_contact' => $orderTicket['attendee_phone'],
                        'qr_code' => $orderTicket['qr_code'],
                        'used_at' => $orderTicket['used_at'] ? Carbon::parse($orderTicket['used_at'])->format('Y-m-d H:i:s') : null,
                        'transfer_count' => $orderTicket['transfer_count'] ?? 0,
                        'transferred_at' => $orderTicket['transferred_at'] ? Carbon::parse($orderTicket['transferred_at'])->format('Y-m-d H:i:s') : null,
                        'type' => $orderTicket['type'] ?? Ticket::TYPES['PAID'],
                    ]);
                    $this->info('Ticket created: ' . $orderTicket['qr_code']);
                }
            }
        }
        DB::commit();
    }
}
