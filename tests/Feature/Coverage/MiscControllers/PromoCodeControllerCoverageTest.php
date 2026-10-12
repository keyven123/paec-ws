<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Constants\GeneralConstants;
use App\Exceptions\NoPromoCodeFoundException;
use App\Exceptions\UnauthorizedException;
use App\Http\Controllers\PromoCodeController;
use App\Http\Repositories\PromoCodeRepository;
use App\Http\Requests\PromoCode\PublicShowPromoCodeRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PromoCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PromoCodeControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private Organization $organization;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Promo Org',
            'representative_first_name' => 'Promo',
            'representative_last_name' => 'Owner',
            'email' => 'promo-org@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
        $this->event = $this->createEvent(['organization_uuid' => $this->organization->uuid]);
    }

    private function makePromo(array $attributes = []): PromoCode
    {
        return PromoCode::create(array_merge([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'SAVE10',
            'description' => 'Ten percent off',
            'activityable_type' => Event::class,
            'activityable_id' => $this->event->uuid,
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }

    private function validate(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/public/promo-codes/{$code}?event_uuid={$this->event->uuid}");
    }

    private function customerHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . auth('api')->login($this->createCustomer())];
    }

    private function guestShowRequest(string $path): PublicShowPromoCodeRequest
    {
        $request = PublicShowPromoCodeRequest::createFrom(
            Request::create($path, 'GET', ['event_uuid' => $this->event->uuid])
        );
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    #[Test]
    public function validateForEventRejectsExpiredPromo(): void
    {
        $this->makePromo(['usable_from' => now()->subMonth(), 'usable_to' => now()->subDays(2)]);

        $this->validate('save10')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Promo code has expired']);
    }

    #[Test]
    public function validateForEventRejectsPromoThatHasNotStarted(): void
    {
        $this->makePromo(['usable_from' => now()->addDays(2), 'usable_to' => now()->addMonth()]);

        $this->validate('SAVE10')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Promo code is not active yet']);
    }

    #[Test]
    public function validateForEventExplainsPromoBelongsToAnotherActivity(): void
    {
        $this->makePromo(['activityable_id' => $this->createEvent()->uuid]);

        $this->validate('SAVE10')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Promo code is not valid for this activity']);
    }

    #[Test]
    public function validateForEventRejectsPromoAtMaximumUsage(): void
    {
        $this->makePromo(['is_unlimited' => false, 'max_use' => 3, 'used_count' => 3]);

        $this->validate('SAVE10')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Promo code reached its maximum usage']);
    }

    #[Test]
    public function showForCustomerReturns404ForPromoOfAnotherEvent(): void
    {
        $promo = $this->makePromo(['activityable_id' => $this->createEvent()->uuid]);

        $this->withHeaders($this->customerHeaders())
            ->getJson("/api/v1/customer/promo-codes/{$promo->uuid}?event_uuid={$this->event->uuid}")
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Promo code not found or not valid for this event']);
    }

    #[Test]
    public function showForCustomerRejectsExpiredPromo(): void
    {
        $promo = $this->makePromo(['usable_from' => now()->subMonth(), 'usable_to' => now()->subDays(2)]);

        $this->withHeaders($this->customerHeaders())
            ->getJson("/api/v1/customer/promo-codes/{$promo->uuid}?event_uuid={$this->event->uuid}")
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Promo code has expired']);
    }

    #[Test]
    public function publicCodeRequiresAuthenticatedUser(): void
    {
        $this->makePromo();

        $response = app(PromoCodeController::class)->publicCode($this->guestShowRequest('/promo-codes/SAVE10'), 'SAVE10');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'message' => 'Please login to use this promo code'],
            $response->getData(true),
        );
    }

    #[Test]
    public function showForCustomerRequiresAuthenticatedUser(): void
    {
        $promo = $this->makePromo();

        $response = app(PromoCodeController::class)
            ->showForCustomer($this->guestShowRequest("/promo-codes/{$promo->uuid}"), $promo->uuid);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Please login to use this promo code', $response->getData(true)['message']);
    }

    #[Test]
    public function updateReturns404WhenPromoDisappearsAfterValidation(): void
    {
        $promo = $this->makePromo();

        $this->mock(PromoCodeRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoPromoCodeFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/promo-codes/{$promo->uuid}", ['description' => 'Changed'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Promo code not found']);
    }

    #[Test]
    public function destroyReturns404ForUnknownPromo(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/promo-codes/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Promo code not found']);
    }

    #[Test]
    public function destroyReturns403WhenRepositoryRefusesDeletion(): void
    {
        $promo = $this->makePromo();

        $this->mock(PromoCodeRepository::class, function ($mock) use ($promo) {
            $mock->shouldReceive('fetchOrThrow')->once()->with('uuid', $promo->uuid)->andReturn($promo);
            $mock->shouldReceive('delete')->once()->andThrow(new UnauthorizedException('Promo code is in use.'));
        });

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/promo-codes/{$promo->uuid}")
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Promo code is in use.']);

        $this->assertDatabaseHas('promo_codes', ['uuid' => $promo->uuid, 'deleted_at' => null]);
    }
}
