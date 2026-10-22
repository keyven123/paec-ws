<?php

namespace Tests\Feature\Coverage\RPixelTickets;

require_once __DIR__ . '/Support/function_shims.php';
require_once __DIR__ . '/Support/FakeImagick.php';

use App\Models\Event;
use App\Models\EventLocation;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\TicketCoupon;
use App\Models\Transaction;
use App\Models\Upload;
use App\Services\TicketEmailExportService;
use App\Services\TicketQrPngService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\RPixelTickets\Support\FunctionShims;
use Tests\TestCase;

class TicketEmailExportServiceCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RPixelTicketsFixtures;

    private string $sandbox;

    /** @var array<string, array<string, mixed>> */
    private array $viewData = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rpix-' . Str::random(12);
        File::ensureDirectoryExists($this->sandbox . '/storage/app');
        File::ensureDirectoryExists($this->sandbox . '/public');
        $this->app->useStoragePath($this->sandbox . DIRECTORY_SEPARATOR . 'storage');
        $this->app->usePublicPath($this->sandbox . DIRECTORY_SEPARATOR . 'public');

        foreach (['pdf.ticket-email-card', 'pdf.ticket-coupons-email-pack', 'pdf.sponsor-ticket-page'] as $name) {
            View::composer($name, function ($view) use ($name) {
                $this->viewData[$name] = $view->getData();
            });
        }

        \Imagick::resetFake();
    }

    protected function tearDown(): void
    {
        FunctionShims::reset();
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    private function service(): TicketEmailExportService
    {
        return $this->app->make(TicketEmailExportService::class);
    }

    private function useFakeImagick(string $blob = '', ?string $readFailure = null): void
    {
        if (\extension_loaded('imagick')) {
            $this->markTestSkipped('Real ext-imagick is loaded; the fake Imagick is not used.');
        }

        \Imagick::$blob = $blob;
        \Imagick::$readFailure = $readFailure;
        FunctionShims::override('extension_loaded', fn (string $ext) => $ext === 'imagick' ? true : \extension_loaded($ext));
    }

    /**
     * @return array{0: Transaction, 1: Ticket, 2: Event}
     */
    private function makeTicket(array $ticketAttributes = [], array $eventAttributes = []): array
    {
        $user = $this->createCustomer();
        $event = $this->createEvent(array_merge([
            'event_name' => 'Summer Jam 2026',
            'address' => 'Mall of Asia Arena',
        ], $eventAttributes));
        $schedule = $this->createSchedule($event, ['date_from' => '2026-12-25', 'date_to' => '2026-12-25']);
        $scheduleTime = $this->createScheduleTime($schedule, ['time_start' => '19:00:00', 'time_end' => '21:30:00']);
        $eventTicket = $this->createEventTicket($event, ['name' => 'VIP', 'price' => 250]);
        $transaction = $this->createTransaction($user, $event, [
            'order_number' => 'ORD-SUMMER-1',
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
        ]);
        $ticket = $this->createTicket($transaction, $eventTicket, array_merge([
            'ticket_number' => 'TCK-0001',
            'qr_code' => 'QR-SUMMER-0001',
            'row' => 'B',
            'col' => '7',
        ], $ticketAttributes));

        return [$transaction->fresh(), $ticket->fresh(), $event];
    }

    private function idHex(Ticket $ticket): string
    {
        return Str::lower(substr(str_replace('-', '', $ticket->uuid), 0, 8));
    }

    private function qrCacheFile(string $cacheKey, string $payload): string
    {
        $binary = (new TicketQrPngService)->pngBinary($payload);

        return storage_path('app/dompdf-qr-cache') . DIRECTORY_SEPARATOR
            . hash('sha256', $cacheKey . "\0" . hash('sha256', $binary)) . '.png';
    }

    /**
     * Dompdf reads POSIX cache files through file:// URLs and Windows ones as plain drive paths.
     */
    private function qrFileSrc(string $cacheFile): string
    {
        $normalized = str_replace('\\', '/', realpath($cacheFile));

        return str_starts_with($normalized, '/') ? 'file://' . $normalized : $normalized;
    }

    private function qrDataUri(string $payload): string
    {
        return 'data:image/png;base64,' . base64_encode((new TicketQrPngService)->pngBinary($payload));
    }

    /**
     * Dompdf site/activity logos are cached under storage and passed as file paths (not data URIs).
     */
    private function cachedImageSrc(string $binary, string $cacheKey): string
    {
        $ext = str_starts_with($binary, "\x89PNG") ? 'png' : 'jpg';
        $cacheFile = storage_path('app/dompdf-qr-cache') . DIRECTORY_SEPARATOR
            . hash('sha256', $cacheKey . "\0" . hash('sha256', $binary)) . '.' . $ext;

        return $this->qrFileSrc($cacheFile);
    }

    private function defaultSiteLogoSrc(): string
    {
        $path = (new TicketQrPngService)->logoAbsolutePath();
        $binary = (string) file_get_contents($path);

        return $this->cachedImageSrc($binary, 'local:' . $path);
    }

    private function createCoupon(Ticket $ticket, string $name, string $qr, string $createdAt): TicketCoupon
    {
        $coupon = TicketCoupon::create([
            'user_uuid' => $ticket->user_uuid,
            'ticket_uuid' => $ticket->uuid,
            'event_uuid' => $ticket->event_uuid,
            'event_ticket_coupon_uuid' => (string) Str::uuid(),
            'name' => $name,
            'qr_code' => $qr,
        ]);
        $coupon->forceFill(['created_at' => $createdAt])->save();

        return $coupon;
    }

    #[Test]
    public function buildAttachmentRendersPdfCardWhenImagickIsUnavailable(): void
    {
        FunctionShims::override('extension_loaded', fn (string $ext) => $ext === 'imagick' ? false : \extension_loaded($ext));
        [$transaction, $ticket] = $this->makeTicket();
        $ticket->load('event');

        $result = $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertSame('ticket-summer_jam_2026-' . $this->idHex($ticket) . '.pdf', $result['filename']);
        $this->assertStringStartsWith('%PDF-', $result['data']);

        $data = $this->viewData['pdf.ticket-email-card'];
        $this->assertSame('Summer Jam 2026', $data['eventName']);
        $this->assertSame('TCK-0001', $data['ticketNumber']);
        $this->assertSame('Mall of Asia Arena', $data['venue']);
        $this->assertSame('VIP', $data['ticketType']);
        $this->assertSame('ORD-SUMMER-1', $data['orderNumber']);
        $this->assertSame('QR-SUMMER-0001', $data['qrText']);
        $this->assertSame('₱250.00', $data['priceFormatted']);
        $this->assertSame('December 25, 2026', $data['scheduleDateFormatted']);
        $this->assertSame('FRIDAY', $data['scheduleDayUpper']);
        $this->assertSame('7:00 PM - 9:30 PM', $data['scheduleRange']);
        $this->assertSame('B-7', $data['seat']);
        $this->assertNull($data['eventImageDataUri']);
        $this->assertSame($this->defaultSiteLogoSrc(), $data['siteLogoDataUri']);

        $cacheFile = $this->qrCacheFile('ticket:' . $ticket->uuid, 'QR-SUMMER-0001');
        $this->assertFileExists($cacheFile);
        $this->assertSame($this->qrFileSrc($cacheFile), $data['qrImageSrc']);
        $this->assertSame('Date of visit', $data['dateLabel']);
        $this->assertSame('Attendee', $data['attendeeName']);
    }

    #[Test]
    public function buildSponsorPageAttachmentPresentsAGiftCertificate(): void
    {
        FunctionShims::override('extension_loaded', fn (string $ext) => $ext === 'imagick' ? false : \extension_loaded($ext));
        [$transaction, $ticket] = $this->makeTicket();
        $ticket->load('event');

        $result = $this->service()->buildSponsorPageAttachment($transaction, $ticket);

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertSame('gift-certificate-ticket-summer_jam_2026-' . $this->idHex($ticket) . '.pdf', $result['filename']);
        $this->assertStringStartsWith('%PDF-', $result['data']);

        $data = $this->viewData['pdf.sponsor-ticket-page'];
        $this->assertSame('SPONSOR GIFT CERTIFICATE', $data['ticketHeaderLabel']);
        $this->assertSame('SPONSOR GIFT CERTIFICATE', $data['ticketPassLabel']);
        $this->assertSame('This is your gift certificate ticket. Do not duplicate.', $data['ticketFooterText']);
        $this->assertSame('Gift certificate admission', $data['termsClosingLabel']);
    }

    #[Test]
    public function buildAttachmentShowsTheTicketsOwnVisitDayLocationAndGuest(): void
    {
        [$transaction, $ticket, $event] = $this->makeTicket([
            'valid_until' => '2027-01-15 23:59:59',
            'visit_policy' => 'flexible',
            'attendee_name' => 'Maria Santos',
        ]);
        $location = EventLocation::create(['event_uuid' => $event->uuid, 'name' => 'PAEC Bay Area Branch', 'city' => 'Pasay']);
        $ticket->update(['event_location_uuid' => $location->uuid]);

        $this->service()->buildAttachment($transaction, $ticket->fresh());

        $data = $this->viewData['pdf.ticket-email-card'];
        $this->assertSame('PAEC Bay Area Branch', $data['venue']);
        $this->assertSame('Valid until', $data['dateLabel']);
        $this->assertSame('January 15, 2027', $data['scheduleDateFormatted']);
        $this->assertSame('FRIDAY', $data['scheduleDayUpper']);
        $this->assertSame('Maria Santos', $data['attendeeName']);
    }

    #[Test]
    public function buildAttachmentCropsAndShrinksTheEventImageToTheTicketFrame(): void
    {
        $poster = imagecreatetruecolor(1200, 1800);
        ob_start();
        imagepng($poster);
        $png = (string) ob_get_clean();
        Http::fake(['cdn.test/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        [$transaction, $ticket, $event] = $this->makeTicket();
        $event->setRelation('portraitImage', new Upload(['path' => 'https://cdn.test/poster.png']));
        $ticket->setRelation('event', $event);

        $this->service()->buildAttachment($transaction, $ticket);

        $src = $this->viewData['pdf.ticket-email-card']['eventImageDataUri'];
        $this->assertStringStartsWith('data:image/jpeg;base64,', $src);
        $jpeg = base64_decode(substr($src, strlen('data:image/jpeg;base64,')));
        $this->assertSame([432, 400], array_slice(getimagesizefromstring($jpeg), 0, 2));
        $this->assertLessThan(strlen($png), strlen($jpeg));
    }

    #[Test]
    public function buildAttachmentReturnsPngWhenImagickConvertsThePdf(): void
    {
        $this->useFakeImagick("\x89PNG-fake-card");
        [$transaction, $ticket] = $this->makeTicket();

        $result = $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame([
            'data' => "\x89PNG-fake-card",
            'filename' => 'ticket-summer_jam_2026-' . $this->idHex($ticket) . '.png',
            'mime' => 'image/png',
        ], $result);
        $this->assertSame([
            'setResolution:144x144',
            'readImageBlob:%PDF-',
            'setIteratorIndex:0',
            'setImageBackgroundColor:white',
            'setImageAlphaChannel:' . \Imagick::ALPHACHANNEL_REMOVE,
            'mergeImageLayers:' . \Imagick::LAYERMETHOD_FLATTEN,
            'setImageFormat:png',
            'clear',
            'destroy',
        ], \Imagick::$calls);
    }

    #[Test]
    public function buildAttachmentFallsBackToPdfWhenImagickProducesEmptyBlob(): void
    {
        $this->useFakeImagick('');
        [$transaction, $ticket] = $this->makeTicket();

        $result = $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertContains('destroy', \Imagick::$calls);
    }

    #[Test]
    public function buildAttachmentFallsBackToPdfWhenImagickCannotReadPdf(): void
    {
        $this->useFakeImagick('unused', 'Ghostscript is not installed');
        Log::spy();
        [$transaction, $ticket] = $this->makeTicket();

        $result = $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame('application/pdf', $result['mime']);
        Log::shouldHaveReceived('debug')->with(
            'Ticket export: PDF→PNG skipped (Imagick/Ghostscript)',
            ['error' => 'Ghostscript is not installed'],
        )->once();
    }

    #[Test]
    public function buildAttachmentUsesDefaultsWhenEventScheduleAndQrAreMissing(): void
    {
        [$transaction, $ticket] = $this->makeTicket([
            'qr_code' => '',
            'row' => null,
            'col' => null,
            'ticket_number' => null,
            'status' => 'cancelled',
        ]);
        $transaction->setRelation('event', null);
        $transaction->setRelation('schedule', null);
        $transaction->setRelation('scheduleTime', null);

        $result = $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame('ticket-ticket-' . $this->idHex($ticket) . '.pdf', $result['filename']);
        $data = $this->viewData['pdf.ticket-email-card'];
        $this->assertSame('Event', $data['eventName']);
        $this->assertNull($data['qrImageSrc']);
        $this->assertSame('', $data['qrText']);
        $this->assertSame('—', $data['ticketNumber']);
        $this->assertSame('—', $data['venue']);
        $this->assertSame('—', $data['seat']);
        $this->assertSame('—', $data['scheduleDateFormatted']);
        $this->assertSame('', $data['scheduleDayUpper']);
        $this->assertSame('—', $data['scheduleRange']);
        $this->assertNotNull($data['siteLogoDataUri']);
    }

    public static function scheduleTimeRanges(): array
    {
        return [
            'both missing' => [null, null, '—'],
            'start only' => ['19:00:00', null, '7:00 PM'],
            'end only via empty start' => ['', '21:00:00', '9:00 PM'],
            'unparseable start kept verbatim' => ['not-a-time', '21:30:00', 'not-a-time - 9:30 PM'],
        ];
    }

    #[Test]
    #[DataProvider('scheduleTimeRanges')]
    public function buildAttachmentFormatsScheduleTimeRange(?string $start, ?string $end, string $expected): void
    {
        [$transaction, $ticket] = $this->makeTicket();
        $transaction->setRelation('scheduleTime', (new ScheduleTime)->setRawAttributes([
            'time_start' => $start,
            'time_end' => $end,
        ]));

        $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame($expected, $this->viewData['pdf.ticket-email-card']['scheduleRange']);
    }

    public static function eventImageResponses(): array
    {
        return [
            'image with declared mime' => [200, 'IMGBYTES', 'image/webp', 'data:image/webp;base64,' . base64_encode('IMGBYTES')],
            'non-image content type assumes jpeg' => [200, 'IMGBYTES', 'application/octet-stream', 'data:image/jpeg;base64,' . base64_encode('IMGBYTES')],
            'empty body' => [200, '', 'image/png', null],
            'not found' => [404, 'missing', 'text/plain', null],
        ];
    }

    #[Test]
    #[DataProvider('eventImageResponses')]
    public function buildAttachmentEmbedsPortraitImage(int $status, string $body, string $mime, ?string $expected): void
    {
        Http::fake(['cdn.test/*' => Http::response($body, $status, ['Content-Type' => $mime])]);
        [$transaction, $ticket, $event] = $this->makeTicket();
        $event->setRelation('portraitImage', new Upload(['path' => 'https://cdn.test/poster.jpg']));
        $ticket->setRelation('event', $event);

        $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame($expected, $this->viewData['pdf.ticket-email-card']['eventImageDataUri']);
        Http::assertSent(fn ($request) => $request->url() === 'https://cdn.test/poster.jpg'
            && $request->hasHeader('Accept', 'image/*'));
    }

    #[Test]
    public function buildAttachmentSkipsPortraitImageWhenFetchFails(): void
    {
        Http::fake(['cdn.test/*' => Http::failedConnection('Could not resolve host')]);
        Log::spy();
        [$transaction, $ticket, $event] = $this->makeTicket();
        $event->setRelation('portraitImage', new Upload(['path' => 'https://cdn.test/poster.jpg']));
        $ticket->setRelation('event', $event);

        $result = $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertNull($this->viewData['pdf.ticket-email-card']['eventImageDataUri']);
        Log::shouldHaveReceived('debug')->withArgs(
            fn ($message, $context) => $message === 'Ticket export: event image fetch failed'
                && $context['url'] === 'https://cdn.test/poster.jpg'
                && str_contains($context['error'], 'Could not resolve host')
        )->once();
    }

    #[Test]
    public function buildAttachmentPrefersPublicSiteLogoWithDetectedMime(): void
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagegif($image);
        $gif = (string) ob_get_clean();
        File::ensureDirectoryExists(public_path('images/logo'));
        File::put(public_path('images/logo/paec-logo.png'), $gif);
        [$transaction, $ticket] = $this->makeTicket();

        $this->service()->buildAttachment($transaction, $ticket);

        $src = $this->viewData['pdf.ticket-email-card']['siteLogoDataUri'];
        $this->assertIsString($src);
        $this->assertStringNotContainsString('data:', $src);
        $this->assertFileExists($src);
        $this->assertSame($gif, (string) file_get_contents($src));
    }

    #[Test]
    public function buildAttachmentFallsBackToQrLogoWhenPublicLogoIsEmpty(): void
    {
        File::ensureDirectoryExists(public_path('images/logo'));
        File::put(public_path('images/logo/Paec-Logo.png'), '');
        File::put(public_path('images/logo/paec-logo.png'), '');
        [$transaction, $ticket] = $this->makeTicket();

        $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame(
            $this->defaultSiteLogoSrc(),
            $this->viewData['pdf.ticket-email-card']['siteLogoDataUri'],
        );
    }

    #[Test]
    public function qrSourceFallsBackToDataUriWhenCacheDirectoryIsNotWritable(): void
    {
        File::put(storage_path('app/dompdf-qr-cache'), 'not a directory');
        [$transaction, $ticket] = $this->makeTicket();

        $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame($this->qrDataUri('QR-SUMMER-0001'), $this->viewData['pdf.ticket-email-card']['qrImageSrc']);
    }

    #[Test]
    public function qrSourceFallsBackToDataUriWhenCachedFileIsEmpty(): void
    {
        [$transaction, $ticket] = $this->makeTicket();
        $cacheFile = $this->qrCacheFile('ticket:' . $ticket->uuid, 'QR-SUMMER-0001');
        File::ensureDirectoryExists(dirname($cacheFile));
        File::put($cacheFile, '');

        $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame($this->qrDataUri('QR-SUMMER-0001'), $this->viewData['pdf.ticket-email-card']['qrImageSrc']);
        $this->assertSame(0, filesize($cacheFile));
    }

    #[Test]
    public function qrSourceUsesFileUrlForPosixCachePath(): void
    {
        FunctionShims::override('realpath', fn (string $path) => '/srv/paec/storage/' . basename($path));
        [$transaction, $ticket] = $this->makeTicket();

        $this->service()->buildAttachment($transaction, $ticket);

        $expected = 'file:///srv/paec/storage/' . basename($this->qrCacheFile('ticket:' . $ticket->uuid, 'QR-SUMMER-0001'));
        $this->assertSame($expected, $this->viewData['pdf.ticket-email-card']['qrImageSrc']);
    }

    #[Test]
    public function qrSourceFallsBackToDataUriForUnresolvableStreamPath(): void
    {
        $this->app->useStoragePath('file://' . str_replace('\\', '/', $this->sandbox) . '/stream-storage');
        [$transaction, $ticket] = $this->makeTicket();

        $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame($this->qrDataUri('QR-SUMMER-0001'), $this->viewData['pdf.ticket-email-card']['qrImageSrc']);
        $this->assertFileExists($this->qrCacheFile('ticket:' . $ticket->uuid, 'QR-SUMMER-0001'));
    }

    #[Test]
    public function qrSourceFallsBackToDataUriWhenFilesystemThrows(): void
    {
        FunctionShims::override('is_file', fn (string $path) => throw new \RuntimeException('stat failed'));
        [$transaction, $ticket] = $this->makeTicket();

        $result = $this->service()->buildAttachment($transaction, $ticket);

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertSame($this->qrDataUri('QR-SUMMER-0001'), $this->viewData['pdf.ticket-email-card']['qrImageSrc']);
    }

    #[Test]
    public function buildAttachmentReturnsNullAndLogsWhenRenderingFails(): void
    {
        Log::spy();
        View::composer('pdf.ticket-email-card', fn () => throw new \RuntimeException('template broke'));
        [$transaction, $ticket] = $this->makeTicket();

        $this->assertNull($this->service()->buildAttachment($transaction, $ticket));

        Log::shouldHaveReceived('error')->withArgs(
            fn ($message, $context) => $message === 'Ticket email export failed'
                && $context['ticket'] === $ticket->uuid
                && str_contains($context['error'], 'template broke')
        )->once();
    }

    #[Test]
    public function buildCouponsAttachmentReturnsNullWithoutCouponQrCodes(): void
    {
        [$transaction, $ticket] = $this->makeTicket();
        $this->createCoupon($ticket, 'Blank QR', '', '2026-10-01 10:00:00');

        $this->assertNull($this->service()->buildCouponsAttachment($transaction, $ticket));
        $this->assertArrayNotHasKey('pdf.ticket-coupons-email-pack', $this->viewData);
    }

    #[Test]
    public function buildCouponsAttachmentRendersMultiPagePdfWithoutPngConversion(): void
    {
        $this->useFakeImagick("\x89PNG-should-not-be-used");
        [$transaction, $ticket] = $this->makeTicket();
        $ticket->load('event');
        $drink = $this->createCoupon($ticket, 'Free Drink', 'CPN-DRINK', '2026-10-01 10:00:00');
        $this->createCoupon($ticket, 'Free Snack', 'CPN-SNACK', '2026-10-01 11:00:00');
        $this->createCoupon($ticket, 'Ignored', '', '2026-10-01 09:00:00');

        $result = $this->service()->buildCouponsAttachment($transaction, $ticket);

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertSame('coupons-summer_jam_2026-' . $this->idHex($ticket) . '.pdf', $result['filename']);
        $this->assertStringStartsWith('%PDF-', $result['data']);
        $this->assertSame([], \Imagick::$calls);

        $data = $this->viewData['pdf.ticket-coupons-email-pack'];
        $this->assertSame('Summer Jam 2026', $data['eventName']);
        $this->assertSame(['Free Drink', 'Free Snack'], array_column($data['coupons'], 'name'));
        $this->assertSame(['CPN-DRINK', 'CPN-SNACK'], array_column($data['coupons'], 'qrText'));
        $cacheFile = $this->qrCacheFile('coupon:' . $drink->uuid, 'CPN-DRINK');
        $this->assertSame($this->qrFileSrc($cacheFile), $data['coupons'][0]['qrImageSrc']);
    }

    #[Test]
    public function buildCouponsAttachmentReturnsPngForSingleCoupon(): void
    {
        $this->useFakeImagick("\x89PNG-fake-coupon");
        [$transaction, $ticket] = $this->makeTicket();
        $this->createCoupon($ticket, 'Free Drink', 'CPN-DRINK', '2026-10-01 10:00:00');

        $result = $this->service()->buildCouponsAttachment($transaction, $ticket);

        $this->assertSame([
            'data' => "\x89PNG-fake-coupon",
            'filename' => 'coupons-summer_jam_2026-' . $this->idHex($ticket) . '.png',
            'mime' => 'image/png',
        ], $result);
    }

    #[Test]
    public function buildCouponsAttachmentUsesDefaultNamesWithoutEvent(): void
    {
        [$transaction, $ticket] = $this->makeTicket();
        $this->createCoupon($ticket, 'Free Drink', 'CPN-DRINK', '2026-10-01 10:00:00');
        $transaction->setRelation('event', null);

        $result = $this->service()->buildCouponsAttachment($transaction, $ticket);

        $this->assertSame('coupons-event-' . $this->idHex($ticket) . '.pdf', $result['filename']);
        $this->assertSame('Event', $this->viewData['pdf.ticket-coupons-email-pack']['eventName']);
    }

    #[Test]
    public function buildCouponsAttachmentReturnsNullAndLogsWhenRenderingFails(): void
    {
        Log::spy();
        View::composer('pdf.ticket-coupons-email-pack', fn () => throw new \RuntimeException('pack broke'));
        [$transaction, $ticket] = $this->makeTicket();
        $this->createCoupon($ticket, 'Free Drink', 'CPN-DRINK', '2026-10-01 10:00:00');

        $this->assertNull($this->service()->buildCouponsAttachment($transaction, $ticket));

        Log::shouldHaveReceived('error')->withArgs(
            fn ($message, $context) => $message === 'Ticket coupon email export failed'
                && $context['ticket'] === $ticket->uuid
                && str_contains($context['error'], 'pack broke')
        )->once();
    }
}
