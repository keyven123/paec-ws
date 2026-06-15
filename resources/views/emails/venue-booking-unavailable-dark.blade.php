@php
    $data = $data ?? [];
    $emailPageTitle = $data['email_page_title'] ?? 'Date No Longer Available';
    $emailHeadline = $data['email_headline'] ?? 'That date just got booked';
    $emailIntro = $data['email_intro'] ?? '';
    $emailFooterThanks = $data['email_footer_thanks'] ?? 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>.';

    $inquiry = $data['inquiry'] ?? [];
    $venue = $data['venue'] ?? [];
    $guest = $data['guest'] ?? [];
    $event = $data['event'] ?? [];

    $venueUrl = $data['venue_url'] ?? '#';
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
            background: #b91c1c;
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
        .unavailable-card {
            padding: 20px 22px;
            background: linear-gradient(135deg, #451a1a 0%, #3b1212 100%);
            border: 1px solid #b91c1c;
        }
        .unavailable-card-label {
            color: #fca5a5;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin: 0 0 8px;
        }
        .unavailable-card-date {
            color: #fff;
            font-size: 22px;
            font-weight: 700;
            margin: 0;
            text-decoration: line-through;
            text-decoration-color: #ef4444;
        }
        .venue-card { display: flex; align-items: stretch; }
        .venue-image { flex: 0 0 34%; background: #000; min-height: 140px; }
        .venue-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .venue-body { flex: 1; padding: 18px 20px; }
        .venue-body h3 { margin: 0 0 8px; color: #fff; font-size: 20px; }
        .venue-meta { color: #d4d4d8; font-size: 14px; }
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
        <p class="intro">
            @if(!empty($guest['full_name']))Hi {{ $guest['full_name'] }},<br><br>@endif
            {{ $emailIntro }}
        </p>

        <p style="margin:0 0 18px;">
            <span class="status-badge">Inquiry Closed</span>
            @if(!empty($inquiry['reference']))
                <span style="margin-left:10px;color:#a1a1aa;font-size:13px;">Ref #{{ $inquiry['reference'] }}</span>
            @endif
        </p>

        @if(!empty($event['date']))
            <div class="card unavailable-card">
                <p class="unavailable-card-label">No longer available</p>
                <p class="unavailable-card-date">{{ $event['date'] }}</p>
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

        <p style="color:#d4d4d8;font-size:15px;margin:0 0 20px;">
            The good news? This beautiful venue may still be available on other dates. Pick a new date that works for you and send a fresh inquiry — we'd be honored to help you bring your event to life.
        </p>

        <p style="margin:0 0 18px;text-align:center;">
            <a
                href="{{ $venueUrl }}"
                class="btn"
                target="_blank"
                rel="noopener"
                style="display:inline-block;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:700;background:#FFD700;color:#0a0a0c !important;font-size:15px;"
            >
                Choose another date
            </a>
        </p>

        <div style="margin-top:18px;padding:14px 16px;background:#1f1f1f;border:1px solid #333;border-radius:8px;color:#d4d4d8;font-size:13px;">
            We're really sorry for the disappointment. If there's anything we can do to help you find the right space, just reply to this email or reach out through your Ticketoc account — we're here for you.
        </div>
    </div>

    <div class="footer">
        <p>{!! $emailFooterThanks !!}<br>
        This email is about a venue inquiry you submitted on Ticketoc.</p>
        <p>
            <a href="{{ $terms }}">Terms & Conditions</a> |
            <a href="{{ $privacy }}">Privacy Policy</a><br>
            &copy; {{ $year }} Ticketoc. All Rights Reserved.
        </p>
    </div>
</div>
</body>
</html>
