<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        /* Dompdf's default @page margin would shrink the ticket canvas. */
        @page { margin: 0 !important; size: 936pt 266pt; }
        html, body { margin: 0; padding: 0; background: #ffffff; font-family: DejaVu Sans, sans-serif; color: #1e1b4b; }
        table { border-collapse: collapse; }
        .label { margin: 0 0 2px 0; font-size: 7.5px; font-weight: bold; letter-spacing: 0.8px; text-transform: uppercase; color: #8b5cf6; }
        .value { margin: 0; font-size: 11px; font-weight: bold; color: #1e1b4b; word-wrap: break-word; }
        .muted { margin: 2px 0 0 0; font-size: 8.5px; font-weight: bold; color: #f97316; }
        .cell { vertical-align: top; padding: 0 10px 10px 0; }
    </style>
</head>
<body>
<table width="936" cellpadding="0" cellspacing="0" style="width:936pt; height:266pt;">
    <tr>
        {{-- Brand + event image --}}
        <td style="width:190pt; vertical-align:top; padding:16pt 14pt; background:#faf5ff; border-right:1px solid #ede9fe;">
            @if (! empty($siteLogoDataUri))
                <div style="text-align:center; margin-bottom:10pt;">
                    <img src="{{ $siteLogoDataUri }}" alt="PAEC" style="width:120pt; height:auto;">
                </div>
            @endif
            <div style="height:150pt; border-radius:10pt; overflow:hidden; background:#ede9fe; text-align:center;">
                @if (! empty($eventImageDataUri))
                    <img src="{{ $eventImageDataUri }}" alt="Event" style="width:100%; height:150pt;">
                @else
                    <div style="padding-top:62pt; font-size:9px; font-weight:bold; color:#a78bfa;">PAEC E-TICKET</div>
                @endif
            </div>
        </td>

        {{-- Details --}}
        <td style="vertical-align:top; padding:0;">
            <div style="height:5pt; background:#7c3aed;"></div>
            <div style="padding:13pt 20pt 0 20pt;">
                <p style="margin:0; font-size:7.5px; font-weight:bold; letter-spacing:1.2px; color:#f97316;">PHILIPPINE AMUSEMENT AND ENTERTAINMENT CORPORATION · E-TICKET</p>
                <p style="margin:5pt 0 2pt 0; font-size:19px; font-weight:bold; color:#1e1b4b; line-height:1.15;">{{ $eventName }}</p>
                <p style="margin:0 0 11pt 0; font-size:10px; font-weight:bold; color:#6d28d9;">{{ $ticketType }}</p>

                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="cell" style="width:36%;">
                            <p class="label">{{ $dateLabel }}</p>
                            <p class="value">{{ $scheduleDateFormatted }}</p>
                            @if ($scheduleDayUpper !== '')
                                <p class="muted">{{ $scheduleDayUpper }}</p>
                            @endif
                        </td>
                        <td class="cell" style="width:32%;">
                            <p class="label">Time</p>
                            <p class="value">{{ $scheduleRange }}</p>
                        </td>
                        <td class="cell" style="width:32%;">
                            <p class="label">Seat</p>
                            <p class="value">{{ $seat }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td class="cell" colspan="2">
                            <p class="label">Location</p>
                            <p class="value">{{ $venue }}</p>
                        </td>
                        <td class="cell">
                            <p class="label">Guest</p>
                            <p class="value">{{ $attendeeName }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td class="cell">
                            <p class="label">Order number</p>
                            <p class="value" style="font-size:10px;">{{ $orderNumber }}</p>
                        </td>
                        <td class="cell">
                            <p class="label">Price</p>
                            <p class="value">{{ $priceFormatted }}</p>
                        </td>
                        <td class="cell">
                            <p class="label">Purchased</p>
                            <p class="value" style="font-size:10px;">{{ $purchasedAt }}</p>
                        </td>
                    </tr>
                </table>
            </div>
        </td>

        {{-- QR stub --}}
        <td style="width:200pt; vertical-align:middle; text-align:center; padding:14pt 12pt; background:#6d28d9; border-left:2px dashed #c4b5fd;">
            @if (! empty($qrImageSrc))
                <div style="display:inline-block; background:#ffffff; border-radius:10pt; padding:7pt;">
                    {{-- file:// or data: URLs must not be HTML-escaped or Dompdf may drop the image --}}
                    <img src="{!! $qrImageSrc !!}" alt="QR" width="150" height="150" style="width:150pt; height:150pt; display:block;">
                </div>
                <p style="margin:8pt 0 0 0; font-size:8px; font-weight:bold; letter-spacing:1px; color:#ddd6fe;">SCAN AT THE ENTRANCE</p>
            @else
                <p style="margin:0; font-size:10px; font-weight:bold; color:#ddd6fe;">QR code unavailable</p>
            @endif
            <p style="margin:6pt 0 0 0; font-family:DejaVu Sans Mono, monospace; font-size:9.5px; font-weight:bold; color:#ffffff;">{{ $ticketNumber }}</p>
            @if ($qrText !== '')
                <p style="margin:2pt 0 0 0; font-family:DejaVu Sans Mono, monospace; font-size:7px; color:#c4b5fd;">{{ $qrText }}</p>
            @endif
        </td>
    </tr>
</table>
</body>
</html>
