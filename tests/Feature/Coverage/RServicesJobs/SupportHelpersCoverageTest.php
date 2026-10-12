<?php

namespace Tests\Feature\Coverage\RServicesJobs;

use App\Services\Chat\ContactInfoFilter;
use App\Support\EventTicketCodeGenerator;
use App\Support\OrganizationPaymentMethods;
use App\Support\VenueListingDefaults;
use App\Support\VenueListingPackageHelper;
use App\Support\VenueListingPublicDetailMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\RServicesJobs\Concerns\CreatesRServicesJobsFixtures;
use Tests\TestCase;

class SupportHelpersCoverageTest extends TestCase
{
    use CreatesRServicesJobsFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();

        parent::tearDown();
    }

    #[Test]
    public function eventTicketCodeGeneratorFallsBackToHexSuffixAfterRepeatedCollisions(): void
    {
        $event = $this->createRsjEvent();
        $this->createRsjEventTicket($event, ['code' => 'TKT-AAAA']);

        Str::createRandomStringsUsing(fn () => 'aaaa');

        $code = EventTicketCodeGenerator::generate($event->uuid);

        $this->assertMatchesRegularExpression('/^TKT-[0-9A-F]{8}$/', $code);
        $this->assertNotSame('TKT-AAAA', $code);
    }

    #[Test]
    public function eventTicketCodeGeneratorUsesSlugOfNameWhenNoCollision(): void
    {
        $event = $this->createRsjEvent();

        Str::createRandomStringsUsing(fn () => 'bcde');

        $this->assertSame('VIPPASS-BCDE', EventTicketCodeGenerator::generate($event->uuid, 'VIP pass!'));
    }

    #[Test]
    public function organizationPaymentMethodsNormalizeSkipsNonArrayAndUnknownEntries(): void
    {
        $normalized = OrganizationPaymentMethods::normalize([
            'not-an-array',
            ['name' => 'bitcoin', 'value' => true],
            ['value' => true],
            ['name' => 'gcash', 'value' => true],
            ['name' => 'paypal', 'value' => 1],
        ]);

        $byName = collect($normalized)->keyBy('name');

        $this->assertCount(count(OrganizationPaymentMethods::allKeys()), $normalized);
        $this->assertFalse($byName->has('bitcoin'));
        $this->assertTrue($byName['gcash']['value']);
        $this->assertSame('paymongo', $byName['gcash']['provider']);
        $this->assertTrue($byName['paypal']['value']);
        $this->assertSame('paypal', $byName['paypal']['provider']);
        $this->assertFalse($byName['card']['value']);
    }

    #[Test]
    public function venueListingDefaultsParsesCapacityRangeLabels(): void
    {
        $this->assertSame([100, 300], VenueListingDefaults::parseCapacityBounds('100 - 300 guests'));
        $this->assertSame([null, null], VenueListingDefaults::parseCapacityBounds('Up to 300'));
        $this->assertSame([null, null], VenueListingDefaults::parseCapacityBounds(null));
    }

    #[Test]
    public function venueListingDefaultsFillsCapacityBoundsFromLabel(): void
    {
        $payload = VenueListingDefaults::applyMissing(['capacity_label' => '50-200 pax']);

        $this->assertSame(50, $payload['capacity_min']);
        $this->assertSame(200, $payload['capacity_max']);
        $this->assertSame('24 hrs', $payload['responds_in']);
        $this->assertTrue($payload['verified']);
    }

    #[Test]
    public function venueListingDefaultsLeavesCapacityUnsetWhenLabelHasNoRange(): void
    {
        $payload = VenueListingDefaults::applyMissing(['capacity_label' => 'Flexible']);

        $this->assertArrayNotHasKey('capacity_min', $payload);
        $this->assertArrayNotHasKey('capacity_max', $payload);
    }

    #[Test]
    public function venueListingPackageHelperReturnsEmptyListForEmptyInput(): void
    {
        $this->assertSame([], VenueListingPackageHelper::normalizeList(null));
        $this->assertSame([], VenueListingPackageHelper::normalizeList([]));
    }

    #[Test]
    public function venueListingPackageHelperSkipsNonArrayItemsAndGeneratesIds(): void
    {
        $list = VenueListingPackageHelper::normalizeList([
            'garbage',
            ['label' => 'Night Owl', 'start_time' => '0:15', 'end_time' => '4:00', 'price_from' => '1500'],
        ]);

        $this->assertCount(1, $list);
        $this->assertMatchesRegularExpression('/^night-owl-[A-Za-z0-9]{6}$/', $list[0]['id']);
        $this->assertSame('00:15', $list[0]['start_time']);
        $this->assertSame('04:00', $list[0]['end_time']);
        $this->assertFalse($list[0]['crosses_midnight']);
        $this->assertSame(1500.0, $list[0]['priceFrom']);
        $this->assertSame('12:15 AM – 4:00 AM', $list[0]['time_label']);
        $this->assertSame(1, $list[0]['sort_order']);
    }

    #[Test]
    public function venueListingPackageHelperRejectsInvalidTimes(): void
    {
        try {
            VenueListingPackageHelper::normalizeTime('9am');
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['Each package must use a valid start and end time (HH:MM).'],
                $e->errors()['packages'],
            );
        }
    }

    #[Test]
    public function venueListingPackageHelperFormatsMidnightAsTwelveAm(): void
    {
        $this->assertSame('12:00 AM', VenueListingPackageHelper::formatTime12h('00:00'));
        $this->assertSame('12:30 PM', VenueListingPackageHelper::formatTime12h('12:30'));
    }

    #[Test]
    public function venueListingPublicDetailMaskerPassesThroughNullAndEmptyText(): void
    {
        $masker = app(VenueListingPublicDetailMasker::class);

        $this->assertNull($masker->mask(null));
        $this->assertSame('', $masker->mask(''));
        $this->assertSame(['', 'Call [hidden]'], $masker->maskStringList(['', 'Call 0917 123 4567']));
    }

    #[Test]
    public function contactInfoFilterReturnsBodyUnchangedWhenMaskingDisabled(): void
    {
        config(['chat.mask_contact_info' => false]);
        $filter = new ContactInfoFilter();

        $this->assertSame('mail me at a@b.com', $filter->mask('mail me at a@b.com'));
        $this->assertFalse($filter->containsContactInfo('mail me at a@b.com'));
    }

    #[Test]
    public function contactInfoFilterDetectsContactInfo(): void
    {
        config(['chat.mask_contact_info' => true, 'chat.mask_placeholder' => '[hidden]']);
        $filter = new ContactInfoFilter();

        $this->assertTrue($filter->containsContactInfo('visit www.example.com'));
        $this->assertFalse($filter->containsContactInfo('we are 120 guests'));
    }
}
