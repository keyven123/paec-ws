<?php

namespace Tests\Feature\Coverage\Requests;

use App\Http\Requests\Organization\CreateOrganizationRequest;
use App\Http\Requests\Organization\OnboardingRegisterRequest;
use App\Http\Requests\Organization\OnboardingRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationRequestsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RequestFixtures;

    #[Test]
    public function createOrganizationReportsEveryMissingRequiredField(): void
    {
        $errors = $this->validateFormRequest(CreateOrganizationRequest::class, ['status' => 'unknown']);

        $this->assertSame([
            'name' => ['Organization name is required.'],
            'representative_name' => ['Organization representative name is required.'],
            'address' => ['Organization address is required.'],
            'contact_number' => ['Organization contact number is required.'],
            'email' => ['Organization email is required.'],
            'bank_name' => ['Organization bank name is required.'],
            'bank_branch' => ['Organization bank branch is required.'],
            'bank_address' => ['Organization bank address is required.'],
            'bank_account_name' => ['Organization bank account name is required.'],
            'bank_account_number' => ['Organization bank account number is required.'],
            'status' => ['Invalid organization status.'],
        ], $errors);
    }

    #[Test]
    public function createOrganizationRejectsADuplicateEmail(): void
    {
        $existing = $this->makeOrganization();

        $errors = $this->validateFormRequest(CreateOrganizationRequest::class, ['email' => $existing->email]);

        $this->assertFormRequestError($errors, 'email');
        $this->assertStringContainsString('taken', $errors['email'][0]);
    }

    #[Test]
    public function onboardingRequiresAKnownOrganizationEmail(): void
    {
        $errors = $this->validateFormRequest(OnboardingRequest::class, ['email' => 'nobody@test.com']);

        $this->assertSame([
            'email' => ['Email address not found.'],
            'secret' => ['Secret is required.'],
        ], $errors);
    }

    #[Test]
    public function onboardingAcceptsAKnownOrganizationEmail(): void
    {
        $organization = $this->makeOrganization();

        $request = $this->assertFormRequestPasses(OnboardingRequest::class, ['email' => $organization->email, 'secret' => 'abc']);

        $this->assertSame(['email' => $organization->email, 'secret' => 'abc'], $request->validated());
    }

    #[Test]
    public function onboardingEndpointReturnsCustomMessagesForInvalidEmails(): void
    {
        $this->postJson('/api/v1/organizer/onboarding', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email' => 'Invalid email address.',
                'secret' => 'Secret is required.',
            ]);
    }

    #[Test]
    public function onboardingRegisterReportsMissingFields(): void
    {
        $errors = $this->validateFormRequest(OnboardingRegisterRequest::class, []);

        $this->assertSame('Secret is required.', $errors['secret'][0]);
        $this->assertSame('First name is required.', $errors['first_name'][0]);
        $this->assertSame('Last name is required.', $errors['last_name'][0]);
        $this->assertSame('Email is required.', $errors['email'][0]);
        $this->assertSame('Password is required.', $errors['password'][0]);
        $this->assertSame('Accepted terms is required.', $errors['accepted_terms'][0]);
        $this->assertSame('Phone number is required.', $errors['phone_number'][0]);
    }

    #[Test]
    public function onboardingRegisterRejectsWeakPasswordsUnacceptedTermsAndBadPhones(): void
    {
        $errors = $this->validateFormRequest(OnboardingRegisterRequest::class, [
            'secret' => 'abc',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'bad-email',
            'password' => 'short',
            'password_confirmation' => 'different',
            'accepted_terms' => false,
            'phone_number' => '12',
        ]);

        $this->assertContains('Invalid email address.', $errors['email']);
        $this->assertContains('Password must be at least 8 characters.', $errors['password']);
        $this->assertContains('Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.', $errors['password']);
        $this->assertContains('Password confirmation does not match.', $errors['password']);
        $this->assertContains('Please accept the terms and conditions.', $errors['accepted_terms']);
        $this->assertContains('Invalid phone number.', $errors['phone_number']);
    }

    #[Test]
    public function onboardingRegisterAcceptsAValidPayload(): void
    {
        $request = $this->assertFormRequestPasses(OnboardingRegisterRequest::class, [
            'secret' => 'abc',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@test.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'accepted_terms' => 1,
            'phone_number' => '09171234567',
        ]);

        $this->assertSame('jane@test.com', $request->validated('email'));
    }

    #[Test]
    public function onboardingRegisterEndpointRejectsAnAdminEmailThatIsAlreadyUsed(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson('/api/v1/organizer/onboarding/register', [
            'secret' => 'abc',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => $admin->email,
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'accepted_terms' => true,
            'phone_number' => '09171234567',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonMissingValidationErrors(['password', 'phone_number', 'accepted_terms']);
    }

    #[Test]
    public function updateOrganizationTurnsBlankBankFieldsIntoNull(): void
    {
        $organization = $this->makeOrganization();

        $request = $this->assertFormRequestPasses(UpdateOrganizationRequest::class, [
            'bank_name' => '',
            'bank_branch' => 'Makati',
        ], ['uuid' => $organization->uuid]);

        $this->assertNull($request->validated('bank_name'));
        $this->assertSame('Makati', $request->validated('bank_branch'));
        $this->assertSame($organization->uuid, $request->validated('uuid'));
    }
}
