<?php

namespace Tests\Feature\Coverage\Requests;

use App\Constants\GeneralConstants;
use App\Http\Requests\Organizer\OrganizerAccountingRequest;
use App\Http\Requests\Organizer\OrganizerCreateAdminUserRequest;
use App\Http\Requests\Organizer\OrganizerCreateCustomerRequest;
use App\Http\Requests\Organizer\OrganizerListAdminUserRequest;
use App\Http\Requests\Organizer\OrganizerPnLRequest;
use App\Http\Requests\Organizer\OrganizerUpdateAdminUserRequest;
use App\Http\Requests\Organizer\OrganizerUpdateRoleRequest;
use App\Http\Requests\Organizer\StoreOrganizerMerchantPayoutRequest;
use App\Http\Requests\Organizer\UpdateOrganizerProfileRequest;
use App\Models\OrganizationBank;
use App\Models\Permission;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use App\Services\Platform\AdminPlatformPnLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerRequestsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RequestFixtures;

    private const UNKNOWN_UUID = '00000000-0000-4000-8000-000000000000';

    #[Test]
    public function accountingRestrictsEventsToTheAdminOrganization(): void
    {
        $organization = $this->makeOrganization();
        $this->actingAs($this->makeAdmin(['organization_uuid' => $organization->uuid]), 'admin');
        $otherEvent = $this->makeEvent(['organization_uuid' => $this->makeOrganization()->uuid]);

        $errors = $this->validateFormRequest(OrganizerAccountingRequest::class, ['event_uuid' => $otherEvent->uuid], method: 'GET');

        $this->assertFormRequestError($errors, 'event_uuid');
    }

    #[Test]
    public function accountingExposesTheValidatedEventUuid(): void
    {
        $organization = $this->makeOrganization();
        $this->actingAs($this->makeAdmin(['organization_uuid' => $organization->uuid]), 'admin');
        $event = $this->makeEvent(['organization_uuid' => $organization->uuid]);

        $request = $this->assertFormRequestPasses(OrganizerAccountingRequest::class, ['event_uuid' => $event->uuid], method: 'GET');

        $this->assertTrue($request->authorize());
        $this->assertSame($event->uuid, $request->eventUuid());
    }

    #[Test]
    public function accountingWithoutAnEventOrOrganizationScopeReturnsNull(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin');
        $anyEvent = $this->makeEvent(['organization_uuid' => $this->makeOrganization()->uuid]);

        $this->assertNull($this->assertFormRequestPasses(OrganizerAccountingRequest::class, [], method: 'GET')->eventUuid());
        $this->assertSame(
            $anyEvent->uuid,
            $this->assertFormRequestPasses(OrganizerAccountingRequest::class, ['event_uuid' => $anyEvent->uuid], method: 'GET')->eventUuid(),
        );
    }

    #[Test]
    public function pnlRequiresBothDatesForCustomPeriods(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin');

        $errors = $this->validateFormRequest(OrganizerPnLRequest::class, ['period' => AdminPlatformPnLService::PERIOD_CUSTOM], method: 'GET');

        $this->assertFormRequestError($errors, 'custom_start');
        $this->assertFormRequestError($errors, 'custom_end');
    }

    #[Test]
    public function pnlRejectsUnknownPeriodsReversedRangesAndForeignEvents(): void
    {
        $organization = $this->makeOrganization();
        $this->actingAs($this->makeAdmin(['organization_uuid' => $organization->uuid]), 'admin');
        $foreignEvent = $this->makeEvent(['organization_uuid' => $this->makeOrganization()->uuid]);

        $errors = $this->validateFormRequest(OrganizerPnLRequest::class, [
            'period' => 'fortnightly',
            'custom_start' => '2030-02-01',
            'custom_end' => '2030-01-01',
            'event_uuid' => $foreignEvent->uuid,
        ], method: 'GET');

        $this->assertFormRequestError($errors, 'period');
        $this->assertFormRequestError($errors, 'custom_end');
        $this->assertFormRequestError($errors, 'event_uuid');
        $this->assertArrayNotHasKey('custom_start', $errors);
    }

    #[Test]
    public function pnlAcceptsACustomRangeForAnOwnEvent(): void
    {
        $organization = $this->makeOrganization();
        $this->actingAs($this->makeAdmin(['organization_uuid' => $organization->uuid]), 'admin');
        $event = $this->makeEvent(['organization_uuid' => $organization->uuid]);

        $request = $this->assertFormRequestPasses(OrganizerPnLRequest::class, [
            'period' => AdminPlatformPnLService::PERIOD_CUSTOM,
            'custom_start' => '2030-01-01',
            'custom_end' => '2030-01-31',
            'event_uuid' => $event->uuid,
        ], method: 'GET');

        $this->assertTrue($request->authorize());
        $this->assertSame('2030-01-31', $request->validated('custom_end'));
    }

    #[Test]
    public function pnlWithoutOrganizationScopeAcceptsAnyEvent(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin');
        $event = $this->makeEvent(['organization_uuid' => $this->makeOrganization()->uuid]);

        $request = $this->assertFormRequestPasses(OrganizerPnLRequest::class, ['event_uuid' => $event->uuid], method: 'GET');

        $this->assertSame($event->uuid, $request->validated('event_uuid'));
    }

    #[Test]
    public function createStaffRejectsTheCustomerRoleAndDuplicateEmails(): void
    {
        $existing = $this->makeAdmin();

        $errors = $this->validateFormRequest(OrganizerCreateAdminUserRequest::class, [
            'role_uuid' => $this->customerRole()->uuid,
            'email' => $existing->email,
            'password' => 'short',
            'status' => 'archived',
        ]);

        $this->assertFormRequestError($errors, 'role_uuid', 'The selected role must be a valid staff role.');
        $this->assertFormRequestError($errors, 'email');
        $this->assertFormRequestError($errors, 'password');
        $this->assertFormRequestError($errors, 'first_name');
        $this->assertFormRequestError($errors, 'last_name');
        $this->assertFormRequestError($errors, 'status');
    }

    #[Test]
    public function createStaffAcceptsAStaffRole(): void
    {
        $role = $this->makeRole('cashier', false);

        $request = $this->assertFormRequestPasses(OrganizerCreateAdminUserRequest::class, [
            'role_uuid' => $role->uuid,
            'email' => 'cashier@test.com',
            'password' => 'password123',
            'first_name' => 'Cash',
            'last_name' => 'Ier',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->assertSame($role->uuid, $request->validated('role_uuid'));
    }

    #[Test]
    public function createCustomerValidatesRequiredFieldsAndUniqueness(): void
    {
        $existing = $this->makeCustomer();

        $errors = $this->validateFormRequest(OrganizerCreateCustomerRequest::class, [
            'email' => $existing->email,
            'password' => 'password123',
            'password_confirmation' => 'mismatch',
            'birth_date' => 'not-a-date',
            'status' => 'archived',
        ]);

        $this->assertSame(
            ['email', 'password', 'first_name', 'last_name', 'birth_date', 'status'],
            array_keys($errors),
        );
    }

    #[Test]
    public function createCustomerAcceptsAFullProfile(): void
    {
        $request = $this->assertFormRequestPasses(OrganizerCreateCustomerRequest::class, [
            'email' => 'walkin@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'first_name' => 'Walk',
            'middle_name' => 'In',
            'last_name' => 'Customer',
            'phone_number' => '09171234567',
            'birth_date' => '1990-01-01',
            'address_line_1' => 'Line 1',
            'address_line_2' => 'Line 2',
            'city' => 'Manila',
            'region' => 'NCR',
            'postal_code' => '1000',
            'country' => 'PH',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->assertSame('walkin@test.com', $request->validated('email'));
        $this->assertSame('Manila', $request->validated('city'));
    }

    #[Test]
    public function listStaffValidatesFilters(): void
    {
        $errors = $this->validateFormRequest(OrganizerListAdminUserRequest::class, [
            'per_page' => 'many',
            'page' => 'first',
            'status' => 'archived',
            'role_uuid' => self::UNKNOWN_UUID,
        ], method: 'GET');

        $this->assertSame(['per_page', 'page', 'status', 'role_uuid'], array_keys($errors));
    }

    #[Test]
    public function listStaffAcceptsValidFilters(): void
    {
        $role = $this->makeRole('cashier', false);

        $request = $this->assertFormRequestPasses(OrganizerListAdminUserRequest::class, [
            'q' => 'jane',
            'sort' => '-created_at',
            'per_page' => 10,
            'page' => 2,
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
            'role_uuid' => $role->uuid,
        ], method: 'GET');

        $this->assertSame($role->uuid, $request->validated('role_uuid'));
    }

    #[Test]
    public function updateStaffRejectsCustomerRoleAndAnotherAdminsEmail(): void
    {
        $staff = $this->makeAdmin();
        $other = $this->makeAdmin();

        $errors = $this->validateFormRequest(OrganizerUpdateAdminUserRequest::class, [
            'role_uuid' => $this->customerRole()->uuid,
            'email' => $other->email,
        ], ['uuid' => $staff->uuid]);

        $this->assertFormRequestError($errors, 'role_uuid', 'The selected role must be a valid staff role.');
        $this->assertFormRequestError($errors, 'email');
        $this->assertArrayNotHasKey('uuid', $errors);
    }

    #[Test]
    public function updateStaffAllowsKeepingTheirOwnEmail(): void
    {
        $staff = $this->makeAdmin();
        $role = $this->makeRole('cashier', false);

        $request = $this->assertFormRequestPasses(OrganizerUpdateAdminUserRequest::class, [
            'role_uuid' => $role->uuid,
            'email' => $staff->email,
            'first_name' => 'Renamed',
        ], ['uuid' => $staff->uuid]);

        $this->assertSame($staff->uuid, $request->validated('uuid'));
        $this->assertSame('Renamed', $request->validated('first_name'));
    }

    #[Test]
    public function updateStaffRequiresAnExistingAdminUser(): void
    {
        $errors = $this->validateFormRequest(OrganizerUpdateAdminUserRequest::class, ['first_name' => 'Ghost'], ['uuid' => self::UNKNOWN_UUID]);

        $this->assertSame(['uuid'], array_keys($errors));
    }

    #[Test]
    public function updateRoleRejectsACodeAlreadyUsedInTheSameOrganization(): void
    {
        $organization = $this->makeOrganization();
        $this->actingAs($this->makeAdmin(['organization_uuid' => $organization->uuid]), 'admin');
        $role = $this->makeRole('cashier', false, $organization->uuid);
        $this->makeRole('usher', false, $organization->uuid);

        $errors = $this->validateFormRequest(OrganizerUpdateRoleRequest::class, [
            'name' => 'Cashier',
            'code' => 'usher',
            'permissions' => [self::UNKNOWN_UUID],
            'permission_grants' => [['code' => 'missing-permission']],
        ], ['uuid' => $role->uuid]);

        $this->assertFormRequestError($errors, 'code');
        $this->assertFormRequestError($errors, 'permissions.0');
        $this->assertFormRequestError($errors, 'permission_grants.0.code');
        $this->assertFormRequestError($errors, 'permission_grants.0.available_access');
    }

    #[Test]
    public function updateRoleAllowsCodesUsedByOtherOrganizationsAndItsOwnCode(): void
    {
        $organization = $this->makeOrganization();
        $this->actingAs($this->makeAdmin(['organization_uuid' => $organization->uuid]), 'admin');
        $role = $this->makeRole('cashier', false, $organization->uuid);
        $this->makeRole('usher', false, $this->makeOrganization()->uuid);
        $permission = Permission::create(['name' => 'Tickets', 'code' => 'tickets', 'available_access' => ['view']]);

        $this->assertFormRequestPasses(OrganizerUpdateRoleRequest::class, [
            'name' => 'Usher',
            'code' => 'usher',
            'permissions' => [$permission->uuid],
            'permission_grants' => [['code' => 'tickets', 'available_access' => 'view']],
        ], ['uuid' => $role->uuid]);

        $request = $this->assertFormRequestPasses(OrganizerUpdateRoleRequest::class, ['name' => 'Cashier', 'code' => 'cashier'], ['uuid' => $role->uuid]);
        $this->assertSame('cashier', $request->validated('code'));
    }

    #[Test]
    public function updateRoleWithoutOrganizationScopeChecksCodesGlobally(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin');
        $role = $this->makeRole('cashier', false);
        $this->makeRole('usher', false, $this->makeOrganization()->uuid);

        $errors = $this->validateFormRequest(OrganizerUpdateRoleRequest::class, ['name' => 'Usher', 'code' => 'usher'], ['uuid' => $role->uuid]);

        $this->assertSame(['code'], array_keys($errors));
    }

    #[Test]
    public function merchantPayoutSkipsTheBalanceCheckForAdminsWithoutAnOrganization(): void
    {
        $this->mock(OrganizerAccountingBalanceService::class)->shouldNotReceive('availableForPayout');
        $this->actingAs($this->makeAdmin(), 'admin');
        $organization = $this->makeOrganization();
        $event = $this->makeEvent(['organization_uuid' => $organization->uuid]);
        $bank = OrganizationBank::query()->where('organization_uuid', $organization->uuid)->firstOrFail();

        $request = $this->assertFormRequestPasses(StoreOrganizerMerchantPayoutRequest::class, [
            'event_uuid' => $event->uuid,
            'amount' => 999999,
            'organization_bank_uuid' => $bank->uuid,
        ]);

        $this->assertSame(999999, $request->validated('amount'));
    }

    #[Test]
    public function merchantPayoutSkipsTheBalanceCheckWhenTheEventIsNotAString(): void
    {
        $this->mock(OrganizerAccountingBalanceService::class)->shouldNotReceive('availableForPayout');
        $organization = $this->makeOrganization();
        $this->actingAs($this->makeAdmin(['organization_uuid' => $organization->uuid]), 'admin');

        $errors = $this->validateFormRequest(StoreOrganizerMerchantPayoutRequest::class, [
            'event_uuid' => ['not', 'a', 'string'],
            'amount' => 100,
        ]);

        $this->assertFormRequestError($errors, 'event_uuid');
        $this->assertFormRequestError($errors, 'organization_bank_uuid', 'Select a preferred bank account.');
        $this->assertArrayNotHasKey('amount', $errors);
    }

    #[Test]
    public function organizerProfileRequiresNamesAndPhone(): void
    {
        $errors = $this->validateFormRequest(UpdateOrganizerProfileRequest::class, []);

        $this->assertSame([
            'first_name' => ['First name is required.'],
            'last_name' => ['Last name is required.'],
            'phone_number' => ['Phone number is required.'],
        ], $errors);
    }

    #[Test]
    public function organizerProfileAcceptsAValidPayload(): void
    {
        $request = $this->assertFormRequestPasses(UpdateOrganizerProfileRequest::class, [
            'first_name' => 'Org',
            'last_name' => 'Anizer',
            'phone_number' => '+63 917 123 4567',
        ]);

        $this->assertSame(['first_name' => 'Org', 'last_name' => 'Anizer', 'phone_number' => '+63 917 123 4567'], $request->validated());
    }
}
