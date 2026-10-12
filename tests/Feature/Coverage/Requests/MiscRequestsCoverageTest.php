<?php

namespace Tests\Feature\Coverage\Requests;

use App\Constants\GeneralConstants;
use App\Http\Requests\Upload\DeleteUploadRequest;
use App\Http\Requests\Upload\GlobalUploadRequest;
use App\Http\Requests\VenueListing\PayVenueInquiryRequest;
use App\Http\Requests\VenueSeat\ShowVenueSeatRequest;
use App\Http\Requests\VenueSeat\UpdateVenueSeatRequest;
use App\Models\Upload;
use App\Models\VenueInquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MiscRequestsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RequestFixtures;

    private const UNKNOWN_UUID = '00000000-0000-4000-8000-000000000000';

    #[Test]
    public function deleteUploadRequiresAnExistingUploadAndAKnownModelType(): void
    {
        $errors = $this->validateFormRequest(DeleteUploadRequest::class, [
            'model_type' => 'Spaceship',
            'model_uuid' => 'nope',
        ], ['uuid' => self::UNKNOWN_UUID], method: 'DELETE');

        $this->assertSame(['uuid', 'model_type', 'model_uuid'], array_keys($errors));
    }

    #[Test]
    public function deleteUploadAcceptsAnExistingUpload(): void
    {
        $event = $this->makeEvent();
        $upload = Upload::create([
            'uploadable_type' => GeneralConstants::MODEL['EVENT'],
            'uploadable_uuid' => $event->uuid,
            'collection' => 'gallery',
            'type' => 'image',
            'path' => 'https://example.com/a.jpg',
        ]);

        $request = $this->assertFormRequestPasses(DeleteUploadRequest::class, [
            'model_type' => GeneralConstants::MODEL['EVENT'],
            'model_uuid' => $event->uuid,
            'category' => 'gallery',
        ], ['uuid' => $upload->uuid], method: 'DELETE');

        $this->assertSame($upload->uuid, $request->validated('uuid'));
    }

    #[Test]
    public function globalUploadRequiresAFile(): void
    {
        $errors = $this->validateFormRequest(GlobalUploadRequest::class, ['model_type' => 'Spaceship', 'order_number' => 'first']);

        $this->assertSame(['File is required.'], $errors['file']);
        $this->assertArrayHasKey('model_type', $errors);
        $this->assertArrayHasKey('order_number', $errors);
    }

    #[Test]
    public function globalUploadRejectsUnsupportedFileTypes(): void
    {
        $errors = $this->validateFormRequest(GlobalUploadRequest::class, [], [], [
            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ]);

        $this->assertContains('File must be a valid image (PNG, JPG, JPEG, JFIF) or video (MP4).', $errors['file']);
    }

    #[Test]
    public function globalUploadRejectsOversizedFiles(): void
    {
        $errors = $this->validateFormRequest(GlobalUploadRequest::class, [], [], [
            'file' => UploadedFile::fake()->image('huge.png')->size(6000),
        ]);

        $this->assertContains('File must be less than 2MB.', $errors['file']);
    }

    #[Test]
    public function globalUploadAcceptsAnImageWithMetadata(): void
    {
        $event = $this->makeEvent();

        $request = $this->assertFormRequestPasses(GlobalUploadRequest::class, [
            'disk' => 'public',
            'model_type' => GeneralConstants::MODEL['EVENT'],
            'model_uuid' => $event->uuid,
            'category' => 'gallery',
            'order_number' => 2,
        ], [], ['file' => UploadedFile::fake()->image('photo.png')]);

        $this->assertSame('photo.png', $request->validated('file')->getClientOriginalName());
        $this->assertSame(2, $request->validated('order_number'));
    }

    #[Test]
    public function payVenueInquiryValidatesProviderPhaseAndUrls(): void
    {
        $errors = $this->validateFormRequest(PayVenueInquiryRequest::class, [
            'payment_provider' => 'bitcoin',
            'payment_phase' => 'final',
            'return_url' => 'not a url',
            'cancel_url' => 'also not a url',
            'payment_methods' => 'gcash',
        ]);

        $this->assertSame(['payment_provider', 'payment_phase', 'return_url', 'cancel_url', 'payment_methods'], array_keys($errors));
    }

    #[Test]
    public function payVenueInquiryAcceptsAValidPayload(): void
    {
        $request = $this->assertFormRequestPasses(PayVenueInquiryRequest::class, [
            'payment_provider' => GeneralConstants::PAYMENT_PROVIDERS['PAYMONGO'],
            'payment_phase' => VenueInquiry::PAYMENT_PHASE_BALANCE,
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
            'payment_methods' => ['gcash', 'card'],
        ]);

        $this->assertTrue($request->authorize());
        $this->assertSame(['gcash', 'card'], $request->validated('payment_methods'));
    }

    #[Test]
    public function showVenueSeatsRequiresAPublishedEventScheduleAndTime(): void
    {
        $draftEvent = $this->makeEvent(['status' => GeneralConstants::EVENT_STATUSES['DRAFT']]);
        $draftSchedule = $this->makeSchedule($draftEvent, ['status' => GeneralConstants::SCHEDULE_STATUSES['DRAFT']]);
        $draftTime = $this->makeScheduleTime($draftSchedule, ['status' => GeneralConstants::SCHEDULE_STATUSES['DRAFT']]);

        $errors = $this->validateFormRequest(ShowVenueSeatRequest::class, [
            'schedule_uuid' => $draftSchedule->uuid,
            'schedule_time_uuid' => $draftTime->uuid,
        ], ['uuid' => $draftEvent->uuid], method: 'GET');

        $this->assertSame([
            'uuid' => ['The selected ticket does not exist.'],
            'schedule_uuid' => ['The selected schedule does not exist.'],
            'schedule_time_uuid' => ['The selected schedule time does not exist.'],
        ], $errors);
    }

    #[Test]
    public function showVenueSeatsAcceptsAPublishedEvent(): void
    {
        $event = $this->makeEvent();
        $schedule = $this->makeSchedule($event);
        $time = $this->makeScheduleTime($schedule);

        $request = $this->assertFormRequestPasses(ShowVenueSeatRequest::class, [
            'q' => 'A1',
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $time->uuid,
            'category' => 'gold',
        ], ['uuid' => $event->uuid], method: 'GET');

        $this->assertSame($event->uuid, $request->validated('uuid'));
    }

    #[Test]
    public function updateVenueSeatRejectsUnknownVenuesAndInvalidEnums(): void
    {
        $seat = $this->makeVenueSeat($this->makeVenue());

        $errors = $this->validateFormRequest(UpdateVenueSeatRequest::class, [
            'venue_uuid' => self::UNKNOWN_UUID,
            'seat_no' => 0,
            'category' => 'diamond',
            'color' => 'purple',
        ], ['uuid' => $seat->uuid], method: 'PUT');

        $this->assertSame(['The selected venue does not exist.'], $errors['venue_uuid']);
        $this->assertSame(['The category must be one of: bronze, silver, gold, vip, svip.'], $errors['category']);
        $this->assertSame(['The color must be one of: bronze, silver, gold, red, green.'], $errors['color']);
        $this->assertArrayHasKey('seat_no', $errors);
    }

    #[Test]
    public function updateVenueSeatAcceptsValidChanges(): void
    {
        $venue = $this->makeVenue();
        $seat = $this->makeVenueSeat($venue);

        $request = $this->assertFormRequestPasses(UpdateVenueSeatRequest::class, [
            'venue_uuid' => $venue->uuid,
            'col' => 'C',
            'row' => 3,
            'seat_no' => 4,
            'category' => 'svip',
            'color' => 'green',
            'status' => 'blocked',
        ], ['uuid' => $seat->uuid], method: 'PUT');

        $this->assertSame($seat->uuid, $request->validated('uuid'));
        $this->assertSame('svip', $request->validated('category'));
    }

    #[Test]
    public function updateVenueSeatRequiresAnExistingSeat(): void
    {
        $errors = $this->validateFormRequest(UpdateVenueSeatRequest::class, [], ['uuid' => self::UNKNOWN_UUID], method: 'PUT');

        $this->assertSame(['uuid'], array_keys($errors));
    }
}
