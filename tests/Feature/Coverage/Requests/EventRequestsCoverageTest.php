<?php

namespace Tests\Feature\Coverage\Requests;

use App\Http\Requests\Event\CreateEventRequest;
use App\Http\Requests\Event\UpdateEventAffiliateRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Requests\Event\UpdateTodayCutoffRequest;
use App\Models\Organization;
use App\Services\PaecOrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventRequestsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RequestFixtures;

    private Organization $paecOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paecOrganization = $this->makeOrganization(['email' => 'inquire@paec.com']);
    }

    #[Test]
    public function createEventDefaultsToThePaecOrganization(): void
    {
        $this->assertSame($this->paecOrganization->uuid, PaecOrganizationService::defaultOrganizationUuid());

        $this->validateFormRequest(CreateEventRequest::class, ['event_name' => 'Without organization']);

        $this->assertSame($this->paecOrganization->uuid, $this->lastRequest->input('organization_uuid'));
    }

    #[Test]
    public function createEventDecodesOtherInfoSentAsJsonAndNormalizesTheMetaPixelFlag(): void
    {
        $errors = $this->validateFormRequest(CreateEventRequest::class, [
            'other_info' => json_encode(['dress_code' => 'casual']),
            'track_event_meta' => true,
        ]);

        $this->assertSame(['dress_code' => 'casual'], $this->lastRequest->input('other_info'));
        $this->assertTrue($this->lastRequest->input('track_event_meta'));
        $this->assertArrayNotHasKey('other_info', $errors);
        $this->assertFormRequestError($errors, 'meta_pixel_id');
        $this->assertFormRequestError($errors, 'meta_pixel_key');
    }

    #[Test]
    public function createEventKeepsUndecodableOtherInfoAndRejectsIt(): void
    {
        $errors = $this->validateFormRequest(CreateEventRequest::class, ['other_info' => '{not json']);

        $this->assertSame('{not json', $this->lastRequest->input('other_info'));
        $this->assertFormRequestError($errors, 'other_info');
    }

    #[Test]
    public function createEventRequiresValidityDaysForFlexibleTickets(): void
    {
        $errors = $this->validateFormRequest(CreateEventRequest::class, [
            'tickets' => [
                ['name' => 'Flexible', 'price' => 100, 'visit_policy' => 'flexible'],
                ['name' => 'Flexible zero', 'price' => 100, 'visit_policy' => 'flexible', 'validity_days' => 0],
                ['name' => 'Flexible ok', 'price' => 100, 'visit_policy' => 'flexible', 'validity_days' => 3],
                ['name' => 'Virtual', 'price' => 100, 'is_virtual' => 'true', 'virtual_event_url' => 'https://example.com/live'],
            ],
        ]);

        $message = 'Number of days is required when visit policy is Flexible Access.';
        $this->assertFormRequestError($errors, 'tickets.0.validity_days', $message);
        $this->assertFormRequestError($errors, 'tickets.1.validity_days', $message);
        $this->assertArrayNotHasKey('tickets.2.validity_days', $errors);
        $this->assertArrayNotHasKey('tickets.3.virtual_event_url', $errors);
    }

    #[Test]
    public function updateEventFallsBackToTheAdminOrganizationWhenNoRouteUuidIsGiven(): void
    {
        $organization = $this->makeOrganization();
        $admin = $this->makeAdmin(['organization_uuid' => $organization->uuid]);
        $this->actingAs($admin, 'admin');

        $errors = $this->validateFormRequest(UpdateEventRequest::class, [
            'other_info' => json_encode(['parking' => true]),
            'track_event_meta' => '1',
        ]);

        $this->assertSame($organization->uuid, $this->lastRequest->input('organization_uuid'));
        $this->assertSame(['parking' => true], $this->lastRequest->input('other_info'));
        $this->assertTrue($this->lastRequest->input('track_event_meta'));
        $this->assertFormRequestError($errors, 'uuid');
        $this->assertArrayNotHasKey('organization_uuid', $errors);
    }

    #[Test]
    public function updateEventUsesTheExistingEventOrganization(): void
    {
        $organization = $this->makeOrganization();
        $event = $this->makeEvent(['organization_uuid' => $organization->uuid]);

        $errors = $this->validateFormRequest(UpdateEventRequest::class, ['track_event_meta' => 0], ['uuid' => $event->uuid]);

        $this->assertSame($organization->uuid, $this->lastRequest->input('organization_uuid'));
        $this->assertFalse($this->lastRequest->input('track_event_meta'));
        $this->assertArrayNotHasKey('uuid', $errors);
    }

    #[Test]
    public function affiliateSettingsTreatBlankValuesAsNull(): void
    {
        $request = $this->assertFormRequestPasses(UpdateEventAffiliateRequest::class, [
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => '',
            'affiliate_ends_at' => '',
        ]);

        $this->assertSame([
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => null,
            'affiliate_ends_at' => null,
        ], $request->validated());
    }

    #[Test]
    public function disablingAffiliatesClearsCommissionAndEndDate(): void
    {
        $request = $this->assertFormRequestPasses(UpdateEventAffiliateRequest::class, [
            'affiliate_enabled' => false,
            'affiliate_commission_percent' => 250,
            'affiliate_ends_at' => 'not-a-date',
        ]);

        $this->assertNull($request->validated('affiliate_commission_percent'));
        $this->assertNull($request->validated('affiliate_ends_at'));
    }

    #[Test]
    public function enabledAffiliatesKeepAndValidateTheCommission(): void
    {
        $errors = $this->validateFormRequest(UpdateEventAffiliateRequest::class, [
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 250,
            'affiliate_ends_at' => '2030-01-01',
        ]);

        $this->assertFormRequestError($errors, 'affiliate_commission_percent');
        $this->assertArrayNotHasKey('affiliate_ends_at', $errors);
    }

    #[Test]
    public function todayCutoffMustUseHoursAndMinutes(): void
    {
        $errors = $this->validateFormRequest(UpdateTodayCutoffRequest::class, ['today_cutoff_time' => '5pm']);

        $this->assertSame(['today_cutoff_time' => ['Cut-off time must use HH:MM format (e.g. 17:00).']], $errors);
    }

    #[Test]
    public function todayCutoffAcceptsAValidTimeOrNull(): void
    {
        $this->assertSame('17:00', $this->assertFormRequestPasses(UpdateTodayCutoffRequest::class, ['today_cutoff_time' => '17:00'])->validated('today_cutoff_time'));
        $this->assertNull($this->assertFormRequestPasses(UpdateTodayCutoffRequest::class, ['today_cutoff_time' => null])->validated('today_cutoff_time'));
    }
}
