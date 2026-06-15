@php
    $data = $data ?? [];
    $emailPageTitle = $data['email_page_title'] ?? 'Deposit Received';
    $emailHeadline = $data['email_headline'] ?? 'Deposit received — event date tentatively reserved';
    $emailIntro = $data['email_intro'] ?? '';
    $emailFooterThanks = $data['email_footer_thanks'] ?? 'Thank you for partnering with <strong style="color:#FFD700;">Ticketoc</strong>!';

    $inquiry = $data['inquiry'] ?? [];
    $venue = $data['venue'] ?? [];
    $guest = $data['guest'] ?? [];
    $deposit = $data['deposit'] ?? [];
    $event = $data['event'] ?? [];

    $manageUrl = $data['manage_inquiry_url'] ?? '#';
    $privacy = $data['privacy_policy_link'] ?? '#';
    $terms = $data['tc_link'] ?? '#';
    $year = $data['current_year'] ?? date('Y');

    $logoPath = resource_path('views/emails/asset/ticketoc_thumbnail.png');
    if (is_readable($logoPath)) {
        $brandLogoSrc = $message->embed($logoPath);
    } elseif (is_readable(public_path('images/logo/ticketoc.png'))) {
        $brandLogoSrc = $message->embed(public_path('images/logo/ticketoc.png'));
    } else {
        $brandLogoSrc = asset('images/logo/ticketoc.png');
    }
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $emailPageTitle }}</title>
    <style type="text/css">
        body { font-family: 'Segoe UI', Arial, sans-serif; background:#0b0b0b; color:#f3f3f3; margin:0; padding:0; }
        .container { max-width:900px; margin:40px auto; background:#111; border-radius:10px; overflow:hidden; border:1px solid #1f1f1f; }
        .header { background:#000; padding:20px; text-align:center; border-bottom:1px solid #222; }
        .header img { max-width:200px; }
        .content { padding:24px; line-height:1.55; }
        h2 { color:#FFD700; margin:0 0 10px; }
        .intro { color:#d4d4d8; font-size:15px; margin:0 0 22px; }
        .status-badge { display:inline-block; background:#15803d; color:#fff; border-radius:999px; padding:4px 12px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .card { background:#1a1a1a; border:1px solid #2a2a2a; border-radius:12px; margin-bottom:18px; overflow:hidden; }
        .deposit-card { padding:20px 22px; background:linear-gradient(135deg,#14532d 0%,#052e16 100%); border:1px solid #16a34a; }
        .deposit-label { color:#bbf7d0; font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; margin:0 0 8px; }
        .deposit-amount { color:#fff; font-size:22px; font-weight:700; margin:0; }
        .deposit-paid-at { color:#86efac; font-size:13px; margin:8px 0 0; }
        .detail-grid { width:100%; border-collapse:collapse; }
        .detail-grid td { padding:14px 18px; border-top:1px solid #2a2a2a; font-size:14px; vertical-align:top; }
        .detail-grid tr:first-child td { border-top:none; }
        .detail-label { width:38%; color:#FFD700; font-weight:600; }
        .detail-value { color:#f3f3f3; }
        .tip-box { margin-top:18px; padding:14px 16px; background:#052e16; border:1px solid #16a34a; border-radius:8px; color:#bbf7d0; font-size:13px; }
        .footer { background:#000; color:#888; text-align:center; padding:16px; font-size:12px; border-top:1px solid #222; }
        .footer a { color:#FFD700; text-decoration:none; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="{{ $brandLogoSrc }}" alt="Ticketoc" width="96" height="96" style="max-width:96px;height:auto;display:block;margin:0 auto 8px;">
        <h2>{{ $emailHeadline }}</h2>
    </div>

    <div class="content">
        <p class="intro">{{ $emailIntro }}</p>

        <p style="margin:0 0 18px;">
            <span class="status-badge">{{ $inquiry['status'] ?? 'Deposit Paid' }}</span>
            @if(!empty($inquiry['reference']))
                <span style="margin-left:10px;color:#a1a1aa;font-size:13px;">Ref #{{ $inquiry['reference'] }}</span>
            @endif
        </p>

        @if(!empty($deposit['amount_label']))
            <div class="card deposit-card">
                <p class="deposit-label">Deposit received</p>
                <p class="deposit-amount">{{ $deposit['amount_label'] }}</p>
                @if(!empty($deposit['paid_at']))
                    <p class="deposit-paid-at">Paid on {{ $deposit['paid_at'] }}</p>
                @endif
            </div>
        @endif

        <div class="card">
            <table role="presentation" class="detail-grid" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="detail-label">Venue</td>
                    <td class="detail-value">{{ $venue['name'] ?? 'Venue listing' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Customer</td>
                    <td class="detail-value">{{ $guest['full_name'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Event date</td>
                    <td class="detail-value">{{ $event['date'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Expected guests</td>
                    <td class="detail-value">{{ !empty($event['guest_count']) ? number_format($event['guest_count']) . ' guests' : 'Not specified' }}</td>
                </tr>
            </table>
        </div>

        <p style="margin:22px 0 18px;">
            <a href="{{ $manageUrl }}" target="_blank" rel="noopener" style="display:inline-block;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:600;background:#16a34a;color:#ffffff !important;">
                Open inquiry &amp; send final billing
            </a>
        </p>

        <div class="tip-box">
            <strong style="color:#bbf7d0;">What to do next</strong><br>
            The customer's event date is tentatively reserved. Send final billing from the inquiry workflow when you're ready to collect the remaining balance.
        </div>
    </div>

    <div class="footer">
        <p>{!! $emailFooterThanks !!}<br>This is an automated notice about a venue deposit payment on Ticketoc.</p>
        <p>
            <a href="{{ $terms }}">Terms & Conditions</a> |
            <a href="{{ $privacy }}">Privacy Policy</a><br>
            &copy; {{ $year }} Ticketoc. All Rights Reserved.
        </p>
    </div>
</div>
</body>
</html>
