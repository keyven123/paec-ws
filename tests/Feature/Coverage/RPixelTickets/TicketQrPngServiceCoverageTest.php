<?php

namespace Tests\Feature\Coverage\RPixelTickets;

require_once __DIR__ . '/Support/function_shims.php';

use App\Services\TicketQrPngService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\RPixelTickets\Support\FunctionShims;
use Tests\TestCase;

class TicketQrPngServiceCoverageTest extends TestCase
{
    /** Fits a version-40/H QR in plain byte mode, but not once Endroid adds the UTF-8 ECI header. */
    private const TOO_BIG_FOR_UTF8_ONLY = 1272;

    /** Exceeds version-40/H capacity in every encoding. */
    private const TOO_BIG_FOR_ANY = 1300;

    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    protected function tearDown(): void
    {
        FunctionShims::reset();

        parent::tearDown();
    }

    private function service(): TicketQrPngService
    {
        return new TicketQrPngService;
    }

    #[Test]
    public function logoPathPointsAtBundledEmailThumbnail(): void
    {
        $path = $this->service()->logoAbsolutePath();

        $this->assertSame(resource_path('views/emails/asset/paec_thumbnail.png'), $path);
        $this->assertFileExists($path);
    }

    #[Test]
    public function pngBinaryReturnsNullForEmptyPayload(): void
    {
        $this->assertNull($this->service()->pngBinary(null));
        $this->assertNull($this->service()->pngBinary(''));
    }

    #[Test]
    public function pngBinaryRendersPngWithLogo(): void
    {
        $png = $this->service()->pngBinary('TICKET-QR-123');

        $this->assertStringStartsWith(self::PNG_MAGIC, $png);
        $size = getimagesizefromstring($png);
        $this->assertSame('image/png', $size['mime']);
    }

    #[Test]
    public function pngBinaryRetriesWithoutLogoWhenLogoCannotBeDecoded(): void
    {
        Log::spy();
        FunctionShims::override('imagecreatefromstring', fn (string $data) => false);

        $png = $this->service()->pngBinary('TICKET-QR-456');

        $this->assertStringStartsWith(self::PNG_MAGIC, $png);
        Log::shouldHaveReceived('debug')->withArgs(
            fn ($message, $context) => $message === 'Ticket QR PNG with logo failed; retrying without logo'
                && str_contains($context['error'], 'Unable to parse image data')
        )->once();
    }

    #[Test]
    public function pngBinaryReturnsNullWhenPayloadTooLargeAndLogoMissing(): void
    {
        Log::spy();
        $logo = $this->service()->logoAbsolutePath();
        FunctionShims::override('is_readable', fn (string $path) => $path === $logo ? false : \is_readable($path));

        $this->assertNull($this->service()->pngBinary(str_repeat('a', self::TOO_BIG_FOR_ANY)));

        Log::shouldNotHaveReceived('debug', ['Ticket QR PNG with logo failed; retrying without logo', \Mockery::any()]);
        Log::shouldHaveReceived('debug')->withArgs(
            fn ($message, $context) => $message === 'Ticket QR PNG (Endroid) failed'
                && $context['error'] === 'Data too big'
        )->once();
    }

    #[Test]
    public function pngBinaryReturnsNullWhenRetryWithoutLogoAlsoFails(): void
    {
        Log::spy();

        $this->assertNull($this->service()->pngBinary(str_repeat('a', self::TOO_BIG_FOR_ANY)));

        Log::shouldHaveReceived('debug')->with('Ticket QR PNG with logo failed; retrying without logo', \Mockery::any())->once();
        Log::shouldHaveReceived('debug')->with('Ticket QR PNG (Endroid) failed', ['error' => 'Data too big'])->once();
    }

    #[Test]
    public function dataUriReturnsNullForEmptyPayload(): void
    {
        $this->assertNull($this->service()->dataUri(null));
        $this->assertNull($this->service()->dataUri(''));
    }

    #[Test]
    public function dataUriEmbedsPng(): void
    {
        $uri = $this->service()->dataUri('TICKET-QR-789');

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $binary = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
        $this->assertStringStartsWith(self::PNG_MAGIC, $binary);
    }

    #[Test]
    public function dataUriFallsBackToSvgWhenPngCannotBeGenerated(): void
    {
        $uri = $this->service()->dataUri(str_repeat('a', self::TOO_BIG_FOR_UTF8_ONLY));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertStringContainsString('<svg', $svg);
    }

    #[Test]
    public function dataUriReturnsNullWhenSvgFallbackFails(): void
    {
        Log::spy();

        $this->assertNull($this->service()->dataUri(str_repeat('a', self::TOO_BIG_FOR_ANY)));

        Log::shouldHaveReceived('error')->with('Ticket QR SVG fallback failed', ['error' => 'Data too big'])->once();
    }
}
