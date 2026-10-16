<?php

namespace Tests\Feature\Coverage\HttpEvents;

use App\Constants\GeneralConstants;
use App\Exceptions\NoEventFoundException;
use App\Http\Repositories\EventRepository;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Upload;
use App\Models\Venue;
use App\Support\UploadDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;

class EventControllerStoreUpdateCoverageTest extends HttpEventsTestCase
{
    private string $token;

    private Category $category;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(UploadDisk::forUploads());

        [, $this->token] = $this->createAdmin(['events' => ['view', 'create', 'update']]);

        $this->category = Category::create([
            'name' => 'Concerts',
            'code' => 'concerts-cov',
            'type' => Category::TYPES['EVENT'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->organization = Organization::factory()->create();
    }

    private function headers(): array
    {
        return $this->bearer($this->token) + ['Accept' => 'application/json'];
    }

    #[Test]
    public function storeWithUploadsSchedulesAndTicketsCreatesEverything(): void
    {
        $venue = Venue::factory()->create();
        $section = EventSection::create([
            'name' => EventSection::FEATURED_SECTION,
            'title' => 'Featured',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders($this->headers())->post('/api/v1/events', [
            'event_name' => 'Concert With Everything',
            'event_description' => 'Full payload',
            'contact_email' => 'full@test.com',
            'city' => 'Makati',
            'category_uuid' => $this->category->uuid,
            'organization_uuid' => $this->organization->uuid,
            'venue_uuid' => $venue->uuid,
            'event_section_uuid' => $section->uuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'portrait_image' => UploadedFile::fake()->image('portrait.png', 40, 60),
            'featured_image' => UploadedFile::fake()->image('featured.jpg', 80, 40),
            'event_showcase' => [
                UploadedFile::fake()->image('show-1.png', 10, 10),
                UploadedFile::fake()->image('show-2.png', 10, 10),
            ],
            'schedules' => [[
                'date_from' => '2026-12-01',
                'date_to' => '2026-12-01',
                'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
                'time' => [[
                    'time_start' => '18:00:00',
                    'time_end' => '22:00:00',
                    'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
                ]],
            ]],
            'ticket_available_from' => '2026-10-01 00:00:00',
            'ticket_available_to' => '2026-11-30 23:59:59',
            'tickets' => [
                [
                    'name' => 'VIP',
                    'description' => 'Front row',
                    'price' => 5000,
                    'max_ticket' => 20,
                    'is_virtual' => 'true',
                    'virtual_event_url' => 'https://stream.test/vip',
                    'is_unlimited' => 'false',
                    'visit_policy' => 'flexible',
                    'validity_days' => 3,
                ],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.event_name', 'Concert With Everything');

        $event = Event::where('event_name', 'Concert With Everything')->firstOrFail();
        $this->assertNotNull($event->portrait_image_uuid);
        $this->assertNotNull($event->featured_image_uuid);
        $this->assertCount(2, $event->event_showcase);

        $this->assertDatabaseHas('uploads', [
            'uuid' => $event->portrait_image_uuid,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'portrait',
        ]);
        $this->assertDatabaseHas('uploads', [
            'uuid' => $event->featured_image_uuid,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'featured',
        ]);
        $this->assertSame(
            2,
            Upload::where('uploadable_uuid', $event->uuid)->where('collection', 'showcase')->count()
        );
        $this->assertEqualsCanonicalizing(
            $event->event_showcase,
            Upload::where('uploadable_uuid', $event->uuid)->where('collection', 'showcase')->pluck('uuid')->all()
        );

        $schedule = Schedule::where('event_uuid', $event->uuid)->firstOrFail();
        $slot = ScheduleTime::where('schedule_uuid', $schedule->uuid)->firstOrFail();
        $ticket = EventTicket::where('event_uuid', $event->uuid)->firstOrFail();
        $this->assertSame($schedule->uuid, $ticket->schedule_uuid);
        $this->assertSame($slot->uuid, $ticket->schedule_time_uuid);
        $this->assertSame('vip', $ticket->code);
        $this->assertTrue((bool) $ticket->is_virtual);
        $this->assertFalse((bool) $ticket->is_unlimited);
        $this->assertSame('flexible', $ticket->visit_policy);
        $this->assertSame(3, (int) $ticket->validity_days);
        $this->assertSame('https://stream.test/vip', $ticket->virtual_event_url);
    }

    #[Test]
    public function storeDailyEventWithoutSchedulesCreatesSluggedTickets(): void
    {
        $amusement = EventSection::create([
            'name' => EventSection::AMUSEMENT_SECTION,
            'title' => 'Amusements',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->withHeaders($this->headers())->postJson('/api/v1/events', [
            'event_name' => 'Daily Fun Park',
            'event_description' => 'Daily tickets',
            'contact_email' => 'daily@test.com',
            'city' => 'Pasay',
            'category_uuid' => $this->category->uuid,
            'organization_uuid' => $this->organization->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tickets' => [
                [
                    'name' => 'Day Pass Adult',
                    'price' => 300,
                    'max_ticket' => 0,
                    'is_unlimited' => 'true',
                ],
                [
                    'name' => 'Flexi Pass',
                    'price' => 500,
                    'max_ticket' => 50,
                    'visit_policy' => 'flexible',
                    'validity_days' => 7,
                ],
            ],
        ])->assertCreated();

        $event = Event::where('event_name', 'Daily Fun Park')->firstOrFail();
        $this->assertSame($amusement->uuid, $event->event_section_uuid);
        $this->assertSame(0, Schedule::where('event_uuid', $event->uuid)->count());

        $dayPass = EventTicket::where('event_uuid', $event->uuid)->where('name', 'Day Pass Adult')->firstOrFail();
        $this->assertSame('day-pass-adult', $dayPass->code);
        $this->assertTrue((bool) $dayPass->is_unlimited);
        $this->assertSame('priority', $dayPass->visit_policy);
        $this->assertNull($dayPass->validity_days);
        $this->assertNull($dayPass->schedule_uuid);

        $flexi = EventTicket::where('event_uuid', $event->uuid)->where('name', 'Flexi Pass')->firstOrFail();
        $this->assertSame('flexi-pass', $flexi->code);
        $this->assertSame('flexible', $flexi->visit_policy);
        $this->assertSame(7, (int) $flexi->validity_days);
    }

    private function existingDailyEvent(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Existing Daily',
            'slug' => 'existing-daily',
            'event_description' => 'Before update',
            'contact_email' => 'before@test.com',
            'city' => 'Taguig',
            'category_uuid' => $this->category->uuid,
            'organization_uuid' => $this->organization->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ], $overrides));
    }

    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'event_name' => 'Existing Daily Updated',
            'slug' => 'existing-daily-updated',
            'event_description' => 'After update',
            'contact_email' => 'after@test.com',
            'city' => 'Taguig',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
        ], $overrides);
    }

    #[Test]
    public function updateWithUploadsAppendsShowcaseToExistingArray(): void
    {
        $event = $this->existingDailyEvent(['event_showcase' => ['legacy-upload-uuid']]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/events/' . $event->uuid, $this->updatePayload([
                'portrait_image' => UploadedFile::fake()->image('p.png', 20, 30),
                'featured_image' => UploadedFile::fake()->image('f.png', 30, 20),
                'event_showcase' => [UploadedFile::fake()->image('s.png', 10, 10)],
            ]))
            ->assertOk()
            ->assertJsonPath('data.event_name', 'Existing Daily Updated');

        $event->refresh();
        $this->assertCount(2, $event->event_showcase);
        $this->assertSame('legacy-upload-uuid', $event->event_showcase[0]);
        $this->assertDatabaseHas('uploads', [
            'uuid' => $event->event_showcase[1],
            'uploadable_uuid' => $event->uuid,
            'collection' => 'showcase',
        ]);
        $this->assertDatabaseHas('uploads', [
            'uuid' => $event->portrait_image_uuid,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'portrait',
        ]);
        $this->assertDatabaseHas('uploads', [
            'uuid' => $event->featured_image_uuid,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'featured',
        ]);
    }

    #[Test]
    public function updateDecodesLegacyJsonStringShowcaseBeforeAppending(): void
    {
        $event = $this->existingDailyEvent();
        DB::table('events')->where('uuid', $event->uuid)->update([
            'event_showcase' => json_encode(json_encode(['legacy-a', 'legacy-b'])),
        ]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/events/' . $event->uuid, $this->updatePayload([
                'event_showcase' => [UploadedFile::fake()->image('s.png', 10, 10)],
            ]))
            ->assertOk();

        $showcase = $event->fresh()->event_showcase;
        $this->assertCount(3, $showcase);
        $this->assertSame(['legacy-a', 'legacy-b'], array_slice($showcase, 0, 2));
    }

    #[Test]
    public function updateTreatsMissingShowcaseAsEmptyBeforeAppending(): void
    {
        $event = $this->existingDailyEvent(['event_showcase' => null]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/events/' . $event->uuid, $this->updatePayload([
                'event_showcase' => [
                    UploadedFile::fake()->image('a.png', 10, 10),
                    UploadedFile::fake()->image('b.png', 10, 10),
                ],
            ]))
            ->assertOk();

        $showcase = $event->fresh()->event_showcase;
        $this->assertCount(2, $showcase);
        $this->assertSame(2, Upload::whereIn('uuid', $showcase)->where('collection', 'showcase')->count());
    }

    #[Test]
    public function updateCanRemoveExistingShowcaseImagesWhileKeepingOthers(): void
    {
        $keep = Upload::create([
            'type' => 'image',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'disk' => UploadDisk::forUploads(),
            'path' => 'uploads/keep.png',
            'size_bytes' => 10,
        ]);
        $remove = Upload::create([
            'type' => 'image',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'disk' => UploadDisk::forUploads(),
            'path' => 'uploads/remove.png',
            'size_bytes' => 10,
            'uploadable_type' => Event::class,
            'uploadable_uuid' => null,
            'collection' => 'showcase',
        ]);

        $event = $this->existingDailyEvent([
            'event_showcase' => [$keep->uuid, $remove->uuid],
        ]);

        $remove->update([
            'uploadable_type' => Event::class,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'showcase',
        ]);
        $keep->update([
            'uploadable_type' => Event::class,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'showcase',
        ]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/events/' . $event->uuid, $this->updatePayload([
                'sync_event_showcase' => true,
                'event_showcase_uuids' => [$keep->uuid],
                'event_showcase' => [UploadedFile::fake()->image('new.png', 10, 10)],
            ]))
            ->assertOk();

        $showcase = $event->fresh()->event_showcase;
        $this->assertCount(2, $showcase);
        $this->assertSame($keep->uuid, $showcase[0]);
        $this->assertNotSame($remove->uuid, $showcase[1]);

        $this->assertDatabaseHas('uploads', [
            'uuid' => $remove->uuid,
            'uploadable_uuid' => null,
            'collection' => null,
        ]);
        $this->assertDatabaseHas('uploads', [
            'uuid' => $keep->uuid,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'showcase',
        ]);
    }

    #[Test]
    public function updateCanClearAllShowcaseImagesWhenSyncingEmptyList(): void
    {
        $upload = Upload::create([
            'type' => 'image',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'disk' => UploadDisk::forUploads(),
            'path' => 'uploads/clear-me.png',
            'size_bytes' => 10,
        ]);

        $event = $this->existingDailyEvent(['event_showcase' => [$upload->uuid]]);
        $upload->update([
            'uploadable_type' => Event::class,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'showcase',
        ]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/events/' . $event->uuid, $this->updatePayload([
                'sync_event_showcase' => true,
                'event_showcase_uuids' => [],
            ]))
            ->assertOk();

        $this->assertSame([], $event->fresh()->event_showcase);
        $this->assertDatabaseHas('uploads', [
            'uuid' => $upload->uuid,
            'uploadable_uuid' => null,
            'collection' => null,
        ]);
    }

    #[Test]
    public function updateReturns404WhenRepositoryCannotFindEvent(): void
    {
        $event = $this->existingDailyEvent();
        $this->partialMock(EventRepository::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetchOrThrow')->andThrow(new NoEventFoundException());
        });

        $this->withHeaders($this->headers())
            ->putJson('/api/v1/events/' . $event->uuid, $this->updatePayload())
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Event not found']);

        $this->assertDatabaseHas('events', ['uuid' => $event->uuid, 'event_name' => 'Existing Daily']);
    }

    #[Test]
    public function updateRollsBackAndRethrowsUnexpectedErrors(): void
    {
        $event = $this->existingDailyEvent();
        $this->partialMock(EventRepository::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetchOrThrow')->andThrow(new \RuntimeException('database exploded'));
        });

        $this->withHeaders($this->headers())
            ->putJson('/api/v1/events/' . $event->uuid, $this->updatePayload())
            ->assertStatus(500);

        $this->assertSame(0, DB::transactionLevel() - 1);
        $this->assertDatabaseHas('events', ['uuid' => $event->uuid, 'event_name' => 'Existing Daily']);
    }
}
