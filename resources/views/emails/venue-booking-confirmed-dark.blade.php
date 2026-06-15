@php
    $data = $data ?? [];
    $emailPageTitle = $data['email_page_title'] ?? 'Booking Confirmed';
    $emailHeadline = $data['email_headline'] ?? 'Your venue booking is confirmed!';
    $emailIntro = $data['email_intro'] ?? '';
    $emailFooterThanks = $data['email_footer_thanks'] ?? 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>!';

    $inquiry = $data['inquiry'] ?? [];
    $venue = $data['venue'] ?? [];
    $guest = $data['guest'] ?? [];
    $booking = $data['booking'] ?? [];

    $viewBookingsUrl = $data['view_bookings_url'] ?? '#';
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

    $venueImageSrc = $venue['image'] ?? null;
    if (! empty($venue['image_embed'])) {
        $emb = $venue['image_embed'];
        $venueImageSrc = $message->embedData($emb['data'], $emb['cidName'], $emb['mime']);
    }
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $emailPageTitle }}</title>
    <style type="text/css">
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #0b0b0b;
            color: #f3f3f3;
            margin: 0;
            padding: 0;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        .container {
            max-width: 900px;
            margin: 40px auto;
            background: #111;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            border: 1px solid #1f1f1f;
        }
        .header {
            background: #000;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #222;
        }
        .header img { max-width: 200px; }
        .content { padding: 24px; color: #fff; line-height: 1.55; }
        h2 { color: #FFD700; margin: 0 0 10px; }
        .intro { color: #d4d4d8; font-size: 15px; margin: 0 0 22px; }
        .status-badge {
            display: inline-block;
            background: #15803d;
            color: #fff;
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .card {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            margin-bottom: 18px;
            overflow: hidden;
        }
        .paid-card {
            padding: 20px 22px;
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            border: 1px solid #10b981;
        }
        .paid-card-label {
            color: #6ee7b7;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin: 0 0 8px;
        }
        .paid-card-amount {
            color: #fff;
            font-size: 26px;
            font-weight: 700;
            margin: 0 0 4px;
        }
        .paid-card-sub { color: #d1fae5; font-size: 14px; margin: 0; }
        .venue-card { display: flex; align-items: stretch; }
        .venue-image { flex: 0 0 34%; background: #000; min-height: 140px; }
        .venue-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .venue-body { flex: 1; padding: 18px 20px; }
        .venue-body h3 { margin: 0 0 8px; color: #fff; font-size: 20px; }
        .venue-meta { color: #d4d4d8; font-size: 14px; }
        .detail-grid { width: 100%; border-collapse: collapse; }
        .detail-grid td {
            padding: 14px 18px;
            border-top: 1px solid #2a2a2a;
            vertical-align: top;
            font-size: 14px;
        }
        .detail-grid tr:first-child td { border-top: none; }
        .detail-label { width: 38%; color: #FFD700; font-weight: 600; }
        .detail-value { color: #f3f3f3; }
        .btn {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
        .footer {
            background: #000;
            color: #888;
            text-align: center;
            padding: 16px;
            font-size: 12px;
            border-top: 1px solid #222;
        }
        .footer a { color: #FFD700; text-decoration: none; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; max-width: 100% !important; margin: 16px auto !important; border-radius: 0 !important; }
            .content { padding: 16px !important; }
            .venue-card { display: block !important; }
            .venue-image { width: 100% !important; min-height: 180px !important; }
            .detail-label, .detail-value { display: block !important; width: 100% !important; padding-bottom: 4px !important; }
            .detail-value { padding-top: 0 !important; padding-bottom: 12px !important; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img
            src="{{ $brandLogoSrc }}"
            alt="Ticketoc"
            width="96"
            height="96"
            style="max-width:96px;height:auto;display:block;margin:0 auto 8px;"
        >
        <h2>{{ $emailHeadline }}</h2>
    </div>

    <div class="content">
        <p class="intro">{{ $emailIntro }}</p>

        <p style="margin:0 0 18px;">
            <span class="status-badge">{{ $inquiry['status'] ?? 'Payment Completed' }}</span>
            @if(!empty($inquiry['reference']))
                <span style="margin-left:10px;color:#a1a1aa;font-size:13px;">Ref #{{ $inquiry['reference'] }}</span>
            @endif
        </p>

        @if(!empty($booking['amount_paid']))
            <div class="card paid-card">
                <p class="paid-card-label">Payment received</p>
                <p class="paid-card-amount">{{ $booking['amount_paid'] }}</p>
                @if(!empty($booking['paid_at']))
                    <p class="paid-card-sub">Paid on {{ $booking['paid_at'] }}</p>
                @endif
                @if(!empty($booking['order_number']))
                    <p class="paid-card-sub">Order #{{ $booking['order_number'] }}</p>
                @endif
            </div>
        @endif

        <div class="card">
            <div class="venue-card">
                <div class="venue-image">
                    @if(!empty($venueImageSrc))
                        <img src="{{ $venueImageSrc }}" alt="{{ $venue['name'] ?? 'Venue' }}">
                    @else
                        <div style="min-height:140px;padding:48px 12px;color:#525252;font-size:12px;text-align:center;">Venue photo</div>
                    @endif
                </div>
                <div class="venue-body">
                    <h3>{{ $venue['name'] ?? 'Venue listing' }}</h3>
                    <div class="venue-meta">
                        @if(!empty($venue['city']))
                            <div><strong style="color:#FFD700;">Location:</strong> {{ $venue['city'] }}@if(!empty($venue['location'])) · {{ $venue['location'] }}@endif</div>
                        @endif
                        @if(!empty($venue['type']))
                            <div style="margin-top:6px;"><strong style="color:#FFD700;">Venue type:</strong> {{ $venue['type'] }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <table role="presentation" class="detail-grid" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="detail-label">Booked by</td>
                    <td class="detail-value">{{ $guest['full_name'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Event date</td>
                    <td class="detail-value">{{ $booking['event_date'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Event type</td>
                    <td class="detail-value">{{ $booking['event_type'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Expected guests</td>
                    <td class="detail-value">{{ !empty($booking['guest_count']) ? number_format($booking['guest_count']) . ' guests' : 'Not specified' }}</td>
                </tr>
            </table>
        </div>

        <p style="margin:22px 0 18px;">
            <a
                href="{{ $viewBookingsUrl }}"
                class="btn"
                target="_blank"
                rel="noopener"
                style="display:inline-block;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:600;background:#15803d;color:#ffffff !important;"
            >
                View my bookings
            </a>
        </p>

        <div style="margin-top:18px;padding:14px 16px;background:#064e3b;border:1px solid #10b981;border-radius:8px;color:#d1fae5;font-size:13px;">
            <strong style="color:#6ee7b7;">What's next?</strong>
            The venue host has been notified of your confirmed booking. Feel free to message the host through your Ticketoc account to coordinate setup, timing, and any special requests for your event.
        </div>
    </div>

    <div class="footer">
        <p>{!! $emailFooterThanks !!}<br>
        This email confirms your venue booking on Ticketoc.</p>
        <p>
            <a href="{{ $terms }}">Terms & Conditions</a> |
            <a href="{{ $privacy }}">Privacy Policy</a><br>
            &copy; {{ $year }} Ticketoc. All Rights Reserved.
        </p>
    </div>
</div>
</body>
</html>
