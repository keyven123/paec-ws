<?php

namespace Tests\Feature\Coverage\HttpEvents;

use App\Constants\GeneralConstants;
use App\Http\Controllers\EventController;
use App\Http\Requests\Event\ListEventRequest;
use App\Models\Event;
use App\Models\EventSection;
use App\Services\EventLocationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class EventControllerPublicCoverageTest extends HttpEventsTestCase
{
    private function publishedEvent(string $name, array $overrides = []): Event
    {
        return Event::create(array_merge([
            'event_name' => $name,
            'event_description' => $name . ' description',
            'contact_email' => 'public@test.com',
            'city' => 'Cebu City',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'published_at' => now(),
        ], $overrides));
    }

    /**
     * @template T of FormRequest
     * @param  class-string<T>  $class
     * @return T
     */
    private function resolvedRequest(string $class, array $query = []): FormRequest
    {
        $request = $class::create('/coverage', 'GET', $query);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    #[Test]
    public function publicEventsUsesPlainCacheWhenStoreHasNoTags(): void
    {
        $this->useCacheStoreWithoutTags();
        $this->publishedEvent('Cached Public Event');
        $this->publishedEvent('Draft Event', ['status' => GeneralConstants::EVENT_STATUSES['DRAFT']]);

        $response = $this->getJson('/api/v1/public/events?per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_name', 'Cached Public Event')
            ->assertJsonPath('meta.per_page', 5);

        $this->assertTrue(
            DB::table('cache')->where('key', 'like', '%public_events_v1_%')->exists(),
            'Public events response should be stored in the untagged cache.'
        );

        // A second event is invisible until the cache version changes, proving the cached payload is served.
        $this->publishedEvent('Late Event');
        $this->getJson('/api/v1/public/events?per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame($response->json(), $this->getJson('/api/v1/public/events?per_page=5')->json());
    }

    #[Test]
    public function publicBrowseByCityUsesPlainCacheWhenStoreHasNoTags(): void
    {
        $this->useCacheStoreWithoutTags();
        $section = EventSection::create([
            'name' => EventSection::AMUSEMENT_SECTION,
            'title' => 'Amusements',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $event = $this->publishedEvent('City Fun', ['event_section_uuid' => $section->uuid]);
        EventLocationService::ensureDefaultLocation($event);

        $this->getJson('/api/v1/public/events/browse-by-city')
            ->assertOk()
            ->assertJsonPath('data.cities', ['Cebu City'])
            ->assertJsonCount(1, 'data.locations');

        $this->assertTrue(
            DB::table('cache')->where('key', 'like', '%public_browse_by_city_v1_%')->exists()
        );
    }

    #[Test]
    public function publishedReturnsOnlyPublishedEventsPaginated(): void
    {
        $this->publishedEvent('Published One');
        $this->publishedEvent('Published Two');
        $this->publishedEvent('Cancelled One', ['cancelled_at' => now()]);
        $this->publishedEvent('Draft One', ['status' => GeneralConstants::EVENT_STATUSES['DRAFT']]);

        $request = $this->resolvedRequest(ListEventRequest::class, ['per_page' => 1]);
        $payload = app(EventController::class)->published($request)->getData(true);

        $this->assertCount(1, $payload['data']);
        $this->assertSame(2, $payload['meta']['total']);
        $this->assertSame(1, $payload['meta']['per_page']);
    }

    #[Test]
    public function upcomingReturnsPublishedEventsInUpcomingSection(): void
    {
        $upcoming = EventSection::create([
            'name' => EventSection::UPCOMING_SECTION,
            'title' => 'Upcoming',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->publishedEvent('Upcoming Show', ['event_section_uuid' => $upcoming->uuid]);
        $this->publishedEvent('Other Section Show');
        $this->publishedEvent('Unpublished Upcoming', [
            'event_section_uuid' => $upcoming->uuid,
            'published_at' => null,
        ]);

        $request = $this->resolvedRequest(ListEventRequest::class);
        $payload = app(EventController::class)->upcoming($request)->getData(true);

        $this->assertSame(['Upcoming Show'], array_column($payload['data'], 'event_name'));
        $this->assertSame(15, $payload['meta']['per_page']);
    }

    #[Test]
    public function clearingCacheBumpsVersionWhenStoreHasNoTags(): void
    {
        $this->useCacheStoreWithoutTags();
        [, $token] = $this->createAdmin(['events' => ['update']]);
        $event = $this->publishedEvent('Cutoff Event');
        Cache::forever('public_events_cache_version', 7);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/v1/events/' . $event->uuid . '/today-cutoff', ['today_cutoff_time' => '15:30'])
            ->assertOk();

        $this->assertSame(8, Cache::get('public_events_cache_version'));
    }
}
