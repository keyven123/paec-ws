<?php

namespace Tests\Feature\Coverage\RModelsCore;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Upload;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventModelTest extends TestCase
{
    use RefreshDatabase;
    use RModelsCoreFixtures;

    private function section(string $name): EventSection
    {
        return EventSection::create(['name' => $name, 'title' => ucfirst($name)]);
    }

    private function ticketType(Event $event, array $attributes = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $event->uuid,
            'code' => 'MC-' . Str::random(8),
            'name' => 'General',
            'price' => 100,
            'is_bundle' => false,
        ], $attributes));
    }

    #[Test]
    public function filtersByKeywordAcrossEventTextColumns(): void
    {
        $byName = $this->makeEvent(['event_name' => 'Quasar Night']);
        $byMeta = $this->makeEvent(['meta_description' => 'see the quasar']);
        $this->makeEvent(['event_name' => 'Unrelated']);

        $this->assertSameModels([$byName, $byMeta], Event::filters(['q' => 'quasar'])->get());
    }

    #[Test]
    public function filtersBySimpleColumns(): void
    {
        $venue = Venue::factory()->create();
        $organization = Organization::factory()->create();
        $target = $this->makeEvent([
            'address' => '12 Rizal Avenue',
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
            'venue_uuid' => $venue->uuid,
            'event_type' => Event::EVENT_TYPES['MULTIPLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'is_featured' => true,
            'organization_uuid' => $organization->uuid,
            'is_request_for_featured' => true,
        ]);
        $other = $this->makeEvent(['is_featured' => false, 'is_request_for_featured' => false]);

        $cases = [
            ['address' => 'Rizal'],
            ['status' => GeneralConstants::EVENT_STATUSES['DRAFT']],
            ['venue_uuid' => $venue->uuid],
            ['event_type' => $target->event_type],
            ['schedule_type' => Event::SCHEDULE_TYPES['DAILY']],
            ['is_featured' => true],
            ['organization_uuid' => $organization->uuid],
            ['is_request_for_featured' => true],
        ];

        foreach ($cases as $filters) {
            $this->assertSameModels([$target], Event::filters($filters)->get(), json_encode($filters));
        }
        $this->assertSameModels([$other], Event::filters(['is_featured' => false])->get());
    }

    #[Test]
    public function amusementSectionFilterMatchesNothingWhenNoCatalogSectionsExist(): void
    {
        $this->makeEvent();

        $this->assertCount(0, Event::filters(['event_section_type' => EventSection::AMUSEMENT_SECTION])->get());
    }

    #[Test]
    public function nonAmusementSectionFilterMatchesSectionNameAndFeaturedFlag(): void
    {
        $featured = $this->section(EventSection::FEATURED_SECTION);
        $openPass = $this->section(EventSection::OPEN_PASS_SECTION);

        $featuredOn = $this->makeEvent(['event_section_uuid' => $featured->uuid, 'is_featured' => true]);
        $featuredOff = $this->makeEvent(['event_section_uuid' => $featured->uuid, 'is_featured' => false]);
        $open = $this->makeEvent(['event_section_uuid' => $openPass->uuid]);

        $this->assertSameModels([$featuredOn], Event::filters(['event_section_type' => EventSection::FEATURED_SECTION])->get());
        $this->assertSameModels([$open], Event::filters(['event_section_type' => EventSection::OPEN_PASS_SECTION])->get());
        $this->assertSameModels(
            [$featuredOn, $featuredOff, $open],
            Event::filters(['event_section_types' => [EventSection::FEATURED_SECTION, EventSection::OPEN_PASS_SECTION]])->get(),
        );
    }

    #[Test]
    public function availableEventFilterReturnsPublishedNonFeaturedEventsInFeaturedSection(): void
    {
        $featured = $this->section(EventSection::FEATURED_SECTION);

        $available = $this->makeEvent(['event_section_uuid' => $featured->uuid, 'is_featured' => false]);
        $this->makeEvent(['event_section_uuid' => $featured->uuid, 'is_featured' => true]);
        $this->makeEvent([
            'event_section_uuid' => $featured->uuid,
            'is_featured' => false,
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);
        $this->makeEvent(['is_featured' => false]);

        $this->assertSameModels([$available], Event::filters(['available_event' => true])->get());
    }

    #[Test]
    public function publishedFilterSplitsPublishedFromDraftAndPending(): void
    {
        $published = $this->makeEvent();
        $draft = $this->makeEvent(['status' => GeneralConstants::EVENT_STATUSES['DRAFT']]);
        $pending = $this->makeEvent(['status' => GeneralConstants::EVENT_STATUSES['PENDING']]);
        $this->makeEvent(['status' => GeneralConstants::EVENT_STATUSES['CANCELLED']]);

        $this->assertSameModels([$published], Event::filters(['published' => true])->get());
        $this->assertSameModels([$draft, $pending], Event::filters(['published' => false])->get());
    }

    #[Test]
    public function nonFunAffiliateCatalogExcludesAmusementEvents(): void
    {
        $amusement = $this->section(EventSection::AMUSEMENT_SECTION);
        $openPass = $this->section(EventSection::OPEN_PASS_SECTION);

        $noSection = $this->makeEvent();
        $other = $this->makeEvent(['event_section_uuid' => $openPass->uuid]);
        $fun = $this->makeEvent(['event_section_uuid' => $amusement->uuid]);

        $this->assertSameModels([$noSection, $other], Event::filters(['affiliate_catalog' => 'events'])->get());
        $this->assertSameModels([$fun], Event::filters(['affiliate_catalog' => 'fun'])->get());
        $this->assertSameModels([$fun], Event::amusement()->get());
    }

    #[Test]
    public function newFunTypeReturnsEventsWithUndiscountedTickets(): void
    {
        $plain = $this->makeEvent();
        $this->ticketType($plain);
        $promo = $this->makeEvent();
        $this->ticketType($promo, ['discount_type' => 'percentage', 'discount_value' => 10]);
        $this->makeEvent();

        $this->assertSameModels([$plain], Event::filters(['fun_type' => Event::FUN_TYPES['NEW']])->get());
        $this->assertSameModels([$promo], Event::filters(['fun_type' => Event::FUN_TYPES['PROMO']])->get());
    }

    #[Test]
    public function draftAndFeaturedScopesFilterByStatusAndFlag(): void
    {
        $draft = $this->makeEvent(['status' => GeneralConstants::EVENT_STATUSES['DRAFT']]);
        $featured = $this->makeEvent(['is_featured' => true]);

        $this->assertSameModels([$draft], Event::draft()->get());
        $this->assertSameModels([$featured], Event::featured()->get());
    }

    #[Test]
    public function showcaseUploadsAccessorReturnsUploadsInStoredOrder(): void
    {
        $first = Upload::create(['type' => 'image', 'path' => 'showcase/a.jpg']);
        $second = Upload::create(['type' => 'image', 'path' => 'showcase/b.jpg']);
        Upload::create(['type' => 'image', 'path' => 'showcase/unrelated.jpg']);

        $event = $this->makeEvent();
        $this->assertInstanceOf(Collection::class, $event->showcase_uploads);
        $this->assertCount(0, $event->showcase_uploads);

        $event->event_showcases = [$second->uuid, $first->uuid];

        $this->assertSame([$second->uuid, $first->uuid], $event->showcase_uploads->pluck('uuid')->all());
    }

    #[Test]
    public function visitDatesBeforeTodayAreNotBookable(): void
    {
        $event = $this->makeEvent();
        $now = Carbon::parse('2026-06-15 09:00:00', config('app.timezone'));

        $this->assertFalse($event->isVisitDateBookable('2026-06-14', $now));
        $this->assertTrue($event->isVisitDateBookable('2026-06-16', $now));
    }
}
