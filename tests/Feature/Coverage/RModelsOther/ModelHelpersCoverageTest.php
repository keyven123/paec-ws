<?php

namespace Tests\Feature\Coverage\RModelsOther;

use App\Models\Dataset;
use App\Models\Otp;
use App\Models\PasswordSetup;
use App\Models\PaymentGateway;
use App\Models\Upload;
use App\Models\VenueInquiry;
use App\Support\OrganizationPaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelHelpersCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function setDataset(string $name, string $value): void
    {
        DB::table('datasets')->where('name', $name)->delete();
        Dataset::create(['name' => $name, 'value' => $value]);
    }

    #[Test]
    public function datasetDefaultPaymentMethodsFallsBackWhenValueIsNotAJsonArray(): void
    {
        $this->setDataset('default_payment_methods', '"just-a-string"');

        $this->assertSame(OrganizationPaymentMethods::defaults(), Dataset::defaultPaymentMethods());
    }

    #[Test]
    public function datasetRateHelpersReturnEmptyArraysForMalformedJson(): void
    {
        $this->setDataset('paymongo_payment_rates', 'not-json');
        $this->setDataset('paypal_payment_rates', 'not-json');
        $this->setDataset('activity_compliance', 'not-json');

        $this->assertSame([], Dataset::paymongoRates());
        $this->assertSame([], Dataset::paypalRates());
        $this->assertSame([], Dataset::activityComplianceDefaults());
    }

    #[Test]
    public function datasetActivityComplianceDefaultsUseZeroPercentageWhenNoRateKeyIsPresent(): void
    {
        $this->setDataset('activity_compliance', json_encode([
            ['label' => 'Environmental Fee', 'fixed_amount' => 20, 'amount_type' => 'fixed', 'status' => 'active'],
        ]));

        $this->assertSame([[
            'label' => 'Environmental Fee',
            'percentage' => 0.0,
            'fixed_amount' => 20.0,
            'amount_type' => 'fixed',
            'status' => 'active',
        ]], Dataset::activityComplianceDefaults());
    }

    #[Test]
    public function paymentGatewayMaskCredentialHandlesEmptyAndShortValues(): void
    {
        $this->assertNull(PaymentGateway::maskCredential(null));
        $this->assertNull(PaymentGateway::maskCredential(''));
        $this->assertSame(str_repeat('•', 6), PaymentGateway::maskCredential('abcdef', 2));
    }

    #[Test]
    public function otpRoutesMailNotificationsToReceiver(): void
    {
        $otp = new Otp(['receiver' => 'otp-user@paec.test']);

        $this->assertSame('otp-user@paec.test', $otp->routeNotificationForMail(null));
    }

    #[Test]
    public function passwordSetupUuidScopeFindsTheMatchingRow(): void
    {
        $wanted = PasswordSetup::create(['email' => 'a@paec.test', 'type' => PasswordSetup::TYPE_SETUP]);
        PasswordSetup::create(['email' => 'b@paec.test', 'type' => PasswordSetup::TYPE_RESET]);

        $this->assertSame(['a@paec.test'], PasswordSetup::uuid($wanted->uuid)->pluck('email')->all());
    }

    #[Test]
    public function uploadUrlFallsBackToRawPathWhenDiskIsNotConfigured(): void
    {
        $upload = new Upload(['disk' => 'missing-disk', 'path' => 'uploads/raw.jpg']);

        $this->assertSame('uploads/raw.jpg', $upload->url);
    }

    #[Test]
    public function uploadSizeHumanFormatsBytesIntoUnits(): void
    {
        $this->assertSame('512 B', (new Upload(['size_bytes' => 512]))->size_human);
        $this->assertSame('1.50 KB', (new Upload(['size_bytes' => 1536]))->size_human);
        $this->assertSame('2.00 MB', (new Upload(['size_bytes' => 2 * 1024 * 1024]))->size_human);
    }

    #[Test]
    public function venueInquiryStatusLabelsForPaidCompletedAndCancelledStates(): void
    {
        $this->assertSame('Fully Paid', VenueInquiry::statusLabel(VenueInquiry::STATUSES['FULLY_PAID']));
        $this->assertSame('Inquiry Submitted', VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['NEW']));
        $this->assertSame('Cancelled', VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['CANCELLED']));
    }
}
