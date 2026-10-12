<?php

namespace Tests\Feature\Coverage\GapEvents;

use App\Http\Repositories\EventRepository;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventSection;
use PHPUnit\Framework\Attributes\Test;

class EventRepositoryCatalogTest extends GapEventsTestCase
{
    private EventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(EventRepository::class);
    }

    #[Test]
    public function publicEventsWithUnknownSectionTypeMatchNothing(): void
    {
        $this->createEvent('Visible Event', ['event_section_uuid' => $this->section(EventSection::UPCOMING_SECTION)->uuid]);

        $events = $this->repository->getPublicEvents(['type' => 'no-such-section'])->get();

        $this->assertCount(0, $events);
    }

    #[Test]
    public function publicFeaturedEventsAreOrderedByFeaturedOrder(): void
    {
        $featured = $this->section(EventSection::FEATURED_SECTION);
        $this->createEvent('Second Featured', ['event_section_uuid' => $featured->uuid, 'featured_order' => 2]);
        $this->createEvent('First Featured', ['event_section_uuid' => $featured->uuid, 'featured_order' => 1]);
        $this->createEvent('Other Section', ['event_section_uuid' => $this->section(EventSection::NEW_EVENT_SECTION)->uuid]);

        $names = $this->repository->getPublicEvents(['type' => EventSection::FEATURED_SECTION])
            ->get()
            ->pluck('event_name')
            ->all();

        $this->assertSame(['First Featured', 'Second Featured'], $names);
    }

    #[Test]
    public function publicUpcomingEventsRequireAPublishedAtDate(): void
    {
        $upcoming = $this->section(EventSection::UPCOMING_SECTION);
        $this->createEvent('Announced', ['event_section_uuid' => $upcoming->uuid, 'published_at' => now()]);
        $this->createEvent('Unannounced', ['event_section_uuid' => $upcoming->uuid, 'published_at' => null]);

        $names = $this->repository->getPublicEvents(['type' => EventSection::UPCOMING_SECTION])
            ->get()
            ->pluck('event_name')
            ->all();

        $this->assertSame(['Announced'], $names);
    }

    #[Test]
    public function publicOpenPassEventsAreScopedToTheOpenPassSection(): void
    {
        $openPass = $this->section(EventSection::OPEN_PASS_SECTION);
        $this->createEvent('Open Pass Event', ['event_section_uuid' => $openPass->uuid]);
        $this->createEvent('Private Pass', [
            'event_section_uuid' => $openPass->uuid,
            'event_config' => Event::EVENT_CONFIGS['PRIVATE_EVENT'],
        ]);
        $this->createEvent('New Event', ['event_section_uuid' => $this->section(EventSection::NEW_EVENT_SECTION)->uuid]);

        $names = $this->repository->getPublicEvents(['type' => EventSection::OPEN_PASS_SECTION])
            ->get()
            ->pluck('event_name')
            ->all();

        $this->assertSame(['Open Pass Event'], $names);
    }

    #[Test]
    public function publicNewEventsAreScopedToTheNewEventSection(): void
    {
        $newEvent = $this->section(EventSection::NEW_EVENT_SECTION);
        $this->createEvent('Fresh Event', ['event_section_uuid' => $newEvent->uuid]);
        $this->createEvent('Cancelled Fresh Event', ['event_section_uuid' => $newEvent->uuid, 'cancelled_at' => now()]);
        $this->createEvent('Open Pass', ['event_section_uuid' => $this->section(EventSection::OPEN_PASS_SECTION)->uuid]);

        $names = $this->repository->getPublicEvents(['type' => EventSection::NEW_EVENT_SECTION])
            ->get()
            ->pluck('event_name')
            ->all();

        $this->assertSame(['Fresh Event'], $names);
    }

    #[Test]
    public function browseByCityCitiesIsEmptyWhenTheSectionDoesNotExist(): void
    {
        $this->assertSame([], $this->repository->getBrowseByCityCities());
    }

    #[Test]
    public function browseByCityCitiesListsCitiesForANonAmusementSection(): void
    {
        $life = $this->section(EventSection::LIFE_SECTION);
        $event = $this->createEvent('City Event', ['event_section_uuid' => $life->uuid]);
        EventLocation::create([
            'event_uuid' => $event->uuid,
            'organization_uuid' => $event->organization_uuid,
            'name' => 'Branch',
            'city' => 'Makati',
            'is_active' => true,
        ]);

        $this->assertSame(['Makati'], $this->repository->getBrowseByCityCities(EventSection::LIFE_SECTION));
    }

    #[Test]
    public function browseByCityLocationsIsEmptyWhenTheSectionDoesNotExist(): void
    {
        $locations = $this->repository->getBrowseByCityLocations(['type' => 'missing-section']);

        $this->assertTrue($locations->isEmpty());
    }

    #[Test]
    public function affiliatePartnerTicketEventsExcludeTheAmusementSection(): void
    {
        $amusement = $this->section(EventSection::AMUSEMENT_SECTION);
        $concerts = $this->section(EventSection::NEW_EVENT_SECTION);
        $this->createEvent('Theme Park', ['event_section_uuid' => $amusement->uuid, 'affiliate_enabled' => true]);
        $this->createEvent('Concert', ['event_section_uuid' => $concerts->uuid, 'affiliate_enabled' => true]);
        $this->createEvent('No Affiliate', ['event_section_uuid' => $concerts->uuid, 'affiliate_enabled' => false]);

        $names = $this->repository->getAffiliatePartnerTicketEvents([])->get()->pluck('event_name')->all();

        $this->assertSame(['Concert'], $names);
    }

    #[Test]
    public function isAmusementEventLoadsTheSectionWhenNotEagerLoaded(): void
    {
        $amusement = $this->section(EventSection::AMUSEMENT_SECTION);
        $fun = $this->createEvent('Fun Park', ['event_section_uuid' => $amusement->uuid]);
        $concert = $this->createEvent('Concert', ['event_section_uuid' => $this->section(EventSection::NEW_EVENT_SECTION)->uuid]);

        $freshFun = Event::find($fun->uuid);
        $this->assertFalse($freshFun->relationLoaded('eventSection'));
        $this->assertTrue($this->repository->isAmusementEvent($freshFun));
        $this->assertTrue($freshFun->relationLoaded('eventSection'));

        $this->assertFalse($this->repository->isAmusementEvent(Event::find($concert->uuid)));
    }
}
