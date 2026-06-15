@php
    $data = $data ?? [];
    $emailPageTitle = $data['email_page_title'] ?? 'Event Reminder';
    $emailHeadline = $data['email_headline'] ?? 'Your event is coming up';
    $emailIntro = $data['email_intro'] ?? 'Here is a friendly reminder for your upcoming event.';
    $emailBody = $data['email_body'] ?? '';
    $emailSignoff = $data['email_signoff'] ?? '';
    $emailFooterThanks = $data['email_footer_thanks'] ?? 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>!';
    $reminderType = $data['reminder_type'] ?? null;
    $showQrCodes = (bool) ($data['show_qr_codes'] ?? false);

    $event = [
        'name' => $data['event_name'] ?? 'Event Name',
        'date' => $data['event_date'] ?? null,
        'time' => $data['event_time'] ?? null,
        'venue' => $data['event_venue'] ?? null,
        'image' => $data['event_image'] ?? null,
    ];

    $tickets = $data['tickets'] ?? [];
    $transaction = $data['transaction'] ?? [];
    $privacy = $data['privacy_policy_link'] ?? '#';
    $terms = $data['tc_link'] ?? '#';
    $year = $data['current_year'] ?? date('Y');

    $viewTicket = $data['view_ticket'] ?? null;
    $viewCoupons = $data['view_coupons'] ?? null;

    // CID inline images: data: URIs are stripped by many providers (e.g. SendGrid → Gmail).
    $logoPath = resource_path('views/emails/asset/ticketoc_thumbnail.png');
    if (is_readable($logoPath)) {
        $brandLogoSrc = $message->embed($logoPath);
    } elseif (is_readable(public_path('images/logo/ticketoc.png'))) {
        $brandLogoSrc = $message->embed(public_path('images/logo/ticketoc.png'));
    } else {
        $brandLogoSrc = asset('images/logo/ticketoc.png');
    }

    $eventImageSrc = $event['image'] ?? null;
    if (! empty($data['event_portrait_embed'])) {
        $emb = $data['event_portrait_embed'];
        $eventImageSrc = $message->embedData($emb['data'], $emb['cidName'], $emb['mime']);
    }
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $emailPageTitle }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #0b0b0b;
            color: #f3f3f3;
            margin: 0;
            padding: 0;
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
        .header img {
            max-width: 200px;
        }
        .content {
            padding: 24px;
            color: #fff;
            line-height: 1.55;
        }
        h2 {
            color: #FFD700;
            margin-bottom: 10px;
        }
        .countdown-pill {
            display: inline-block;
            background: #1a1a1a;
            border: 1px solid #FFD70055;
            color: #FFD700;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 14px;
        }
        .event-card {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            padding: 18px 20px;
            margin: 18px 0 22px;
            color: #f3f3f3;
        }
        .event-card strong { color: #FFD700; }
        .footer {
            background: #000;
            color: #888;
            text-align: center;
            padding: 16px;
            font-size: 12px;
            border-top: 1px solid #222;
        }
        .footer a {
            color: #FFD700;
            text-decoration: none;
        }
        .btn {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
        .btn-primary {
            background: #2563eb;
            color: #ffffff;
        }
        .qr-box {
            display: block;
            text-align: center;
            background: radial-gradient(circle at 70% 30%, #FFD70055, #000000 80%);
            border-radius: 10px;
            padding: 8px;
        }
        .qr-box img {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            background: #fff;
            display: block;
            margin: 0 auto;
        }
        .status {
            display: inline-block;
            background: #16a34a;
            color: #fff;
            border-radius: 12px;
            padding: 3px 10px;
            font-size: 11px;
            text-transform: uppercase;
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
        @if($reminderType === '7d')
            <span class="countdown-pill">7 days to go</span>
        @elseif($reminderType === '48h')
            <span class="countdown-pill">48 hours to go</span>
        @elseif($reminderType === '12h')
            <span class="countdown-pill">12 hours to go</span>
        @endif

        <h2 style="color:#FFFFFF !important; margin-bottom: 6px;">{{ $event['name'] }}</h2>

        <p style="margin:8px 0 16px; color:#e5e5e5;">{!! $emailIntro !!}</p>

        @if(!empty($emailBody))
            <p style="margin:0 0 16px; color:#e5e5e5;">{!! $emailBody !!}</p>
        @endif

        <div class="event-card">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    @if(!empty($eventImageSrc))
                        <td valign="top" style="width:30%; max-width:200px; padding-right:14px;">
                            <img
                                src="{{ $eventImageSrc }}"
                                alt="Event Image"
                                width="200"
                                style="display:block; width:100%; max-width:200px; height:auto; border-radius:8px;"
                            >
                        </td>
                    @endif
                    <td valign="top" style="color:#ffffff;">
                        <p style="margin:0; font-size:14px; line-height:1.6;">
                            <strong style="color:#FFD700;">Event:</strong> {{ $event['name'] }}<br>
                            @if($event['date'])
                                <strong style="color:#FFD700;">Date:</strong> {{ $event['date'] }}<br>
                            @endif
                            @if($event['time'])
                                <strong style="color:#FFD700;">Time:</strong> {{ $event['time'] }}<br>
                            @endif
                            @if(!empty($event['venue']))
                                <strong style="color:#FFD700;">Venue:</strong> {{ $event['venue'] }}<br>
                            @endif
                            @if(!empty($transaction['order_number']))
                                <strong style="color:#FFD700;">Order Number:</strong> {{ $transaction['order_number'] }}
                            @endif
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        @if(!empty($viewTicket))
            <p style="margin:0 0 22px; color:#FFFFFF !important;">
                <a
                    href="{{ $viewTicket }}"
                    class="btn btn-primary"
                    target="_blank"
                    rel="noopener"
                    style="display:inline-block;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:600;background:#2563eb;color:#ffffff !important;"
                >
                    View Your Tickets
                </a>
            </p>
        @endif

        @if($showQrCodes && !empty($tickets))
            <p style="margin:0 0 12px; color:#e5e5e5;">
                Your ticket{{ count($tickets) > 1 ? 's are' : ' is' }} below. Show the QR code at the entrance for fast entry.
            </p>

            @foreach($tickets as $ticket)
                @php
                    $eventDate = $ticket['event_date'] ?? $event['date'];
                    $eventTime = $ticket['event_time'] ?? $event['time'];
                    $dayOfWeek = $eventDate ? \Carbon\Carbon::parse($eventDate)->format('l') : '';
                @endphp

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px; border:1px solid #2a2a2a; border-radius:12px; overflow:hidden; background:#1a1a1a;">
                    <tr>
                        <td valign="top" style="padding:18px 14px 18px 16px; color:#ffffff;">
                            <h3 style="margin:0 0 8px 0; color:#ffffff; font-size:16px;">{{ $event['name'] }}</h3>
                            <p style="margin:0; font-size:13px; color:#dddddd; line-height:1.5;">
                                <strong style="color:#FFD700;">Ticket Type:</strong> {{ strtoupper($ticket['eventTicket']['name'] ?? '-') }}<br>
                                @if(!empty($ticket['col']) || !empty($ticket['row']))
                                    <strong style="color:#FFD700;">Seat:</strong> {{ $ticket['col'] ?? '' }}-{{ $ticket['row'] ?? '' }}<br>
                                @endif
                                @if(!empty($ticket['raw_qr_code']))
                                    <strong style="color:#FFD700;">QR Code:</strong> {{ $ticket['raw_qr_code'] }}<br>
                                @endif
                                <strong style="color:#FFD700;">Status:</strong>
                                <span class="status">{{ $ticket['status'] ?? 'active' }}</span>
                            </p>
                        </td>
                        <td valign="top" style="padding:18px 12px; font-size:13px; color:#ffffff; line-height:1.4; width:160px;">
                            <strong style="display:block; color:#FFD700; font-size:14px;">{{ strtoupper($eventDate ?? '-') }}</strong>
                            <span style="display:block;">{{ strtoupper($dayOfWeek) }}</span>
                            <span style="display:block; color:#cccccc; font-size:12px;">{{ $eventTime ?? '' }}</span>
                        </td>
                        <td valign="top" align="center" style="width:140px; padding:18px 16px 18px 8px;">
                            <div class="qr-box" style="display:block;text-align:center;background:radial-gradient(circle at 70% 30%, #FFD70055, #000000 80%);border-radius:10px;padding:8px;">
                                @if(!empty($ticket['qr_png']))
                                    <img src="{{ $message->embedData($ticket['qr_png'], $ticket['qr_cid_name'] ?? 'qr.png', 'image/png') }}" alt="QR Code" width="120" height="120" style="width:120px;height:120px;border-radius:8px;background:#ffffff;display:block;margin:0 auto;border:0;outline:none;-ms-interpolation-mode:bicubic;" />
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>
            @endforeach
        @endif

        @if(!empty($data['has_ticket_file_attachments']) || !empty($data['has_coupon_attachments']))
            <p style="margin:0 0 14px; padding:12px 14px; background:#1a1a1a; border:1px solid #333; border-radius:8px; color:#e5e5e5; font-size:13px; line-height:1.55;">
                @if(!empty($data['has_ticket_file_attachments']) && !empty($data['has_coupon_attachments']))
                    Your ticket and voucher files are attached for offline use &mdash; the same layout as in the app.
                @elseif(!empty($data['has_ticket_file_attachments']))
                    Your ticket file is attached for offline use.
                @else
                    Your voucher files are attached for offline use.
                @endif
            </p>
        @endif

        @if(!empty($emailSignoff))
            <p style="margin:18px 0 4px; color:#e5e5e5;">{{ $emailSignoff }}</p>
        @endif

        @if(!empty($viewTicket) || !empty($viewCoupons))
            <p style="margin:6px 0 0; color:#bcbcbc; font-size:13px;">
                You can also open
                @if(!empty($viewTicket) && !empty($viewCoupons))
                    <a href="{{ $viewTicket }}" style="color:#FFD700;">your tickets</a> or
                    <a href="{{ $viewCoupons }}" style="color:#FFD700;">your vouchers</a> any time in your Ticketoc account.
                @elseif(!empty($viewTicket))
                    <a href="{{ $viewTicket }}" style="color:#FFD700;">your tickets</a> any time in your Ticketoc account.
                @else
                    <a href="{{ $viewCoupons }}" style="color:#FFD700;">your vouchers</a> any time in your Ticketoc account.
                @endif
            </p>
        @endif
    </div>

    <div class="footer">
        <p>{!! $emailFooterThanks !!}</p>
        <p>
            <a href="{{ $terms }}">Terms &amp; Conditions</a> |
            <a href="{{ $privacy }}">Privacy Policy</a><br>
            &copy; {{ $year }} Ticketoc. All Rights Reserved.
        </p>
    </div>
</div>
</body>
</html>
