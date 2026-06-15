<?php

namespace Tests\Unit;

use App\Support\VenueListingPublicDetailMasker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueListingPublicDetailMaskerTest extends TestCase
{
    #[Test]
    public function it_masks_emails_and_phone_numbers_in_text(): void
    {
        $masker = app(VenueListingPublicDetailMasker::class);

        $masked = $masker->mask('Call venue@example.com or 0917 123 4567 for details.');

        $this->assertStringNotContainsString('venue@example.com', $masked);
        $this->assertStringNotContainsString('0917', $masked);
        $this->assertStringContainsString('[hidden]', $masked);
    }

    #[Test]
    public function it_masks_contact_info_in_nested_spec_and_package_fields(): void
    {
        $masker = app(VenueListingPublicDetailMasker::class);

        $specs = $masker->maskSpecs([
            ['label' => 'Coordinator', 'value' => 'Jane · jane@venue.com'],
        ]);

        $packages = $masker->maskPackages([
            [
                'id' => 'full-day',
                'label' => 'Full-day',
                'note' => 'Text us at +63 917 555 1234',
            ],
        ]);

        $this->assertStringContainsString('[hidden]', $specs[0]['value']);
        $this->assertStringNotContainsString('jane@venue.com', $specs[0]['value']);
        $this->assertStringContainsString('[hidden]', $packages[0]['note']);
        $this->assertStringNotContainsString('917', $packages[0]['note']);
    }
}
