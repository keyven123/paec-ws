<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page { margin: 18pt; size: A4 portrait; }
        html, body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            font-family: DejaVu Sans, sans-serif;
            color: #1e1b4b;
        }
        table { border-collapse: collapse; }
        .label {
            margin: 0 0 2pt 0;
            font-size: 7.5px;
            font-weight: bold;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: #7c3aed;
        }
        .value {
            margin: 0;
            font-size: 11px;
            font-weight: bold;
            color: #1e1b4b;
        }
        .muted {
            margin: 2pt 0 0 0;
            font-size: 8.5px;
            font-weight: bold;
            color: #f97316;
        }
        .terms-body {
            margin: 0;
            font-size: 8px;
            color: #1e1b4b;
            line-height: 1.45;
            white-space: pre-line;
        }
    </style>
</head>
<body>
<table width="100%" cellpadding="0" cellspacing="0" style="width:100%;">
    <tr>
        {{-- Left: ticket stub --}}
        <td style="width:56%; vertical-align:top; border:2pt solid #7c3aed; border-radius:6pt;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="padding:14pt 16pt 8pt 16pt; text-align:center; border-bottom:1.5pt dashed #c4b5fd;">
                        @if (! empty($siteLogoDataUri))
                            <img src="{!! $siteLogoDataUri !!}" alt="Activity logo" style="width:130pt; height:auto; max-height:56pt;">
                        @else
                            <p style="margin:0; font-size:16px; font-weight:bold; color:#7c3aed;">{{ $eventName }}</p>
                        @endif
                        <p style="margin:4pt 0 0 0; font-size:8px; font-weight:bold; letter-spacing:1px; color:#f97316;">
                            SPONSOR E-TICKET
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:12pt 16pt 6pt 16pt; text-align:center;">
                        @if (! empty($qrImageSrc))
                            <div style="display:inline-block; background:#ffffff; border:1.5pt solid #ede9fe; border-radius:8pt; padding:6pt;">
                                <img src="{!! $qrImageSrc !!}" alt="QR" width="120" height="120" style="width:120pt; height:120pt; display:block;">
                            </div>
                            <p style="margin:6pt 0 0 0; font-family:DejaVu Sans Mono, monospace; font-size:10px; font-weight:bold; color:#1e1b4b;">
                                {{ $ticketNumber }}
                            </p>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:8pt 18pt 4pt 18pt; text-align:center;">
                        <p style="margin:0; font-size:8px; font-weight:bold; letter-spacing:1.2px; color:#f97316; text-transform:uppercase;">
                            {{ $organizationName }}
                        </p>
                        <p style="margin:6pt 0 2pt 0; font-size:18px; font-weight:bold; color:#1e1b4b; line-height:1.2;">
                            {{ $eventName }}
                        </p>
                        <p style="margin:0; font-size:11px; font-weight:bold; color:#7c3aed;">
                            {{ $scheduleDateFormatted }}@if ($scheduleDayUpper !== '') · {{ $scheduleDayUpper }}@endif
                        </p>
                        @if ($scheduleRange !== '—')
                            <p style="margin:2pt 0 0 0; font-size:10px; font-weight:bold; color:#6d28d9;">
                                {{ $scheduleRange }}
                            </p>
                        @endif
                        <p style="margin:6pt 0 0 0; font-size:10px; font-weight:bold; color:#1e1b4b;">
                            {{ $venue }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10pt 18pt 4pt 18pt;">
                        <table width="100%" cellpadding="0" cellspacing="0" style="background:#faf5ff; border-radius:6pt;">
                            <tr>
                                <td style="padding:10pt 12pt; text-align:center; border-bottom:1pt solid #ede9fe;">
                                    <p class="label">Ticket type</p>
                                    <p style="margin:0; font-size:14px; font-weight:bold; color:#7c3aed;">{{ $ticketType }}</p>
                                    <p class="muted">Section: {{ $sectionLabel }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:10pt 12pt;">
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="width:55%; vertical-align:top; padding-right:8pt;">
                                                <p class="label">Issued to</p>
                                                <p class="value">{{ $attendeeName }}</p>
                                            </td>
                                            <td style="width:45%; vertical-align:top;">
                                                <p class="label">Price</p>
                                                <p class="value" style="color:#f97316;">{{ $priceFormatted }}</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding-top:8pt; vertical-align:top;">
                                                <p class="label">Order</p>
                                                <p class="value" style="font-size:9.5px;">{{ $orderNumber }}</p>
                                            </td>
                                            <td style="padding-top:8pt; vertical-align:top;">
                                                <p class="label">Ticket count</p>
                                                <p class="value">{{ $ticketIndex }} of {{ $ticketCount }}</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10pt 16pt 8pt 16pt; text-align:center;">
                        @if (! empty($qrImageSrc))
                            <div style="display:inline-block; background:#ffffff; border:1pt solid #ede9fe; border-radius:6pt; padding:4pt;">
                                <img src="{!! $qrImageSrc !!}" alt="QR" width="72" height="72" style="width:72pt; height:72pt; display:block;">
                            </div>
                            <p style="margin:4pt 0 0 0; font-family:DejaVu Sans Mono, monospace; font-size:8px; color:#6d28d9;">
                                {{ $qrText }}
                            </p>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="background:#7c3aed; padding:8pt 12pt; text-align:center;">
                        <p style="margin:0; font-size:9px; font-weight:bold; letter-spacing:0.8px; color:#ffffff;">
                            This is your sponsor ticket. Do not duplicate.
                        </p>
                    </td>
                </tr>
            </table>
        </td>

        <td style="width:2%;"></td>

        {{-- Right: poster + terms + activity brand --}}
        <td style="width:42%; vertical-align:top;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="border:2pt solid #7c3aed; border-radius:6pt; overflow:hidden; height:240pt; background:#faf5ff; text-align:center; vertical-align:middle;">
                        @if (! empty($eventImageDataUri))
                            <img src="{{ $eventImageDataUri }}" alt="Event" style="width:100%; height:240pt;">
                        @else
                            <div style="padding:90pt 16pt;">
                                <p style="margin:0; font-size:12px; font-weight:bold; color:#7c3aed;">{{ $eventName }}</p>
                                <p style="margin:8pt 0 0 0; font-size:9px; font-weight:bold; color:#f97316;">SPONSOR PASS</p>
                            </div>
                        @endif
                    </td>
                </tr>

                <tr><td style="height:10pt;"></td></tr>

                <tr>
                    <td style="border:2pt solid #f97316; border-radius:6pt; padding:12pt 14pt; background:#fff7ed; vertical-align:top;">
                        <p style="margin:0 0 8pt 0; font-size:11px; font-weight:bold; color:#c2410c; letter-spacing:0.6px;">
                            TERMS AND CONDITIONS
                        </p>
                        <div class="terms-body">{!! nl2br(e($termsText ?? '')) !!}</div>
                        <p style="margin:8pt 0 0 0; font-size:8px; font-weight:bold; color:#7c3aed;">
                            Complimentary sponsor admission
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td colspan="3" style="padding-top:14pt;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#7c3aed; border-radius:6pt;">
                <tr>
                    <td style="padding:14pt 16pt; text-align:center;">
                        @if (! empty($paecLogoSrc))
                            <img src="{!! $paecLogoSrc !!}" alt="PAEC" style="width:140pt; height:auto; max-height:52pt; background:#ffffff; border-radius:8pt; padding:6pt;">
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
