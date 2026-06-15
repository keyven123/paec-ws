<?php

namespace Tests\Unit;

use App\Support\OrganizationPaymentMethods;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationPaymentMethodsTest extends TestCase
{
    #[Test]
    public function checkoutPaymongoApiTypesReturnsLegacyDefaultsWhenStorageIsNull(): void
    {
        $types = OrganizationPaymentMethods::checkoutPaymongoApiTypes(null);

        $this->assertSame(
            OrganizationPaymentMethods::defaultPaymongoCheckoutApiTypes(),
            $types,
        );
    }

    #[Test]
    public function checkoutPaymongoApiTypesIncludesOnlyEnabledOrganizationKeys(): void
    {
        $stored = [
            ['name' => 'gcash', 'value' => true, 'provider' => 'paymongo'],
        ];

        $this->assertSame(['gcash'], OrganizationPaymentMethods::checkoutPaymongoApiTypes($stored));
    }

    #[Test]
    public function brankasOrganizationKeyExpandsToPaymongoBankRails(): void
    {
        $stored = [
            ['name' => 'brankas', 'value' => true, 'provider' => 'paymongo'],
        ];

        $types = OrganizationPaymentMethods::checkoutPaymongoApiTypes($stored);
        sort($types);

        $this->assertSame(
            ['brankas_bdo', 'brankas_landbank', 'brankas_metrobank'],
            $types,
        );
    }

    #[Test]
    public function dobAndDobFixedMinimumExpandToDobAndDobUbp(): void
    {
        $storedDob = [['name' => 'dob', 'value' => true, 'provider' => 'paymongo']];
        $storedFixed = [['name' => 'dob_fixed_minimum', 'value' => true, 'provider' => 'paymongo']];

        foreach ([$storedDob, $storedFixed] as $stored) {
            $types = OrganizationPaymentMethods::checkoutPaymongoApiTypes($stored);
            sort($types);
            $this->assertSame(['dob', 'dob_ubp'], $types);
        }
    }

    #[Test]
    public function checkoutPaymongoApiTypesReturnsEmptyWhenNoPaymongoMethodIsEnabled(): void
    {
        $stored = [
            ['name' => 'paypal', 'value' => true, 'provider' => 'paypal'],
        ];

        $this->assertSame([], OrganizationPaymentMethods::checkoutPaymongoApiTypes($stored));
    }

    #[Test]
    public function checkoutPaypalAllowedIsTrueWhenColumnUnset(): void
    {
        $this->assertTrue(OrganizationPaymentMethods::checkoutPaypalAllowed(null));
    }

    #[Test]
    public function checkoutPaypalAllowedReflectsPaypalToggle(): void
    {
        $this->assertFalse(OrganizationPaymentMethods::checkoutPaypalAllowed([
            ['name' => 'paypal', 'value' => false, 'provider' => 'paypal'],
        ]));

        $this->assertTrue(OrganizationPaymentMethods::checkoutPaypalAllowed([
            ['name' => 'paypal', 'value' => true, 'provider' => 'paypal'],
        ]));
    }

    #[Test]
    public function paymongoApiTypesForOrganizationKeyMapsDirectWalletNames(): void
    {
        foreach (['qrph', 'card', 'gcash', 'grab_pay', 'shopee_pay', 'billease', 'paymaya'] as $name) {
            $this->assertSame([$name], OrganizationPaymentMethods::paymongoApiTypesForOrganizationKey($name));
        }
    }
}
