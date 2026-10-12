<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use App\Constants\GeneralConstants;
use App\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

class SalesReportExportTest extends AnalyticsRepositoryTestCase
{
    use CreatesExportFixtures;

    private const HEADER = '"Order Number","Purchase Date","Payment Status","Customer Name","Customer Email","Ticket Type","Quantity","Unit Price","Discount","Total Price","Ticket Numbers","Payment Method","Reference Number","Transaction ID","Provider ID","Event Name","Event Schedule","Venue","Promo Code Used","Type"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExportFixtures();
    }

    #[Test]
    public function exportsOneRowPerOrderLineWithPaymentScheduleVenueAndPromoDetails(): void
    {
        $vip = $this->createEventTicket($this->concert, 'VIP', 1000);
        $ga = $this->createEventTicket($this->concert, 'GA', 500);

        $transaction = $this->createTransaction($this->concert, 2350, '2026-05-19 10:15:30', [
            'order_number' => 'ORD-001',
            'payment_provider' => 'paypal',
            'payment_data' => ['purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-123']]]]]],
            'payment_order_id' => 'PO-1',
            'payment_id' => 'PAY-1',
            'promo_code_uuid' => $this->promoCode->uuid,
            'discount' => 100,
        ]);
        $this->createOrder($transaction, $vip, 2, 1000, 100);
        $this->createOrder($transaction, $ga, 1, 500);
        $this->createIssuedTicket($transaction, $vip, 'T-VIP-1');
        $this->createIssuedTicket($transaction, $vip, 'T-VIP-2', status: GeneralConstants::TICKET_STATUSES['TRANSFERRED']);
        $this->createIssuedTicket($transaction, $vip, 'T-VIP-3');
        $this->createIssuedTicket($transaction, $ga, 'T-GA-1');

        $lines = $this->csvLines($this->repository()->exportSalesReport('2026-05-18', '2026-05-20'));

        $this->assertSame([
            self::HEADER,
            '"ORD-001","2026/05/19 10:15:30","paid","Chart  Buyer","analytics-customer@test.com","VIP","2","1,000.00","100.00","2,350.00","T-VIP-1_T-VIP-3","paypal","CAP-123","PO-1","PAY-1","Concert Night","2026/06/01 - 10:00 - 12:00","Grand Hall","SAVE10","paid"',
            '"ORD-001","2026/05/19 10:15:30","paid","Chart  Buyer","analytics-customer@test.com","GA","1","500.00","0.00","2,350.00","T-GA-1","paypal","CAP-123","PO-1","PAY-1","Concert Night","2026/06/01 - 10:00 - 12:00","Grand Hall","SAVE10","paid"',
        ], $lines);
    }

    #[Test]
    public function complementaryOrdersExportZeroTotalAndEventLevelDiscountLabel(): void
    {
        $entry = $this->createEventTicket($this->streetFair, 'Entry', 0);

        $transaction = $this->createTransaction($this->streetFair, 200, '2026-05-20 08:00:00', [
            'order_number' => 'ORD-COMP',
            'user_uuid' => $this->deletedCustomer->uuid,
            'payment_provider' => 'cash',
            'discount' => 50,
        ]);
        $this->createOrder($transaction, $entry, 1, 0);
        $this->createIssuedTicket($transaction, $entry, 'T-COMP-1', type: 'complementary');

        $lines = $this->csvLines($this->repository()->exportSalesReport('2026-05-20', '2026-05-20'));

        $this->assertSame([
            self::HEADER,
            '"ORD-COMP","2026/05/20 08:00:00","paid","N/A","N/A","Entry","1","0.00","0.00","0.00","T-COMP-1","cash","CASH","N/A","N/A","Street Fair","2026/06/01","123 Main St","EVENT TICKET PROMO","complementary"',
        ], $lines);
    }

    #[Test]
    public function missingScheduleVenueTicketsAndProviderFallBackToNotApplicable(): void
    {
        $basic = $this->createEventTicket($this->popUp, 'Basic', 300);

        $transaction = $this->createTransaction($this->popUp, 300, '2026-05-18 00:00:00', ['order_number' => 'ORD-BARE']);
        $this->createOrder($transaction, $basic, 1, 300);
        $basic->delete();

        $lines = $this->csvLines($this->repository()->exportSalesReport('2026-05-18', '2026-05-18'));

        $this->assertSame([
            self::HEADER,
            '"ORD-BARE","2026/05/18 00:00:00","paid","Chart  Buyer","analytics-customer@test.com","Basic","0","300.00","0.00","300.00","","N/A","N/A","N/A","N/A","Pop Up","N/A","N/A","N/A","N/A"',
        ], $lines);
    }

    #[Test]
    public function onlyPaidEventTransactionsInsideTheDateRangeAreExported(): void
    {
        $ticket = $this->createEventTicket($this->concert, 'GA', 500);

        $inRange = $this->createTransaction($this->concert, 500, '2026-05-19 12:00:00', ['order_number' => 'ORD-IN']);
        $outOfRange = $this->createTransaction($this->concert, 500, '2026-05-21 00:00:01', ['order_number' => 'ORD-LATE']);
        $pending = $this->createTransaction($this->concert, 500, '2026-05-19 12:00:00', [
            'order_number' => 'ORD-PENDING',
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);
        $nonEvent = $this->createTransaction(null, 500, '2026-05-19 12:00:00', ['order_number' => 'ORD-NONEVENT']);

        foreach ([$inRange, $outOfRange, $pending, $nonEvent] as $transaction) {
            $this->createOrder($transaction, $ticket, 1, 500);
        }

        $csv = $this->repository()->exportSalesReport('2026-05-19', '2026-05-20');
        $lines = $this->csvLines($csv);

        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('"ORD-IN",', $lines[1]);
        $this->assertStringNotContainsString('ORD-LATE', $csv);
        $this->assertStringNotContainsString('ORD-PENDING', $csv);
        $this->assertStringNotContainsString('ORD-NONEVENT', $csv);
    }

    #[Test]
    public function emptyRangeExportsOnlyTheHeader(): void
    {
        $this->assertSame(self::HEADER."\n", $this->repository()->exportSalesReport('2020-01-01', '2020-01-31'));
    }
}
