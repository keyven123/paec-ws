@php
    $data = $data ?? [];
    $emailHeadline = $data['email_headline'] ?? 'Site visit update';
    $emailIntro = $data['email_intro'] ?? '';
    $emailFooterThanks = $data['email_footer_thanks'] ?? 'Thank you for partnering with <strong style="color:#FFD700;">Ticketoc</strong>!';
    $highlightLabel = $data['highlight_label'] ?? 'Visit details';
    $highlightValue = $data['highlight_value'] ?? '—';
    $tip = $data['tip'] ?? '';
    $responseType = $data['response_type'] ?? 'declined';

    $inquiry = $data['inquiry'] ?? [];
    $venue = $data['venue'] ?? [];
    $guest = $data['guest'] ?? [];
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

    $cardClass = match ($responseType) {
        'accepted' => 'highlight-card accepted',
        'suggested' => 'highlight-card suggested',
        default => 'highlight-card declined',
    };
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $data['email_page_title'] ?? 'Site Visit Update' }}</title>
    <style type="text/css">
        body { font-family: 'Segoe UI', Arial, sans-serif; background:#0b0b0b; color:#f3f3f3; margin:0; padding:0; }
        .container { max-width:900px; margin:40px auto; background:#111; border-radius:10px; overflow:hidden; border:1px solid #1f1f1f; }
        .header { background:#000; padding:20px; text-align:center; border-bottom:1px solid #222; }
        .content { padding:24px; line-height:1.55; }
        h2 { color:#FFD700; margin:0 0 10px; }
        .intro { color:#d4d4d8; font-size:15px; margin:0 0 22px; }
        .status-badge { display:inline-block; background:#581c87; color:#fff; border-radius:999px; padding:4px 12px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .card { background:#1a1a1a; border:1px solid #2a2a2a; border-radius:12px; margin-bottom:18px; overflow:hidden; }
        .highlight-card { padding:20px 22px; text-align:center; }
        .highlight-card.accepted { background:linear-gradient(135deg,#064e3b 0%,#022c22 100%); border:1px solid #10b981; }
        .highlight-card.declined { background:linear-gradient(135deg,#7f1d1d 0%,#450a0a 100%); border:1px solid #b91c1c; }
        .highlight-card.suggested { background:linear-gradient(135deg,#312e81 0%,#1e1b4b 100%); border:1px solid #818cf8; }
        .highlight-label { font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; margin:0 0 8px; color:#e5e7eb; }
        .highlight-value { color:#fff; font-size:20px; font-weight:700; margin:0; }
        .detail-grid { width:100%; border-collapse:collapse; }
        .detail-grid td { padding:14px 18px; border-top:1px solid #2a2a2a; font-size:14px; vertical-align:top; }
        .detail-grid tr:first-child td { border-top:none; }
        .detail-label { width:38%; color:#FFD700; font-weight:600; }
        .detail-value { color:#f3f3f3; }
        .tip-box { margin-top:18px; padding:14px 16px; background:#1f2937; border:1px solid #374151; border-radius:8px; color:#d1d5db; font-size:13px; }
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
            <span class="status-badge">{{ $inquiry['status'] ?? 'Update' }}</span>
            @if(!empty($inquiry['reference']))
                <span style="margin-left:10px;color:#a1a1aa;font-size:13px;">Ref #{{ $inquiry['reference'] }}</span>
            @endif
        </p>

        <div class="card {{ $cardClass }}">
            <p class="highlight-label">{{ $highlightLabel }}</p>
            <p class="highlight-value">{{ $highlightValue }}</p>
        </div>

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
            </table>
        </div>

        <p style="margin:22px 0 18px;">
            <a href="{{ $manageUrl }}" target="_blank" rel="noopener" style="display:inline-block;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:600;background:#FFD700;color:#111111 !important;">
                Open inquiry
            </a>
        </p>

        @if(!empty($tip))
            <div class="tip-box">{{ $tip }}</div>
        @endif
    </div>

    <div class="footer">
        <p>{!! $emailFooterThanks !!}<br>This is an automated notice about a customer site visit response on Ticketoc.</p>
        <p>
            <a href="{{ $terms }}">Terms & Conditions</a> |
            <a href="{{ $privacy }}">Privacy Policy</a><br>
            &copy; {{ $year }} Ticketoc. All Rights Reserved.
        </p>
    </div>
</div>
</body>
</html>
