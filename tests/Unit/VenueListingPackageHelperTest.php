<?php

namespace Tests\Unit;

use App\Support\VenueListingPackageHelper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueListingPackageHelperTest extends TestCase
{
    #[Test]
    public function it_normalizes_package_with_time_label(): void
    {
        $normalized = VenueListingPackageHelper::normalizeItem([
            'id' => 'full-day',
            'label' => 'Full-day (8 hrs)',
            'priceFrom' => 45000,
            'note' => 'Full-day package · final rate varies by date & setup',
            'start_time' => '07:00',
            'end_time' => '21:00',
        ]);

        $this->assertSame('full-day', $normalized['id']);
        $this->assertSame(45000.0, $normalized['priceFrom']);
        $this->assertSame('7:00 AM – 9:00 PM', $normalized['time_label']);
        $this->assertFalse($normalized['crosses_midnight']);
    }

    #[Test]
    public function it_infers_crosses_midnight_for_evening_blocks(): void
    {
        $normalized = VenueListingPackageHelper::normalizeItem([
            'id' => 'half-day-afternoon',
            'label' => 'Half day (Afternoon)',
            'start_time' => '17:00',
            'end_time' => '01:00',
        ]);

        $this->assertTrue($normalized['crosses_midnight']);
        $this->assertSame('5:00 PM – 1:00 AM (next day)', $normalized['time_label']);
    }

    #[Test]
    public function it_returns_default_base_packages(): void
    {
        $packages = VenueListingPackageHelper::basePackages(45000);

        $this->assertCount(3, $packages);
        $this->assertSame('Half day (Morning)', $packages[0]['label']);
        $this->assertSame('Full-day (8 hrs)', $packages[2]['label']);
        $this->assertSame(45000.0, $packages[2]['priceFrom']);
    }
}
