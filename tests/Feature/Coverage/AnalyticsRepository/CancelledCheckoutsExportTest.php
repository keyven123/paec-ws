<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use App\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

class CancelledCheckoutsExportTest extends AnalyticsRepositoryTestCase
{
    use CreatesExportFixtures;

    private const HEADER = '"Event Name","Event Schedule","User","Email","Ticket Details","Discount","Discount Code","Total Amount","Payment Method","Date"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExportFixtures();
    }

    #[Test]
    public function exportsCancelledCheckoutWithTicketBreakdownScheduleAndPromoCode(): void
    {
        $vip = $this->createEventTicket($this->concert, 'VIP', 1000);
        $ga = $this->createEventTicket($this->concert, 'GA', 500);

        $transaction = $this->cancelledCheckout($this->concert, 2400, '2026-05-19 09:45:00', [
            'payment_provider' => 'paymongo',
            'promo_code_uuid' => $this->promoCode->uuid,
            'discount' => 100,
        ]);
        $this->createOrder($transaction, $vip, 2, 1000, 100);
        $this->createOrder($transaction, $ga, 1, 500);

        $this->assertSame([
            self::HEADER,
            '"Concert Night","2026/06/01 - 10:00 - 12:00","Chart  Buyer","analytics-customer@test.com","VIP (qty:2)GA (qty:1)","100.00","SAVE10","2,400.00","paymongo","2026/05/19 09:45"',
        ], $this->csvLines($this->repository()->cancelledCheckouts('2026-05-19', '2026-05-19')));
    }

    #[Test]
    public function missingUserPromoProviderAndScheduleTimeFallBack(): void
    {
        $entry = $this->createEventTicket($this->streetFair, 'Entry', 50);
        $basic = $this->createEventTicket($this->popUp, 'Basic', 300);

        $fair = $this->cancelledCheckout($this->streetFair, 150, '2026-05-20 08:00:00', ['user_uuid' => $this->deletedCustomer->uuid]);
        $this->createOrder($fair, $entry, 3, 50);

        $popUp = $this->cancelledCheckout($this->popUp, 300, '2026-05-20 09:00:00');
        $this->createOrder($popUp, $basic, 1, 300);

        $lines = $this->csvLines($this->repository()->cancelledCheckouts('2026-05-20', '2026-05-20'));

        $this->assertCount(3, $lines);
        $this->assertSame(self::HEADER, $lines[0]);
        $this->assertContains('"Street Fair","2026/06/01","N/A","N/A","Entry (qty:3)","0.00","","150.00","N/A","2026/05/20 08:00"', $lines);
        $this->assertContains('"Pop Up","N/A","Chart  Buyer","analytics-customer@test.com","Basic (qty:1)","0.00","","300.00","N/A","2026/05/20 09:00"', $lines);
    }

    #[Test]
    public function onlyCancelledOrdersInsideTheDateRangeAreExported(): void
    {
        $this->cancelledCheckout($this->popUp, 10, '2026-05-19 23:59:59', ['order_number' => 'ORD-EDGE']);
        $this->cancelledCheckout($this->popUp, 20, '2026-05-17 23:59:59', ['order_number' => 'ORD-EARLY']);
        $this->createTransaction($this->popUp, 30, '2026-05-19 12:00:00');

        $lines = $this->csvLines($this->repository()->cancelledCheckouts('2026-05-18', '2026-05-19'));

        $this->assertSame([
            self::HEADER,
            '"Pop Up","N/A","Chart  Buyer","analytics-customer@test.com","","0.00","","10.00","N/A","2026/05/19 23:59"',
        ], $lines);
    }

    private function cancelledCheckout($event, float $amount, string $createdAt, array $attributes = []): Transaction
    {
        return $this->createTransaction($event, $amount, $createdAt, array_merge([
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
            'paid_at' => null,
        ], $attributes));
    }
}
