<?php

namespace Tests\Feature\Coverage\Requests;

use App\Constants\GeneralConstants;
use App\Http\Requests\EventTicket\BulkCreateEventTicketsRequest;
use App\Http\Requests\EventTicket\CreateEventTicketRequest;
use App\Http\Requests\EventTicket\UpdateEventTicketRequest;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventTicketRequestsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RequestFixtures;

    /**
     * Pins current behaviour: the after() hook iterates `tickets` without an is_array() guard,
     * so a non-array payload surfaces as a PHP error instead of a 422.
     */
    #[Test]
    public function bulkCreateCrashesOnANonArrayTicketsPayload(): void
    {
        try {
            $this->validateFormRequest(BulkCreateEventTicketsRequest::class, ['tickets' => 'nope']);
            $this->fail('Expected the after() hook to fail on a non-array tickets payload.');
        } catch (\ErrorException $e) {
            $this->assertStringContainsString('foreach() argument must be of type array|object', $e->getMessage());
        }

        $this->assertSame('nope', $this->lastRequest->input('tickets'));
    }

    #[Test]
    public function bulkCreateRequiresSchedulesForRegularEvents(): void
    {
        $event = $this->makeEvent();

        $errors = $this->validateFormRequest(BulkCreateEventTicketsRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [
                $this->bulkTicket(['schedule_uuid' => '', 'schedule_time_uuid' => '']),
            ],
        ]);

        $this->assertNull($this->lastRequest->input('tickets.0.schedule_uuid'));
        $this->assertFormRequestError($errors, 'tickets.0.schedule_uuid');
        $this->assertFormRequestError($errors, 'tickets.0.schedule_time_uuid');
    }

    #[Test]
    public function bulkCreateMakesSchedulesOptionalForAmusementEvents(): void
    {
        $event = $this->makeAmusementEvent(['event_type' => Event::EVENT_TYPES['SINGLE']]);

        $errors = $this->validateFormRequest(BulkCreateEventTicketsRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [$this->bulkTicket(['schedule_uuid' => ''])],
        ]);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function bulkCreateSkipsNonArrayTicketRows(): void
    {
        $event = $this->makeEvent(['event_type' => Event::EVENT_TYPES['DAILY']]);

        $errors = $this->validateFormRequest(BulkCreateEventTicketsRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [$this->bulkTicket(), 'garbage'],
        ]);

        $this->assertSame('garbage', $this->lastRequest->input('tickets.1'));
        $this->assertFormRequestError($errors, 'tickets.1.name');
        $this->assertFormRequestError($errors, 'tickets.1.is_unlimited');
        $this->assertSame([], array_filter(array_keys($errors), fn ($key) => str_starts_with($key, 'tickets.0')));
    }

    #[Test]
    public function bulkCreateRejectsDuplicateCodesAndExistingCodes(): void
    {
        $event = $this->makeEvent(['event_type' => Event::EVENT_TYPES['DAILY']]);
        $this->makeEventTicket($event, ['code' => 'taken']);

        $errors = $this->validateFormRequest(BulkCreateEventTicketsRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [
                $this->bulkTicket(['code' => 'VIP']),
                $this->bulkTicket(['code' => ' vip ']),
                $this->bulkTicket(['code' => 'taken']),
            ],
        ]);

        $this->assertFormRequestError($errors, 'tickets', 'Each ticket code must be unique in this request.');
        $this->assertFormRequestError($errors, 'tickets.2.code', 'This code is already used for another ticket in this event.');
    }

    #[Test]
    public function bulkCreateValidatesPerTicketBusinessRules(): void
    {
        $event = $this->makeEvent(['event_type' => Event::EVENT_TYPES['DAILY']]);

        $errors = $this->validateFormRequest(BulkCreateEventTicketsRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [
                $this->bulkTicket(['available_from' => '2030-01-10 00:00:00', 'available_to' => '2030-01-01 00:00:00']),
                $this->bulkTicket(['visit_policy' => 'flexible']),
                $this->bulkTicket(['is_virtual' => true]),
                $this->bulkTicket(['with_coupon' => true, 'coupons' => []]),
                $this->bulkTicket(['with_coupon' => true, 'coupons' => [['name' => '  ']]]),
                $this->bulkTicket(['discount_value' => 0]),
                $this->bulkTicket(['discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'], 'discount_value' => 150]),
                $this->bulkTicket(['discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'], 'discount_value' => 500, 'price' => 100]),
                $this->bulkTicket(['is_bundle' => true]),
            ],
        ]);

        $this->assertFormRequestError($errors, 'tickets.0.available_to', 'The available to date must be after or equal to the available from date.');
        $this->assertFormRequestError($errors, 'tickets.1.validity_days', 'Number of days is required when visit policy is Flexible Access.');
        $this->assertFormRequestError($errors, 'tickets.2.virtual_event_url', 'The virtual activity link is required when ticket is virtual.');
        $this->assertFormRequestError($errors, 'tickets.3.coupons', 'At least one coupon is required when coupons are enabled.');
        $this->assertFormRequestError($errors, 'tickets.4.coupons', 'At least one coupon name is required when coupons are enabled.');
        $this->assertFormRequestError($errors, 'tickets.5.discount_type', 'Discount type is required when discount is set.');
        $this->assertFormRequestError($errors, 'tickets.5.discount_value', 'Discount value is required and must be greater than 0 when discount is set.');
        $this->assertFormRequestError($errors, 'tickets.6.discount_value', 'Discount percentage must be between 0 and 100.');
        $this->assertFormRequestError($errors, 'tickets.7.discount_value', 'Discount amount must be between 0 and 100.');
        $this->assertFormRequestError($errors, 'tickets.8.bundle_quantity', 'Quantity is required and must be at least 1 when bundle is enabled.');
    }

    #[Test]
    public function bulkCreateAcceptsValidDiscountsAndNamedCoupons(): void
    {
        $event = $this->makeEvent(['event_type' => Event::EVENT_TYPES['DAILY']]);

        $errors = $this->validateFormRequest(BulkCreateEventTicketsRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [
                $this->bulkTicket([
                    'with_coupon' => true,
                    'coupons' => [['name' => 'Free drink'], ['name' => 'Popcorn', 'once_only' => true]],
                    'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
                    'discount_value' => 50,
                    'is_bundle' => true,
                    'bundle_quantity' => 2,
                    'is_virtual' => true,
                    'virtual_event_url' => 'https://example.com/live',
                    'visit_policy' => 'flexible',
                    'validity_days' => 7,
                ]),
                $this->bulkTicket(['discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'], 'discount_value' => 50, 'price' => 100]),
            ],
        ]);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function bulkCreatePayloadNormalizesDiscountsAndCoupons(): void
    {
        $event = $this->makeEvent(['event_type' => Event::EVENT_TYPES['DAILY']]);

        $this->assertFormRequestPasses(BulkCreateEventTicketsRequest::class, [
            'event_uuid' => $event->uuid,
            'tickets' => [
                $this->bulkTicket([
                    'name' => 'With extras',
                    'with_discount' => true,
                    'discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
                    'discount_value' => 20,
                    'with_coupon' => true,
                    'coupons' => [['name' => 'Free drink']],
                ]),
                $this->bulkTicket([
                    'name' => 'Plain',
                    'with_discount' => false,
                    'discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
                    'discount_value' => 20,
                    'with_coupon' => false,
                    'coupons' => [['name' => 'Ignored']],
                ]),
            ],
        ]);

        $payload = $this->lastRequest->validatedTicketsPayload();

        $this->assertSame($event->uuid, $payload['event_uuid']);
        $this->assertCount(2, $payload['tickets']);

        [$withExtras, $plain] = $payload['tickets'];
        $this->assertSame($event->uuid, $withExtras['event_uuid']);
        $this->assertSame('amount', $withExtras['discount_type']);
        $this->assertSame(20, $withExtras['discount_value']);
        $this->assertSame([['name' => 'Free drink']], $withExtras['coupons']);
        $this->assertArrayNotHasKey('with_discount', $withExtras);

        $this->assertNull($plain['discount_type']);
        $this->assertNull($plain['discount_value']);
        $this->assertSame([], $plain['coupons']);
    }

    #[Test]
    public function createTicketMakesSchedulesOptionalForDailyEvents(): void
    {
        $event = $this->makeEvent(['event_type' => Event::EVENT_TYPES['DAILY']]);

        $request = $this->assertFormRequestPasses(CreateEventTicketRequest::class, [
            'event_uuid' => $event->uuid,
            'name' => 'Daily pass',
            'price' => 100,
            'is_unlimited' => true,
        ]);

        $this->assertNull($request->validated('schedule_uuid'));
    }

    #[Test]
    public function createTicketRequiresValidityDaysForFlexibleTickets(): void
    {
        $event = $this->makeEvent(['event_type' => Event::EVENT_TYPES['DAILY']]);

        $errors = $this->validateFormRequest(CreateEventTicketRequest::class, [
            'event_uuid' => $event->uuid,
            'name' => 'Flexible',
            'price' => 100,
            'is_unlimited' => true,
            'visit_policy' => 'flexible',
        ]);

        $this->assertSame(['validity_days' => ['Number of days is required when visit policy is Flexible Access.']], $errors);
    }

    #[Test]
    public function updateTicketTakesTheUuidFromTheRouteAndAcceptsValidChanges(): void
    {
        $event = $this->makeEvent();
        $ticket = $this->makeEventTicket($event);
        $other = $this->makeEventTicket($event);

        $request = $this->assertFormRequestPasses(UpdateEventTicketRequest::class, [
            'name' => 'Renamed',
            'price' => 200,
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            'discount_value' => 150,
            'bundle_tickets' => [$other->uuid],
            'available_from' => '2030-01-01 00:00:00',
            'available_to' => '2030-01-02 00:00:00',
            'visit_policy' => 'flexible',
            'validity_days' => 2,
            'with_coupon' => true,
            'coupons' => [['name' => 'Free drink']],
        ], ['uuid' => $ticket->uuid]);

        $this->assertSame($ticket->uuid, $request->validated('uuid'));
        $this->assertSame('Renamed', $request->validated('name'));
    }

    #[Test]
    public function updateTicketRejectsSelfBundlingAndInvalidCombinations(): void
    {
        $event = $this->makeEvent();
        $ticket = $this->makeEventTicket($event);

        $errors = $this->validateFormRequest(UpdateEventTicketRequest::class, [
            'event_uuid' => '00000000-0000-4000-8000-000000000000',
            'bundle_tickets' => [$ticket->uuid],
            'available_from' => '2030-01-10 00:00:00',
            'available_to' => '2030-01-01 00:00:00',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 150,
            'is_virtual' => true,
            'with_coupon' => true,
            'visit_policy' => 'flexible',
        ], ['uuid' => $ticket->uuid]);

        $this->assertFormRequestError($errors, 'event_uuid', 'The selected event does not exist.');
        $this->assertFormRequestError($errors, 'bundle_tickets.0', 'A ticket cannot include itself in its bundle.');
        $this->assertFormRequestError($errors, 'available_to', 'The available to date must be after or equal to the available from date.');
        $this->assertFormRequestError($errors, 'discount_value');
        $this->assertFormRequestError($errors, 'virtual_event_url');
        $this->assertFormRequestError($errors, 'coupons');
        $this->assertFormRequestError($errors, 'validity_days', 'Number of days is required when visit policy is Flexible Access.');
    }

    /**
     * Pins current behaviour: rules() dereferences the route ticket before the `uuid` exists rule runs,
     * so an unknown ticket uuid surfaces as a PHP error instead of a 422/404.
     */
    #[Test]
    public function updateTicketCrashesForAnUnknownRouteUuid(): void
    {
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Attempt to read property "event" on null');

        $this->validateFormRequest(UpdateEventTicketRequest::class, ['name' => 'Ghost'], ['uuid' => '00000000-0000-4000-8000-000000000000']);
    }

    private function bulkTicket(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ticket',
            'price' => 100,
            'is_unlimited' => true,
        ], $overrides);
    }
}
