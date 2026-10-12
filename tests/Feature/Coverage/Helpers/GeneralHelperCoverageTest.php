<?php

namespace Tests\Feature\Coverage\Helpers;

use App\Helpers\GeneralHelper;
use App\Models\Event;
use App\Models\VenueInquiry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeneralHelperCoverageTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function deleteFileRemovesAnExistingFileAndIgnoresMissingOnes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paec-helper-');
        $this->assertFileExists($path);

        GeneralHelper::deleteFile($path);
        $this->assertFileDoesNotExist($path);

        GeneralHelper::deleteFile($path);
        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function shortContactNumbersAreOnlyTrimmed(): void
    {
        $this->assertSame('12345', GeneralHelper::formatContactNumberToPh('  12345 '));
        $this->assertSame('+639171234567', GeneralHelper::formatContactNumberToPh('09171234567'));
    }

    #[Test]
    public function unsetUnknownKeepsOnlyAcceptedKeysIncludingNulls(): void
    {
        $this->assertSame(
            ['name' => 'VIP', 'price' => null],
            GeneralHelper::unsetUnknown(['name' => 'VIP', 'price' => null, 'hacked' => true], ['name', 'price']),
        );
    }

    #[Test]
    public function soaNumbersArePaddedAfterThePrefixAndYear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $this->assertSame('SOA-20260000042', GeneralHelper::generateSoaNumber(42, 'SOA-'));
        $this->assertSame('20261234567', GeneralHelper::generateSoaNumber(1234567));
    }

    #[Test]
    public function formatWithoutRoundingTruncatesInsteadOfRounding(): void
    {
        $this->assertSame('12.34', GeneralHelper::formatWithoutRounding(12.349));
        $this->assertSame('7.1', GeneralHelper::formatWithoutRounding(7.19, 1));
        $this->assertSame('5', GeneralHelper::formatWithoutRounding(5.9, 0));
    }

    #[Test]
    public function uploadUrlComesFromTheGivenDisk(): void
    {
        Storage::fake('uploads-test');

        $this->assertStringEndsWith(
            'events/banner.png',
            GeneralHelper::getUploadUrl('uploads-test', 'events/banner.png'),
        );
    }

    #[Test]
    public function morphMapKeysAreResolvedForRegisteredModelsOnly(): void
    {
        $this->assertNull(GeneralHelper::getMorphMapKey(null));
        $this->assertNull(GeneralHelper::getMorphMapKey(''));
        $this->assertSame('event', GeneralHelper::getMorphMapKey(Event::class));
        $this->assertSame('venue_inquiry', GeneralHelper::getMorphMapKey(VenueInquiry::class));
        $this->assertSame('App\\Models\\Category', GeneralHelper::getMorphMapKey('App\\Models\\Category'));
    }

    #[Test]
    #[DataProvider('payMongoPayloads')]
    public function payMongoReferenceNumbersPreferProviderThenPaymentThenSourceId(mixed $paymentData, string $expected): void
    {
        $this->assertSame($expected, GeneralHelper::getPaymentTypeRefNo('paymongo', $paymentData));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function payMongoPayloads(): array
    {
        $payment = fn (array $source, ?string $paymentId = null) => ['data' => ['attributes' => ['payments' => [
            array_filter(['id' => $paymentId, 'attributes' => ['source' => $source]]),
        ]]]];

        return [
            'provider id from json string' => [json_encode($payment(['provider_id' => 'GCASH-123', 'id' => 'src_1'], 'pay_1')), 'GCASH-123'],
            'payment id when no provider id' => [$payment(['id' => 'src_1'], 'pay_1'), 'pay_1'],
            'source id as last resort' => [$payment(['id' => 'src_1']), 'src_1'],
            'no payments' => [['data' => ['attributes' => ['payments' => []]]], 'N/A'],
            'unparsable json' => ['not json', 'N/A'],
            'missing payload' => [null, 'N/A'],
        ];
    }

    #[Test]
    public function payPalReferenceNumberIsTheCaptureId(): void
    {
        $data = ['purchase_units' => [['payments' => ['captures' => [['id' => 'CAPTURE-9']]]]]];

        $this->assertSame('CAPTURE-9', GeneralHelper::getPaymentTypeRefNo('paypal', json_encode($data)));
        $this->assertSame('N/A', GeneralHelper::getPaymentTypeRefNo('paypal', []));
    }

    #[Test]
    #[DataProvider('offlinePaymentTypes')]
    public function offlinePaymentTypesMapToFixedLabels(?string $type, string $expected): void
    {
        $this->assertSame($expected, GeneralHelper::getPaymentTypeRefNo($type));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function offlinePaymentTypes(): array
    {
        return [
            'cash' => ['cash', 'CASH'],
            'bank transfer' => ['bank_transfer', 'BANK_TRANSFER'],
            'credit card' => ['credit_card', 'CREDIT_CARD'],
            'debit card' => ['debit_card', 'DEBIT_CARD'],
            'unknown' => ['crypto', 'N/A'],
            'none' => [null, 'N/A'],
        ];
    }
}
