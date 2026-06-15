<?php

namespace Tests\Unit;

use App\Constants\GeneralConstants;
use App\Models\ActivityCompliance;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketPurchasePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketPurchasePricingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_gross_revenue_per_ticket_includes_markup_and_tax(): void
    {
        $event = Event::factory()->create();
        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);
        $user = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'pricing-customer@test.com',
            'password' => 'password123',
            'first_name' => 'Price',
            'last_name' => 'Buyer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'order_number' => 'ORD-PRICE-1',
            'sub_total' => 999,
            'discount' => 200,
            'markup_amount' => 100,
            'tax_amount' => 107.88,
            'total_amount' => 1006.88,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'GA',
            'name' => 'GA',
            'price' => 999,
            'markup_type' => 'amount',
            'markup_value' => 100,
        ]);

        TransactionOrder::create([
            'user_uuid' => $user->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'quantity' => 1,
            'price' => 999,
            'markup' => 100,
            'markup_discount' => 0,
            'discount' => 200,
            'total_amount' => 899,
        ]);

        $ticket = $transaction->tickets()->create([
            'user_uuid' => $user->uuid,
            'organization_uuid' => $event->organization_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => 'Price Buyer',
            'attendee_email' => 'pricing-customer@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'price' => 799,
            'discount' => 200,
            'qr_code' => 'QR-TEST-1',
        ]);

        $line = TicketPurchasePricingService::lineAmounts(
            $event->fresh(),
            $transaction->transactionOrders()->first(),
            999,
            1,
            200,
        );

        $this->assertSame(799.0, $line['net_selling_price']);
        $this->assertSame(100.0, $line['markup']);
        $this->assertSame(899.0, $line['gross_selling_price']);
        $this->assertSame(107.88, $line['tax_and_fees']);
        $this->assertSame(1006.88, $line['gross_revenue']);

        $perTicket = TicketPurchasePricingService::customerGrossRevenuePerTicket($ticket->fresh(['transaction.transactionOrders', 'transaction.event', 'transaction.tickets']));
        $this->assertSame(1006.88, $perTicket);

        $transaction->load(['event', 'transactionOrders', 'affiliateConversion']);
        $this->assertSame(799.0, TicketPurchasePricingService::transactionNetSellingTotal($transaction));
        $this->assertSame(719.1, TicketPurchasePricingService::transactionMerchantSalesTotal($transaction, 10.0));
    }

    public function test_percentage_promo_discount_splits_between_base_and_markup_in_export(): void
    {
        $event = Event::factory()->create();
        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);
        $user = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'pricing-promo@test.com',
            'password' => 'password123',
            'first_name' => 'Promo',
            'last_name' => 'Buyer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $promoCode = \App\Models\PromoCode::create([
            'organization_uuid' => $event->organization_uuid,
            'code' => 'PROMO10',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'order_number' => 'ORD-PROMO-1',
            'sub_total' => 999,
            'discount' => 200,
            'markup_amount' => 100,
            'promo_code_uuid' => $promoCode->uuid,
            'promo_code_discount' => 89.9,
            'tax_amount' => 97.09,
            'total_amount' => 906.99,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'GA-PROMO',
            'name' => 'GA Promo',
            'price' => 999,
            'markup_type' => 'amount',
            'markup_value' => 100,
        ]);

        $order = TransactionOrder::create([
            'user_uuid' => $user->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'quantity' => 1,
            'price' => 999,
            'markup' => 100,
            'markup_discount' => 0,
            'discount' => 200,
            'total_amount' => 899,
        ]);

        $transaction->load(['event', 'transactionOrders', 'promoCode']);

        $line = TicketPurchasePricingService::lineAmountsForPaidOrder($transaction, $order);

        $this->assertSame(279.9, $line['discount']);
        $this->assertSame(719.1, $line['net_selling_price']);
        $this->assertSame(90.0, $line['markup']);
        $this->assertSame(809.1, $line['gross_selling_price']);
        $this->assertSame(97.09, $line['tax_and_fees']);
        $this->assertSame(906.19, $line['gross_revenue']);
    }

    public function test_paid_order_line_splits_transaction_tax_amount_across_lines(): void
    {
        $event = Event::factory()->create();
        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);
        $user = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'pricing-tax-split@test.com',
            'password' => 'password123',
            'first_name' => 'Tax',
            'last_name' => 'Split',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'order_number' => 'ORD-TAX-SPLIT',
            'sub_total' => 1500,
            'tax_amount' => 120,
            'total_amount' => 1620,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        $ticketA = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'A',
            'name' => 'Ticket A',
            'price' => 1000,
        ]);
        $ticketB = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'B',
            'name' => 'Ticket B',
            'price' => 500,
        ]);

        $orderA = TransactionOrder::create([
            'user_uuid' => $user->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $ticketA->uuid,
            'quantity' => 1,
            'price' => 1000,
            'total_amount' => 1000,
        ]);
        $orderB = TransactionOrder::create([
            'user_uuid' => $user->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $ticketB->uuid,
            'quantity' => 1,
            'price' => 500,
            'total_amount' => 500,
        ]);

        $transaction->load(['event', 'transactionOrders']);

        $lineA = TicketPurchasePricingService::lineAmountsForPaidOrder($transaction, $orderA);
        $lineB = TicketPurchasePricingService::lineAmountsForPaidOrder($transaction, $orderB);

        $this->assertSame(80.0, $lineA['tax_and_fees']);
        $this->assertSame(40.0, $lineB['tax_and_fees']);
        $this->assertSame(120.0, round($lineA['tax_and_fees'] + $lineB['tax_and_fees'], 2));
    }
}
