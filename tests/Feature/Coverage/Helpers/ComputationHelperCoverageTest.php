<?php

namespace Tests\Feature\Coverage\Helpers;

use App\Constants\GeneralConstants;
use App\Helpers\ComputationHelper;
use App\Models\EventTicket;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComputationHelperCoverageTest extends TestCase
{
    #[Test]
    public function promoEligibleTotalSumsOrderLinesWhenPresent(): void
    {
        $this->assertSame(350.25, ComputationHelper::promoEligibleCartTotal([
            'total_amount' => 999,
            'temp_transaction_orders' => [['total_amount' => 200.25], ['total_amount' => 150], []],
        ]));
    }

    #[Test]
    public function promoEligibleTotalFallsBackToBaseMinusDiscountPlusMarkup(): void
    {
        $this->assertSame(470.5, ComputationHelper::promoEligibleCartTotal([
            'total_amount' => 500,
            'total_discount' => 50,
            'markup_amount' => 20.5,
        ]));
        $this->assertSame(0.0, ComputationHelper::promoEligibleCartTotal([]));
    }

    #[Test]
    public function totalDiscountIsTheTicketDiscountTimesQuantity(): void
    {
        $percentage = (new EventTicket())->forceFill([
            'price' => 200,
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
        ]);
        $fixed = (new EventTicket())->forceFill([
            'price' => 200,
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            'discount_value' => 25,
        ]);

        $this->assertSame(60.0, ComputationHelper::calculateTotalDiscount($percentage, 3));
        $this->assertSame(50.0, ComputationHelper::calculateTotalDiscount($fixed, 2));
    }

    #[Test]
    public function promoDiscountIsZeroForAnEmptyCart(): void
    {
        $promo = (new PromoCode())->forceFill([
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 50,
        ]);

        $this->assertSame(0.0, ComputationHelper::calculatePromoCodeDiscount($promo, 0));
    }

    #[Test]
    public function fixedPromoDiscountIsCappedAtTheCartTotal(): void
    {
        $promo = (new PromoCode())->forceFill([
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            'discount_value' => 300,
        ]);

        $this->assertSame(120.0, ComputationHelper::calculatePromoCodeDiscount($promo, 120));
        $this->assertSame(300.0, ComputationHelper::calculatePromoCodeDiscount($promo, 1000));
    }

    #[Test]
    public function percentagePromoDiscountIsAShareOfTheCartTotal(): void
    {
        $promo = (new PromoCode())->forceFill([
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 15,
        ]);

        $this->assertSame(30.0, ComputationHelper::calculatePromoCodeDiscount($promo, 200));
    }

    #[Test]
    public function refundDiscountIsProratedByTheOrderLineShare(): void
    {
        $transaction = (new Transaction())->forceFill([
            'discount' => 50,
            'promo_code_discount' => 30,
            'total_amount' => 400,
        ]);
        $line = (new TransactionOrder())->forceFill(['total_amount' => 100]);

        $this->assertEqualsWithDelta(20.0, ComputationHelper::calculateRefundDiscount($transaction, $line), 0.0001);
    }
}
