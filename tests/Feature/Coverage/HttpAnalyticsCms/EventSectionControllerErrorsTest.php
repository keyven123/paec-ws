<?php

namespace Tests\Feature\Coverage\HttpAnalyticsCms;

use App\Constants\GeneralConstants;
use App\Exceptions\NoEventSectionFoundException;
use App\Http\Repositories\EventSectionRepository;
use App\Models\Event;
use App\Models\EventSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventSectionControllerErrorsTest extends TestCase
{
    use CreatesPermittedAdmin;
    use RefreshDatabase;

    private const MISSING_UUID = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPermittedAdmin(['event-sections' => ['view', 'create', 'update', 'delete']]);
    }

    #[Test]
    public function updateRejectsUnknownSectionAtValidation(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/event-sections/' . self::MISSING_UUID, ['name' => 'Renamed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['uuid']);
    }

    #[Test]
    public function updateReturns404WhenSectionDisappearsAfterValidation(): void
    {
        $section = EventSection::create([
            'name' => 'Vanishing Section',
            'title' => 'Vanishing Section Title',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->mock(EventSectionRepository::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('fetchOrThrow')
                ->once()
                ->with('uuid', $section->uuid)
                ->andThrow(new NoEventSectionFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/event-sections/' . $section->uuid, [
                'name' => 'Renamed',
                'title' => 'Renamed Title',
            ])
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Event section not found',
            ]);

        $this->assertDatabaseHas('event_sections', [
            'uuid' => $section->uuid,
            'name' => 'Vanishing Section',
        ]);
    }

    #[Test]
    public function destroyReturns404WhenSectionDoesNotExist(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/event-sections/' . self::MISSING_UUID)
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Event section not found',
            ]);
    }

    #[Test]
    public function destroyReturns403WhenSectionIsUsedByEvents(): void
    {
        $section = EventSection::create([
            'name' => 'Busy Section',
            'title' => 'Busy Section Title',
            'description' => 'Has events',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        Event::create([
            'event_section_uuid' => $section->uuid,
            'event_name' => 'Section Event',
            'event_description' => 'Uses the section',
            'contact_email' => 'section-event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/event-sections/' . $section->uuid)
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Cannot delete event section that is being used by events.',
            ]);

        $this->assertNotSoftDeleted('event_sections', ['uuid' => $section->uuid]);
    }
}
