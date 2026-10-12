<?php

namespace Tests\Feature\Coverage\PlatformServices;

use App\Models\Transaction;
use App\Services\Platform\PlatformPnLGatewayFeeEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformPnLGatewayFeeEstimatorTest extends TestCase
{
    private const PAYMONGO_RATES = [
        'qr_ph' => 1.5,
        'card' => 3.0,
        'gcash' => 2.0,
        'grab_pay' => 2.2,
        'shopee_pay' => 1.8,
        'billease' => 1.4,
        'paymaya' => 1.6,
        'dob' => 1.0,
        'dob_fixed_minimum' => 15.0,
        'brankas' => 1.2,
    ];

    private const PAYPAL_RATES = ['paypal_fee' => 4.0, 'additional_fee' => 10.0];

    private PlatformPnLGatewayFeeEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new PlatformPnLGatewayFeeEstimator();
    }

    /**
     * @param  array<string, mixed>|null  $paymentData
     */
    private function tx(?string $provider, float $amount = 1000, ?array $paymentData = null): Transaction
    {
        return new Transaction([
            'payment_provider' => $provider,
            'total_amount' => $amount,
            'payment_data' => $paymentData,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentsSource(string $type): array
    {
        return ['data' => ['attributes' => ['payments' => [['attributes' => ['source' => ['type' => $type]]]]]]];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paymongoSourceTypes(): array
    {
        return [
            'qrph' => ['qrph', 'qr_ph'],
            'card' => ['card', 'card'],
            'gcash' => ['GCash', 'gcash'],
            'grab_pay' => ['grab_pay', 'grab_pay'],
            'shopee_pay' => ['shopee_pay', 'shopee_pay'],
            'billease' => ['billease', 'billease'],
            'paymaya' => ['paymaya', 'paymaya'],
            'dob' => ['dob', 'dob'],
            'dob_ubp' => ['dob_ubp', 'dob'],
            'direct_debit' => ['direct_debit', 'dob'],
            'brankas' => ['brankas', 'brankas'],
            'brankas_bdo' => ['brankas_bdo', 'brankas'],
            'brankas_landbank' => ['brankas_landbank', 'brankas'],
            'brankas_metrobank' => [' Brankas_Metrobank ', 'brankas'],
        ];
    }

    #[Test]
    #[DataProvider('paymongoSourceTypes')]
    public function bucketNormalizesEachPaymongoSourceType(string $sourceType, string $expectedBucket): void
    {
        $tx = $this->tx('PayMongo', 1000, $this->paymentsSource($sourceType));

        $this->assertSame($expectedBucket, $this->estimator->paymongoMethodBucketForTransaction($tx));
    }

    #[Test]
    public function bucketIsNullForNonPaymongoProviders(): void
    {
        $this->assertNull($this->estimator->paymongoMethodBucketForTransaction(
            $this->tx('paypal', 1000, $this->paymentsSource('card'))
        ));
    }

    #[Test]
    public function bucketIsNullWhenPaymentDataIsMissing(): void
    {
        $this->assertNull($this->estimator->paymongoMethodBucketForTransaction($this->tx('paymongo')));
    }

    #[Test]
    public function bucketFallsBackToNestedSourceTypeAndRejectsUnknownTypes(): void
    {
        $nested = $this->tx('paymongo', 1000, ['data' => ['attributes' => ['source' => ['type' => 'gcash']]]]);
        $unknown = $this->tx('paymongo', 1000, $this->paymentsSource('bitcoin'));
        $empty = $this->tx('paymongo', 1000, ['data' => ['attributes' => ['source' => ['type' => '']]]]);

        $this->assertSame('gcash', $this->estimator->paymongoMethodBucketForTransaction($nested));
        $this->assertNull($this->estimator->paymongoMethodBucketForTransaction($unknown));
        $this->assertNull($this->estimator->paymongoMethodBucketForTransaction($empty));
    }

    #[Test]
    public function zeroOrNegativeGrossHasNoFee(): void
    {
        $this->assertSame(0.0, $this->estimator->estimate($this->tx('paypal', 0), [], self::PAYPAL_RATES));
        $this->assertSame(0.0, $this->estimator->estimate($this->tx('paypal', -50), [], self::PAYPAL_RATES));
    }

    #[Test]
    public function freeOrMissingProviderHasNoFee(): void
    {
        $this->assertSame(0.0, $this->estimator->estimate($this->tx('free'), self::PAYMONGO_RATES, self::PAYPAL_RATES));
        $this->assertSame(0.0, $this->estimator->estimate($this->tx(null), self::PAYMONGO_RATES, self::PAYPAL_RATES));
    }

    #[Test]
    public function unknownProviderHasNoFee(): void
    {
        $this->assertSame(0.0, $this->estimator->estimate($this->tx('upgrade'), self::PAYMONGO_RATES, self::PAYPAL_RATES));
    }

    #[Test]
    public function paypalFeeIsPercentPlusFixed(): void
    {
        // 1250 × 4% + 10 = 60
        $this->assertSame(60.0, $this->estimator->estimate($this->tx('PayPal', 1250), [], self::PAYPAL_RATES));
        // Missing rates default to zero.
        $this->assertSame(0.0, $this->estimator->estimate($this->tx('paypal', 1250), [], []));
    }

    #[Test]
    public function paymongoUsesTheMethodSpecificRate(): void
    {
        $fee = $this->estimator->estimate($this->tx('paymongo', 1000, $this->paymentsSource('card')), self::PAYMONGO_RATES, []);

        $this->assertSame(30.0, $fee); // 1000 × 3%
    }

    #[Test]
    public function explicitPaymentDataOverridesTheTransactionPayload(): void
    {
        $tx = $this->tx('paymongo', 1000, $this->paymentsSource('card'));

        $fee = $this->estimator->estimate($tx, self::PAYMONGO_RATES, [], $this->paymentsSource('gcash'));

        $this->assertSame(20.0, $fee); // 1000 × 2% (gcash, not card)
    }

    #[Test]
    public function paymongoWithoutPaymentDataUsesTheAveragePercentageRate(): void
    {
        $rates = ['card' => 3.0, 'gcash' => 1.0, 'dob_fixed_minimum' => 50.0, 'qr_ph' => null];

        // avg(3, 1) = 2% (dob_fixed_minimum and null rates are excluded) → 1000 × 2% = 20
        $this->assertSame(20.0, $this->estimator->estimate($this->tx('paymongo', 1000), $rates, []));
    }

    #[Test]
    public function paymongoWithUnresolvableMethodUsesTheAverageRate(): void
    {
        $rates = ['card' => 3.0, 'gcash' => 1.0];

        $this->assertSame(
            20.0,
            $this->estimator->estimate($this->tx('paymongo', 1000, ['data' => ['attributes' => []]]), $rates, [])
        );
    }

    #[Test]
    public function paymongoMethodWithoutConfiguredRateUsesTheAverageRate(): void
    {
        $rates = ['card' => 3.0, 'gcash' => 1.0];

        $this->assertSame(
            20.0,
            $this->estimator->estimate($this->tx('paymongo', 1000, $this->paymentsSource('paymaya')), $rates, [])
        );
    }

    #[Test]
    public function fallbackWithNoUsableRatesIsZero(): void
    {
        $rates = ['dob_fixed_minimum' => 25.0, 'card' => null];

        $this->assertSame(0.0, $this->estimator->estimate($this->tx('paymongo', 1000), $rates, []));
    }

    #[Test]
    public function dobFeeAppliesTheFixedMinimum(): void
    {
        $tx = $this->tx('paymongo', 1000, $this->paymentsSource('dob'));

        // 1000 × 1% = 10 < minimum 15
        $this->assertSame(15.0, $this->estimator->estimate($tx, self::PAYMONGO_RATES, []));

        $large = $this->tx('paymongo', 5000, $this->paymentsSource('dob_ubp'));
        // 5000 × 1% = 50 > minimum 15
        $this->assertSame(50.0, $this->estimator->estimate($large, self::PAYMONGO_RATES, []));
    }

    #[Test]
    public function dobFeeWithoutMinimumIsPercentageOnly(): void
    {
        $tx = $this->tx('paymongo', 1000, $this->paymentsSource('direct_debit'));

        $this->assertSame(10.0, $this->estimator->estimate($tx, ['dob' => 1.0], []));
    }

    #[Test]
    public function dobWithoutConfiguredRateUsesTheAverageRate(): void
    {
        $tx = $this->tx('paymongo', 1000, $this->paymentsSource('dob'));

        $this->assertSame(25.0, $this->estimator->estimate($tx, ['card' => 2.5, 'dob_fixed_minimum' => 99.0], []));
    }
}
