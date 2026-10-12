<?php

namespace Tests\Feature\Coverage\ResourcesDirect;

use App\Http\Resources\AdminUserResource;
use App\Http\Resources\AffiliateConversionResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\EventSectionResource;
use App\Http\Resources\EventTicketResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\PromoCodeResource;
use App\Http\Resources\PublicOrganizationResource;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\TicketCouponResource;
use App\Http\Resources\TicketSeatResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VenueResource;
use App\Http\Resources\VenueSeatResource;
use App\Models\AdminUser;
use App\Models\AffiliateConversion;
use App\Models\Category;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\PromoCode;
use App\Models\Schedule;
use App\Models\TicketCoupon;
use App\Models\TicketSeat;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueSeat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class LoadedRelationsCoverageTest extends ResourcesDirectTestCase
{
    /**
     * @return array<string, array{class-string, class-string, list<string>, array<string, mixed>}>
     */
    public static function loadedRelationCases(): array
    {
        return [
            'admin user creator' => [AdminUserResource::class, AdminUser::class, ['creator'], [
                'creator.uuid' => 'stub-uuid',
                'creator.name' => 'Ana Cruz',
            ]],
            'category creator and updater' => [CategoryResource::class, Category::class, ['creator', 'updater'], [
                'creator.name' => 'Ana Cruz',
                'creator.email' => 'ana@example.com',
                'updater.uuid' => 'stub-uuid',
                'updater.email' => 'ana@example.com',
            ]],
            'event section creator and updater' => [EventSectionResource::class, EventSection::class, ['creator', 'updater'], [
                'creator.name' => 'Ana Cruz',
                'creator.email' => 'ana@example.com',
                'updater.name' => 'Ana Cruz',
                'updater.email' => 'ana@example.com',
            ]],
            'venue creator and updater' => [VenueResource::class, Venue::class, ['creator', 'updater'], [
                'creator.name' => 'Ana Cruz',
                'creator.email' => 'ana@example.com',
                'updater.name' => 'Ana Cruz',
                'updater.email' => 'ana@example.com',
            ]],
            'promo code creator, updater and activityable' => [PromoCodeResource::class, PromoCode::class, ['creator', 'updater', 'activityable'], [
                'creator.name' => 'Ana Cruz',
                'updater.name' => 'Ana Cruz',
                'activityable.data.uuid' => 'stub-uuid',
            ]],
            'schedule creator' => [ScheduleResource::class, Schedule::class, ['creator'], [
                'creator.uuid' => 'stub-uuid',
                'creator.name' => 'Ana Cruz',
            ]],
            'venue seat creator' => [VenueSeatResource::class, VenueSeat::class, ['creator'], [
                'creator.name' => 'Ana Cruz',
            ]],
            'event ticket creator' => [EventTicketResource::class, EventTicket::class, ['creator'], [
                'creator.name' => 'Ana Cruz',
            ]],
            'ticket coupon scanner' => [TicketCouponResource::class, TicketCoupon::class, ['scannedBy'], [
                'scanned_by_user.uuid' => 'stub-uuid',
                'scanned_by_user.first_name' => 'Ana',
            ]],
            'organization image and approver' => [OrganizationResource::class, Organization::class, ['image', 'approvedBy'], [
                'image.url' => 'https://cdn.test/stub.png',
                'image.path' => 'uploads/stub.png',
                'approvedBy.name' => 'Ana Cruz',
            ]],
            'public organization image' => [PublicOrganizationResource::class, Organization::class, ['image'], [
                'image.uuid' => 'stub-uuid',
                'image.url' => 'https://cdn.test/stub.png',
                'image.path' => 'uploads/stub.png',
            ]],
            'user profile image' => [UserResource::class, User::class, ['profileImage'], [
                'image.uuid' => 'stub-uuid',
                'image.url' => 'https://cdn.test/stub.png',
            ]],
            'transaction promo code and creator' => [TransactionResource::class, Transaction::class, ['promoCode', 'creator'], [
                'promo_code.code' => 'STUB',
                'promo_code.description' => 'Stub description',
                'creator.name' => 'Ana Cruz',
            ]],
            'ticket seat creator' => [TicketSeatResource::class, TicketSeat::class, ['creator'], [
                'creator.name' => 'Ana Cruz',
            ]],
            'affiliate conversion ticket' => [AffiliateConversionResource::class, AffiliateConversion::class, ['ticket'], [
                'ticket.uuid' => 'stub-uuid',
                'ticket.ticket_number' => 'TKT-0001',
            ]],
        ];
    }

    /**
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  list<string>  $relations
     * @param  array<string, mixed>  $expected
     */
    #[Test]
    #[DataProvider('loadedRelationCases')]
    public function itRendersLoadedRelations(string $resourceClass, string $modelClass, array $relations, array $expected): void
    {
        $model = $this->inMemory($modelClass, [], array_fill_keys($relations, ResourceStub::make()));

        $data = $this->render(new $resourceClass($model));

        foreach ($expected as $path => $value) {
            $this->assertSame($value, data_get($data, $path), "Unexpected value at {$path}");
        }
    }

    #[Test]
    public function ticketSeatRendersNestedTicketAndVenueRelationsWhenEagerLoaded(): void
    {
        $ticket = ResourceStub::make(['uuid' => 'ticket-uuid']);
        $ticket->setRelation('user', ResourceStub::make(['uuid' => 'user-uuid']));
        $ticket->setRelation('event', ResourceStub::make(['uuid' => 'event-uuid']));
        $venueSeat = ResourceStub::make(['uuid' => 'seat-uuid']);
        $venueSeat->setRelation('venue', ResourceStub::make(['uuid' => 'venue-uuid', 'name' => 'Main Hall']));

        $data = $this->render(new TicketSeatResource(
            $this->inMemory(TicketSeat::class, [], ['ticket' => $ticket, 'venueSeat' => $venueSeat])
        ));

        $this->assertSame(['uuid' => 'user-uuid', 'name' => 'Ana Cruz'], $data['ticket']['user']);
        $this->assertSame(['uuid' => 'event-uuid', 'name' => 'Stub Event'], $data['ticket']['event']);
        $this->assertSame(['uuid' => 'venue-uuid', 'name' => 'Main Hall'], $data['venue_seat']['venue']);
    }

    #[Test]
    public function ticketSeatOmitsNestedRelationsThatWereNotLoaded(): void
    {
        $data = $this->render(new TicketSeatResource($this->inMemory(TicketSeat::class, [], [
            'ticket' => ResourceStub::make(),
            'venueSeat' => ResourceStub::make(),
        ])));

        $this->assertArrayNotHasKey('user', $data['ticket']);
        $this->assertArrayNotHasKey('event', $data['ticket']);
        $this->assertArrayNotHasKey('venue', $data['venue_seat']);
    }
}
