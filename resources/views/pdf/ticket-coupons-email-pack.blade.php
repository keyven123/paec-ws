<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page { margin: 0 !important; size: 260pt 580pt; }
        html, body { margin: 0; padding: 0; background: #ffffff; font-family: DejaVu Sans, sans-serif; color: #1e1b4b; }
        .page { width: 260pt; height: 580pt; text-align: center; page-break-after: always; }
        .page:last-child { page-break-after: auto; }
    </style>
</head>
<body>
@foreach ($coupons as $coupon)
    <div class="page">
        <div style="height:6pt; background:#7c3aed;"></div>
        <div style="padding:26pt 20pt 0 20pt;">
            <p style="margin:0; font-size:8px; font-weight:bold; letter-spacing:1.4px; color:#f97316;">PAEC COUPON</p>
            <p style="margin:8pt 0 0 0; font-size:17px; font-weight:bold; line-height:1.2;">{{ $coupon['name'] }}</p>
            <p style="margin:6pt 0 0 0; font-size:10px; color:#6d28d9; font-weight:bold;">{{ $eventName }}</p>

            <div style="margin:26pt auto 0 auto; width:190pt; padding:10pt; border:2px dashed #c4b5fd; border-radius:12pt;">
                @if (! empty($coupon['qrImageSrc']))
                    <img src="{!! $coupon['qrImageSrc'] !!}" alt="QR" width="170" height="170" style="width:170pt; height:170pt;">
                @else
                    <p style="margin:70pt 0; font-size:10px; color:#a78bfa;">QR code unavailable</p>
                @endif
            </div>

            <p style="margin:12pt 0 0 0; font-family:DejaVu Sans Mono, monospace; font-size:9px; font-weight:bold;">{{ $coupon['qrText'] }}</p>
            <p style="margin:18pt 0 0 0; font-size:9px; line-height:1.5; color:#6b7280;">Show this coupon at the counter to redeem it.</p>
        </div>
    </div>
@endforeach
</body>
</html>
