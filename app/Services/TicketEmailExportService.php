<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketCoupon;
use App\Models\Transaction;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds a ticket card like MyTickets PNG export: Dompdf HTML → PDF → PNG (Imagick) when possible.
 */
final class TicketEmailExportService
{
    public function __construct(
        private readonly TicketQrPngService $qrPngService,
    ) {
        //
    }

    /**
     * @return array{data: string, filename: string, mime: string}|null
     */
    public function buildAttachment(Transaction $transaction, Ticket $ticket): ?array
    {
        try {
            $html = view('pdf.ticket-email-card', $this->viewData($transaction, $ticket))->render();

            $options = new Options;
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('chroot', base_path());

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper([0.0, 0.0, 936.0, 266.0]);
            $dompdf->render();
            $pdf = $dompdf->output();

            $filenameBase = $this->attachmentBaseName($transaction, $ticket);

            $png = $this->pdfBlobToPng($pdf);
            if ($png !== null) {
                return [
                    'data' => $png,
                    'filename' => $filenameBase . '.png',
                    'mime' => 'image/png',
                ];
            }

            return [
                'data' => $pdf,
                'filename' => $filenameBase . '.pdf',
                'mime' => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            Log::error('Ticket email export failed', [
                'ticket' => $ticket->uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * One PDF per ticket with all coupons for that ticket (one coupon per page).
     *
     * @return array{data: string, filename: string, mime: string}|null
     */
    public function buildCouponsAttachment(Transaction $transaction, Ticket $ticket): ?array
    {
        $coupons = TicketCoupon::query()
            ->where('ticket_uuid', $ticket->uuid)
            ->whereNotNull('qr_code')
            ->where('qr_code', '!=', '')
            ->orderBy('created_at')
            ->get();

        if ($coupons->isEmpty()) {
            return null;
        }

        try {
            $html = view(
                'pdf.ticket-coupons-email-pack',
                $this->couponPackViewData($transaction, $ticket, $coupons),
            )->render();

            $options = new Options;
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('chroot', base_path());

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper([0.0, 0.0, 260.0, 580.0]);
            $dompdf->render();
            $pdf = $dompdf->output();

            $filenameBase = $this->couponAttachmentBaseName($transaction, $ticket);

            $png = $coupons->count() === 1 ? $this->pdfBlobToPng($pdf) : null;
            if ($png !== null) {
                return [
                    'data' => $png,
                    'filename' => $filenameBase . '.png',
                    'mime' => 'image/png',
                ];
            }

            return [
                'data' => $pdf,
                'filename' => $filenameBase . '.pdf',
                'mime' => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            Log::error('Ticket coupon email export failed', [
                'ticket' => $ticket->uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\TicketCoupon>|\Illuminate\Database\Eloquent\Collection<int, \App\Models\TicketCoupon> $coupons
     * @return array<string, mixed>
     */
    private function couponPackViewData(Transaction $transaction, Ticket $ticket, $coupons): array
    {
        $event = $ticket->relationLoaded('event') ? $ticket->event : $transaction->event;
        $eventName = $event?->event_name ?? 'Event';

        $items = [];
        foreach ($coupons as $coupon) {
            $qrBinary = $this->qrPngService->pngBinary($coupon->qr_code ?? '');
            $items[] = [
                'name' => $coupon->name ?? 'Coupon',
                'qrImageSrc' => $this->resolvePngSrcForDompdf($qrBinary, 'coupon:' . (string) $coupon->uuid),
                'qrText' => $coupon->qr_code ?? '',
            ];
        }

        return [
            'eventName' => $eventName,
            'coupons' => $items,
        ];
    }

    private function couponAttachmentBaseName(Transaction $transaction, Ticket $ticket): string
    {
        $event = $ticket->relationLoaded('event') ? $ticket->event : $transaction->event;
        $slug = Str::slug($event?->event_name ?? 'event', '_');

        $idHex = Str::lower(Str::substr(str_replace('-', '', (string) $ticket->uuid), 0, 8));

        return 'coupons-' . $slug . '-' . $idHex;
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Transaction $transaction, Ticket $ticket): array
    {
        $event = $ticket->relationLoaded('event') ? $ticket->event : $transaction->event;
        $eventName = $event?->event_name ?? 'Event';

        $portraitUrl = $event?->portraitImage?->url;
        $eventImageDataUri = $this->fetchImageAsDataUri($portraitUrl);

        $siteLogoDataUri = $this->fileToDataUri(public_path('images/logo/ticketoc.png'))
            ?? $this->fileToDataUri($this->qrPngService->logoAbsolutePath());

        $qrBinary = $this->qrPngService->pngBinary($ticket->qr_code ?? '');
        $qrImageSrc = $this->resolvePngSrcForDompdf($qrBinary, 'ticket:' . (string) $ticket->uuid);

        $venueName = $ticket->venueSeat?->venue?->name ?? $event?->address ?? '—';

        $price = (float) ($ticket->price ?? $ticket->eventTicket?->price ?? 0);

        $schedule = $transaction->schedule;
        $scheduleTime = $transaction->scheduleTime;

        $scheduleDateFormatted = $schedule?->date_from
            ? Carbon::parse($schedule->date_from)->format('F j, Y')
            : '—';
        $scheduleDayUpper = $schedule?->date_from
            ? strtoupper(Carbon::parse($schedule->date_from)->format('l'))
            : '';

        $scheduleRange = $this->formatScheduleTimeRange($scheduleTime);

        $row = (string) ($ticket->row ?? '');
        $col = (string) ($ticket->col ?? '');
        $seat = ($row !== '' || $col !== '') ? "{$row}-{$col}" : '—';

        return [
            'siteLogoDataUri' => $siteLogoDataUri,
            'eventImageDataUri' => $eventImageDataUri,
            'qrImageSrc' => $qrImageSrc,
            'eventName' => $eventName,
            'ticketNumber' => $ticket->ticket_number ?? '—',
            'venue' => $venueName,
            'ticketType' => $ticket->eventTicket?->name ?? 'Regular',
            'orderNumber' => $transaction->order_number ?? '—',
            'qrText' => $ticket->status === 'active' ? ($ticket->qr_code ?? '') : '',
            'priceFormatted' => '₱' . number_format($price, 2),
            'purchasedAt' => $ticket->created_at?->format('m/d/Y H:i') ?? '—',
            'scheduleDateFormatted' => $scheduleDateFormatted,
            'scheduleDayUpper' => $scheduleDayUpper,
            'scheduleRange' => $scheduleRange,
            'seat' => $seat,
        ];
    }

    /**
     * Dompdf reliably loads local files under chroot; huge data: URIs in img src often fail in PDF output.
     * Cache key must be unique per asset (e.g. ticket:{uuid} vs coupon:{uuid}).
     */
    private function resolvePngSrcForDompdf(?string $qrBinary, string $cacheKey): ?string
    {
        if ($qrBinary === null || $qrBinary === '') {
            return null;
        }

        $dir = storage_path('app/dompdf-qr-cache');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = hash('sha256', $cacheKey . "\0" . hash('sha256', $qrBinary)) . '.png';
        $fullPath = $dir . DIRECTORY_SEPARATOR . $filename;

        try {
            if (! is_file($fullPath)) {
                if (@file_put_contents($fullPath, $qrBinary) === false) {
                    return 'data:image/png;base64,' . base64_encode($qrBinary);
                }
            }

            if (! is_readable($fullPath) || (int) filesize($fullPath) === 0) {
                return 'data:image/png;base64,' . base64_encode($qrBinary);
            }

            $resolved = realpath($fullPath);
            $normalized = str_replace('\\', '/', $resolved !== false ? $resolved : $fullPath);

            if (str_starts_with($normalized, '/')) {
                return 'file://' . $normalized;
            }

            // Windows: C:/...
            if (preg_match('#^[A-Za-z]:/#', $normalized) === 1) {
                return 'file:///' . $normalized;
            }

            return 'data:image/png;base64,' . base64_encode($qrBinary);
        } catch (\Throwable) {
            return 'data:image/png;base64,' . base64_encode($qrBinary);
        }
    }

    private function attachmentBaseName(Transaction $transaction, Ticket $ticket): string
    {
        $event = $ticket->relationLoaded('event') ? $ticket->event : $transaction->event;
        $slug = Str::slug($event?->event_name ?? 'ticket', '_');

        $idHex = Str::lower(Str::substr(str_replace('-', '', (string) $ticket->uuid), 0, 8));

        return 'ticket-' . $slug . '-' . $idHex;
    }

    private function formatScheduleTimeRange(?object $scheduleTime): string
    {
        if ($scheduleTime === null) {
            return '—';
        }

        $start = $scheduleTime->time_start ?? null;
        $end = $scheduleTime->time_end ?? null;
        if ($start === null && $end === null) {
            return '—';
        }

        $to12 = function ($time): string {
            if ($time === null || $time === '') {
                return '';
            }
            try {
                $t = is_string($time) ? Carbon::parse($time) : Carbon::parse((string) $time);

                return $t->format('g:i A');
            } catch (\Throwable) {
                return (string) $time;
            }
        };

        $a = $to12($start);
        $b = $to12($end);

        if ($a !== '' && $b !== '') {
            return "$a - $b";
        }

        return "$a$b";
    }

    private function fileToDataUri(?string $path): ?string
    {
        if ($path === null || $path === '' || ! is_readable($path)) {
            return null;
        }

        $bin = @file_get_contents($path);
        if ($bin === false || $bin === '') {
            return null;
        }

        $mime = 'image/png';
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f !== false) {
                $detected = finfo_buffer($f, $bin);
                finfo_close($f);
                if (is_string($detected) && str_starts_with($detected, 'image/')) {
                    $mime = $detected;
                }
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }

    private function fetchImageAsDataUri(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        try {
            $response = Http::timeout(12)->withHeaders(['Accept' => 'image/*'])->get($url);
            if (! $response->successful()) {
                return null;
            }
            $bin = $response->body();
            if ($bin === '') {
                return null;
            }
            $mime = $response->header('Content-Type');
            if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                $mime = 'image/jpeg';
            }

            return 'data:' . $mime . ';base64,' . base64_encode($bin);
        } catch (\Throwable $e) {
            Log::debug('Ticket export: event image fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function pdfBlobToPng(string $pdf): ?string
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $im = new \Imagick;
            $im->setResolution(144, 144);
            $im->readImageBlob($pdf);
            $im->setIteratorIndex(0);
            $im->setImageBackgroundColor('white');
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageFormat('png');
            $blob = $im->getImageBlob();
            $im->clear();
            $im->destroy();

            return $blob !== '' ? $blob : null;
        } catch (\Throwable $e) {
            Log::debug('Ticket export: PDF→PNG skipped (Imagick/Ghostscript)', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
