<?php

namespace Tests\Feature\Coverage\ResourcesDirect;

use App\Http\Resources\EventPublicResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\EventShowPublicResource;
use App\Http\Resources\VenueListingDashboardResource;
use App\Models\Event;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Upload;
use App\Models\Venue;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;

class EventResourcesCoverageTest extends ResourcesDirectTestCase
{
    private function makeUpload(string $uuid): Upload
    {
        return Upload::create([
            'uuid' => $uuid,
            'type' => 'image',
            'disk' => 'public',
            'path' => "https://cdn.test/{$uuid}.png",
        ]);
    }

    private function images(): array
    {
        return [
            'logo' => ResourceStub::make(['uuid' => 'logo-uuid']),
            'portraitImage' => ResourceStub::make(['uuid' => 'portrait-uuid']),
            'featuredImage' => ResourceStub::make(['uuid' => 'featured-uuid']),
        ];
    }

    #[Test]
    public function publicListingKeepsShowcaseOrderAndRendersSchedulesAndImages(): void
    {
        $this->makeUpload('showcase-a');
        $this->makeUpload('showcase-b');

        $schedule = $this->inMemory(Schedule::class, ['uuid' => 'schedule-uuid', 'date_from' => '2026-10-12', 'date_to' => '2026-10-14'], [
            'scheduleTimes' => new Collection([
                $this->inMemory(ScheduleTime::class, ['uuid' => 'time-uuid', 'time_start' => '09:00:00', 'time_end' => '17:00:00']),
            ]),
        ]);
        $event = $this->inMemory(Event::class, ['event_showcase' => ['showcase-b', 'showcase-a']], $this->images() + [
            'eventTickets' => new Collection(),
            'schedules' => new Collection([$schedule]),
        ]);

        $data = $this->render(new EventPublicResource($event));

        $this->assertSame(['showcase-b', 'showcase-a'], array_column($data['event_showcase'], 'uuid'));
        $this->assertSame('https://cdn.test/showcase-b.png', $data['event_showcase'][0]['url']);
        $this->assertSame([[
            'uuid' => 'schedule-uuid',
            'date_from' => '2026-10-12',
            'date_to' => '2026-10-14',
            'schedule_times' => [['uuid' => 'time-uuid', 'time_start' => '09:00:00', 'time_end' => '17:00:00']],
        ]], $data['schedules']);
        $this->assertSame('logo-uuid', $data['logo']['uuid']);
        $this->assertSame('portrait-uuid', $data['portrait_image']['uuid']);
        $this->assertSame('featured-uuid', $data['featured_image']['uuid']);
    }

    #[Test]
    public function publicDetailRendersShowcaseImagesAndVenue(): void
    {
        $this->makeUpload('showcase-a');
        $this->makeUpload('showcase-b');

        $venue = ResourceStub::make(['uuid' => 'venue-uuid', 'name' => 'Main Hall', 'code' => 'MH', 'type' => 'indoor']);
        $venue->setRelation('image', ResourceStub::make(['uuid' => 'venue-image', 'path' => 'venues/hall.png']));

        $event = $this->inMemory(Event::class, ['event_showcase' => ['showcase-a', 'showcase-b']], $this->images() + [
            'category' => ResourceStub::make(['name' => 'Concerts']),
            'venue' => $venue,
        ]);

        $data = $this->render(new EventShowPublicResource($event));

        $this->assertSame('Concerts', $data['category_name']);
        $this->assertSame(['showcase-a', 'showcase-b'], array_column($data['event_showcase'], 'uuid'));
        $this->assertSame('portrait-uuid', $data['portrait_image']['uuid']);
        $this->assertSame('featured-uuid', $data['featured_image']['uuid']);
        $this->assertSame([
            'uuid' => 'venue-uuid',
            'name' => 'Main Hall',
            'code' => 'MH',
            'type' => 'indoor',
            'image' => [
                'uuid' => 'venue-image',
                'path' => 'venues/hall.png',
                'url' => config('app.url') . '/venues/hall.png',
            ],
        ], $data['venue']);
    }

    #[Test]
    public function adminEventDecodesDoubleEncodedShowcaseAndReportsSeatsApproverAndVenue(): void
    {
        $this->makeUpload('showcase-a');

        $venue = $this->inMemory(Venue::class, ['uuid' => 'venue-uuid', 'name' => 'Main Hall'], [
            'venueSeats' => new Collection([ResourceStub::make()]),
        ]);
        $event = $this->inMemory(Event::class, [], [
            'approvedBy' => ResourceStub::make(['uuid' => 'approver-uuid']),
            'venue' => $venue,
        ]);
        $event->setRawAttributes($event->getAttributes() + ['event_showcase' => json_encode(json_encode(['showcase-a']))]);

        $data = $this->render(new EventResource($event));

        $this->assertSame(['showcase-a'], array_column($data['event_showcase'], 'uuid'));
        $this->assertTrue($data['has_seats']);
        $this->assertSame(['uuid' => 'approver-uuid', 'name' => 'Ana Cruz'], $data['approvedBy']);
        $this->assertSame('venue-uuid', $data['venue']['uuid']);
    }

    #[Test]
    public function adminEventHasNoSeatsWithoutAVenue(): void
    {
        $withoutVenue = $this->render(new EventResource($this->inMemory(Event::class, [], ['venue' => null])));
        $notLoaded = $this->render(new EventResource($this->inMemory(Event::class)));

        $this->assertFalse($withoutVenue['has_seats']);
        $this->assertFalse($notLoaded['has_seats']);
    }

    #[Test]
    public function venueDashboardCountsConfirmedBookingsWhenNotPreloaded(): void
    {
        $listing = VenueListing::factory()->published()->create();
        foreach (['FULLY_PAID', 'COMPLETED', 'NEW'] as $status) {
            VenueInquiry::factory()->create([
                'venue_listing_uuid' => $listing->uuid,
                'status' => VenueInquiry::STATUSES[$status],
            ]);
        }

        $data = $this->render(new VenueListingDashboardResource($listing));

        $this->assertSame(2, $data['stats']['bookings_count']);
    }
}
