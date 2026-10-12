<?php

namespace Tests\Feature\Coverage\Requests;

use App\Constants\GeneralConstants;
use App\Http\Requests\Admin\UpdatePaymentGatewayRateSettingRequest;
use App\Http\Requests\AdminUser\UpdateAdminUserRequest;
use App\Http\Requests\PromoCode\UpdatePromoCodeRequest;
use App\Models\PromoCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminRequestsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RequestFixtures;

    #[Test]
    public function paymentGatewayRatesRequireBothProviderPayloads(): void
    {
        $errors = $this->validateFormRequest(UpdatePaymentGatewayRateSettingRequest::class, []);

        $this->assertFormRequestError($errors, 'paymongo', 'Paymongo rates payload is required.');
        $this->assertFormRequestError($errors, 'paypal', 'PayPal rates payload is required.');
    }

    #[Test]
    public function paymentGatewayRatesRejectSimpleMethodPercentagesAboveOneHundred(): void
    {
        $errors = $this->validateFormRequest(UpdatePaymentGatewayRateSettingRequest::class, [
            'paymongo' => ['gcash' => 150, 'qrph' => 1.5, 'dob' => ['percentage' => 101, 'fixed_minimum' => 15]],
            'paypal' => ['paypal_fee' => 4.4, 'additional_fee' => -1],
        ]);

        $this->assertFormRequestError($errors, 'paymongo.gcash');
        $this->assertFormRequestError($errors, 'paymongo.dob.percentage');
        $this->assertFormRequestError($errors, 'paypal.additional_fee');
        $this->assertArrayNotHasKey('paymongo.qrph', $errors);
        $this->assertArrayNotHasKey('paypal.paypal_fee', $errors);
    }

    #[Test]
    public function paymentGatewayRatesAcceptAValidPayload(): void
    {
        $request = $this->assertFormRequestPasses(UpdatePaymentGatewayRateSettingRequest::class, [
            'paymongo' => [
                'qrph' => 1.5, 'card' => 3.5, 'gcash' => 2.5, 'grab_pay' => 2.2, 'shopee_pay' => 2,
                'billease' => 1.5, 'paymaya' => 2, 'brankas' => 1,
                'dob' => ['percentage' => 1, 'fixed_minimum' => 15],
            ],
            'paypal' => ['paypal_fee' => 4.4, 'additional_fee' => 15],
        ]);

        $this->assertSame(2.5, $request->validated('paymongo.gcash'));
        $this->assertSame(15, $request->validated('paypal.additional_fee'));
    }

    #[Test]
    public function updateAdminUserRejectsTheCustomerRole(): void
    {
        $admin = $this->makeAdmin();

        $errors = $this->validateFormRequest(UpdateAdminUserRequest::class, [
            'role_uuid' => $this->customerRole()->uuid,
        ], ['uuid' => $admin->uuid]);

        $this->assertFormRequestError($errors, 'role_uuid', 'The selected role must be a valid admin role (not customer).');
    }

    #[Test]
    public function updateAdminUserAcceptsAnAdminRole(): void
    {
        $admin = $this->makeAdmin();
        $role = $this->makeRole('ops_admin');

        $request = $this->assertFormRequestPasses(UpdateAdminUserRequest::class, [
            'role_uuid' => $role->uuid,
            'email' => $admin->email,
        ], ['uuid' => $admin->uuid]);

        $this->assertSame($admin->uuid, $request->validated('uuid'));
        $this->assertSame($role->uuid, $request->validated('role_uuid'));
    }

    #[Test]
    public function updatePromoCodeRejectsPercentageAboveOneHundredUsingStoredDiscountType(): void
    {
        $promo = $this->makePromoCode(GeneralConstants::DISCOUNT_TYPES['PERCENTAGE']);

        $errors = $this->validateFormRequest(UpdatePromoCodeRequest::class, [
            'discount_value' => 150,
        ], ['uuid' => $promo->uuid]);

        $this->assertFormRequestError($errors, 'discount_value', 'The discount value cannot exceed 100 for percentage discounts.');
    }

    #[Test]
    public function updatePromoCodeAllowsLargeValuesForAmountDiscounts(): void
    {
        $promo = $this->makePromoCode(GeneralConstants::DISCOUNT_TYPES['AMOUNT']);

        $request = $this->assertFormRequestPasses(UpdatePromoCodeRequest::class, [
            'discount_value' => 150,
        ], ['uuid' => $promo->uuid]);

        $this->assertSame(150, $request->validated('discount_value'));
    }

    private function makePromoCode(string $discountType): PromoCode
    {
        return PromoCode::create([
            'organization_uuid' => $this->makeOrganization()->uuid,
            'code' => 'PROMO' . random_int(1000, 9999),
            'discount_type' => $discountType,
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }
}
