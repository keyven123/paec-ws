<?php

namespace App\Services;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode as SimpleQrCode;

/**
 * Matches MyTickets: react-qr-code level H + centered thumbnail (Endroid + GD).
 */
final class TicketQrPngService
{
    private const EMAIL_BRAND_LOGO = 'emails/asset/paec_thumbnail.png';

    public function logoAbsolutePath(): string
    {
        return resource_path('views/' . self::EMAIL_BRAND_LOGO);
    }

    /**
     * Write image bytes to a temp file Endroid can read (JPEG/PNG).
     */
    public function writeTempLogoFile(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        $dir = storage_path('app/dompdf-qr-cache');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ext = str_starts_with($binary, "\x89PNG") ? 'png' : 'jpg';
        $path = $dir . DIRECTORY_SEPARATOR . hash('sha256', $binary) . '-center-logo.' . $ext;

        try {
            if (! is_file($path) && @file_put_contents($path, $binary) === false) {
                return null;
            }

            return is_readable($path) ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Raw PNG bytes for embedding (e.g. PDF/PNG ticket export), or null if only SVG is available.
     *
     * @param  string|null  $centerLogoPath  Absolute path to activity logo; defaults to PAEC mark.
     */
    public function pngBinary(?string $qrPayload, ?string $centerLogoPath = null): ?string
    {
        if ($qrPayload === null || $qrPayload === '') {
            return null;
        }

        $logoPath = ($centerLogoPath !== null && is_readable($centerLogoPath))
            ? $centerLogoPath
            : $this->logoAbsolutePath();

        try {
            $qrCode = QrCode::create($qrPayload)
                ->setEncoding(new Encoding('UTF-8'))
                ->setSize(220)
                ->setMargin(1)
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High);

            $logo = null;
            if (is_readable($logoPath)) {
                $logo = Logo::create($logoPath)
                    ->setResizeToWidth(44)
                    ->setPunchoutBackground(true);
            }

            try {
                return (new PngWriter)->write($qrCode, $logo)->getString();
            } catch (\Throwable $e) {
                if ($logo !== null) {
                    Log::debug('Ticket QR PNG with logo failed; retrying without logo', ['error' => $e->getMessage()]);

                    return (new PngWriter)->write($qrCode)->getString();
                }

                throw $e;
            }
        } catch (\Throwable $e) {
            Log::debug('Ticket QR PNG (Endroid) failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * data:image/... for HTML email &lt;img src&gt;.
     */
    public function dataUri(?string $qrPayload): ?string
    {
        if ($qrPayload === null || $qrPayload === '') {
            return null;
        }

        $bin = $this->pngBinary($qrPayload);
        if ($bin !== null) {
            return 'data:image/png;base64,' . base64_encode($bin);
        }

        try {
            $qrSvg = SimpleQrCode::format('svg')->size(220)->margin(1)->errorCorrection('H')->generate($qrPayload);

            return 'data:image/svg+xml;base64,' . base64_encode((string) $qrSvg);
        } catch (\Throwable $e) {
            Log::error('Ticket QR SVG fallback failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
