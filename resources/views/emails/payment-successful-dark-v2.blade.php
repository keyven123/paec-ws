@php
    $data = $data ?? [];
    $emailPageTitle = $data['email_page_title'] ?? 'Payment Successful';
    $emailHeadline = $data['email_headline'] ?? '🎟️ Payment Successful';
    $emailFooterThanks = $data['email_footer_thanks'] ?? 'Thank you for purchasing with <strong style="color:#FFD700;">Ticketoc</strong>!';
    $event = [
        'name' => $data['event_name'] ?? 'Event Name',
        'date' => $data['event_date'] ?? null,
        'time' => $data['event_time'] ?? null,
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
        .header img {
            max-width: 200px;
        }
        .content {
            padding: 24px;
            color: #fff;
        }
        h2 {
            color: #FFD700;
            margin-bottom: 10px;
        }
        .ticket-card {
            display: flex;
            align-items: stretch;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .ticket-image {
            flex: 0 0 35%;
            background-color: #000;
        }
        .ticket-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .ticket-body {
            flex: 1;
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .ticket-body h3 {
            margin: 0;
            color: #fff;
            font-size: 18px;
        }
        .ticket-details {
            font-size: 13.5px;
            color: #ddd;
        }
        .ticket-details strong { color: #FFD700; }
        .ticket-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-top: 8px;
        }
        .ticket-date {
            text-align: right;
            font-size: 13px;
        }
        .ticket-date strong {
            display: block;
            color: #FFD700;
            font-size: 14px;
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
        /* Mobile: stack ticket rows so Gmail / narrow clients don’t clip columns */
        @media only screen and (max-width: 600px) {
            .container {
                width: 100% !important;
                max-width: 100% !important;
                margin: 16px auto !important;
                border-radius: 0 !important;
            }
            .content {
                padding: 16px !important;
            }
            .payment-ticket-table {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed !important;
            }
            .payment-ticket-table tr {
                width: 100% !important;
            }
            .payment-ticket-table .ticket-stack {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            .payment-ticket-table .ticket-stack.ticket-img {
                width: 100% !important;
                max-width: 100% !important;
                text-align: center !important;
            }
            .payment-ticket-table .ticket-stack.ticket-img img {
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                margin: 0 auto !important;
            }
            .payment-ticket-table .ticket-stack.ticket-details,
            .payment-ticket-table .ticket-stack.ticket-datetime {
                padding: 16px !important;
            }
            .payment-ticket-table .ticket-stack.ticket-datetime {
                text-align: center !important;
                border-top: 1px solid #2a2a2a !important;
            }
            .payment-ticket-table .ticket-stack.ticket-qr {
                padding: 16px !important;
                text-align: center !important;
                border-top: 1px solid #2a2a2a !important;
            }
            .payment-ticket-table .ticket-stack.ticket-qr .qr-box {
                display: inline-block !important;
                max-width: 100% !important;
            }
            .payment-ticket-table .ticket-stack.ticket-qr .qr-box img {
                width: 200px !important;
                max-width: 85vw !important;
                height: auto !important;
            }
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
        <h2 style="color:#FFFFFF !important; margin-bottom: 10px;">{{ $event['name'] }}</h2>
        @if(!empty($viewTicket))
            <p style="margin:16px 0 22px; color:#FFFFFF !important;">
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

        @if(!empty($data['has_ticket_file_attachments']))
            <p style="margin:0 0 18px; padding:12px 14px; background:#1a1a1a; border:1px solid #333; border-radius:8px; color:#e5e5e5; font-size:14px;">
                Printable ticket files are attached to this email (PNG when your server supports it, otherwise PDF)—same layout as your tickets in the app.
            </p>
        @endif

        @if($event['date'] || $event['time'])
            <p>
                @if($event['date'])
                    <strong>Date:</strong> {{ $event['date'] }}<br>
                @endif
                @if($event['time'])
                    <strong>Time:</strong> {{ $event['time'] }}
                @endif
            </p>
        @endif

        @foreach($tickets as $ticket)
            @php
                $eventDate = $ticket['event_date'] ?? $event['date'];
                $eventTime = $ticket['event_time'] ?? $event['time'];
                $dayOfWeek = $eventDate ? \Carbon\Carbon::parse($eventDate)->format('l') : '';
            @endphp

            <div style="width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <table role="presentation" class="payment-ticket-table" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;background:#1a1a1a;width:100%;max-width:100%;table-layout:auto;">
                <tr>
                    <td class="ticket-stack ticket-img" valign="top" style="width:24%;max-width:200px;background:#000;vertical-align:top;">
                        @if(!empty($eventImageSrc))
                            <img
                                src="{{ $eventImageSrc }}"
                                alt="Event Image"
                                width="200"
                                style="display:block;width:100%;max-width:200px;height:auto;border:0;outline:none;-ms-interpolation-mode:bicubic;"
                            >
                        @else
                            <div style="min-height:140px;padding:36px 8px;color:#525252;font-size:11px;text-align:center;">Event Image</div>
                        @endif
                    </td>
                    <td class="ticket-stack ticket-details" valign="top" style="padding:18px 14px 18px 16px;color:#ffffff;vertical-align:top;word-break:break-word;overflow-wrap:break-word;">
                        <h3 style="margin:0 0 8px 0;color:#ffffff;font-size:18px;word-break:break-word;">{{ $event['name'] }}</h3>
                        <p style="margin:0;font-size:13.5px;color:#dddddd;line-height:1.45;word-break:break-word;overflow-wrap:break-word;">
                            <strong style="color:#FFD700;">Venue:</strong> — <br>
                            <strong style="color:#FFD700;">Ticket Type:</strong> {{ strtoupper($ticket['eventTicket']['name'] ?? '-') }}<br>
                            <strong style="color:#FFD700;">Price:</strong> ₱{{ number_format($ticket['eventTicket']['price'] ?? 0, 2) }}<br>
                            <strong style="color:#FFD700;">Order Number:</strong> {{ $transaction['order_number'] ?? '' }}<br>
                            <strong style="color:#FFD700;">Purchased:</strong> {{ $ticket['purchased_at'] ?? 'N/A' }}<br>
                            <strong style="color:#FFD700;">Seat:</strong> {{ $ticket['col'] ?? '' }}-{{ $ticket['row'] ?? '' }}<br>
                            <strong style="color:#FFD700;">QR Code:</strong> {{ $ticket['raw_qr_code'] ?? '' }}<br>
                            <strong style="color:#FFD700;">Status:</strong>
                            <span style="display:inline-block;background:#16a34a;color:#fff;border-radius:12px;padding:3px 10px;font-size:11px;text-transform:uppercase;">{{ $ticket['status'] ?? 'active' }}</span>
                        </p>
                    </td>
                    <td class="ticket-stack ticket-datetime" valign="top" style="padding:18px 12px;font-size:13px;color:#ffffff;line-height:1.4;vertical-align:top;word-break:break-word;">
                        <strong style="display:block;color:#FFD700;font-size:14px;">{{ strtoupper($eventDate ?? '-') }}</strong>
                        <span style="display:block;">{{ strtoupper($dayOfWeek) }}</span>
                        <span style="display:block;color:#cccccc;font-size:12px;">{{ $eventTime ?? '' }}</span>
                    </td>
                    <td class="ticket-stack ticket-qr" valign="top" align="center" style="width:132px;padding:18px 16px 18px 8px;vertical-align:top;">
                        <div class="qr-box" style="display:block;text-align:center;background:radial-gradient(circle at 70% 30%, #FFD70055, #000000 80%);border-radius:10px;padding:8px;max-width:100%;box-sizing:border-box;">
                            @if(!empty($ticket['qr_png']))
                                <img src="{{ $message->embedData($ticket['qr_png'], $ticket['qr_cid_name'] ?? 'qr.png', 'image/png') }}" alt="QR Code" width="120" height="120" style="width:120px;height:120px;max-width:100%;border-radius:8px;background:#ffffff;display:block;margin:0 auto;border:0;outline:none;-ms-interpolation-mode:bicubic;" />
                            @endif
                        </div>
                    </td>
                </tr>
                </table>
            </div>
        @endforeach

        <p style="margin-top:20px;">
            <strong>Order Total:</strong> ₱{{ number_format($transaction['total_amount'] ?? 0, 2) }}<br>
            <strong>Paid At:</strong> {{ $transaction['paid_at'] ?? '' }}
        </p>

        <div style="margin-top:20px; padding:12px 14px; background:#1a1a1a; border:1px solid #333; border-radius:8px; color:#e5e5e5; font-size:13px; line-height:1.55;">
            <p style="margin:0 0 10px 0;">
                NOTE: Present the QR code at the entrance; it serves as your entry ticket.
            </p>
            @if(!empty($viewTicket) || !empty($viewCoupons))
                <p style="margin:0 0 10px 0;">
                    If any QR code is missing from this email, open
                    @if(!empty($viewTicket) && !empty($viewCoupons))
                        <a href="{{ $viewTicket }}" style="color:#FFD700;">Tickets</a> for your entry pass or <a href="{{ $viewCoupons }}" style="color:#FFD700;">Coupons</a> for your benefits.
                    @elseif(!empty($viewTicket))
                        <a href="{{ $viewTicket }}" style="color:#FFD700;">Tickets</a> to view your entry QR code.
                    @else
                        <a href="{{ $viewCoupons }}" style="color:#FFD700;">Coupons</a> to view your coupon QR codes.
                    @endif
                </p>
            @endif
            @if(!empty($data['has_ticket_file_attachments']) || !empty($data['has_coupon_attachments']))
                <p style="margin:0;">
                    @if(!empty($data['has_ticket_file_attachments']) && !empty($data['has_coupon_attachments']))
                        You can also download the attached ticket and coupon files and use them as your digital tickets and coupons.
                    @elseif(!empty($data['has_ticket_file_attachments']))
                        You can also download the attached ticket files and use them as your digital tickets.
                    @else
                        You can also download the attached coupon files for your add-on benefits.
                    @endif
                </p>
            @endif
        </div>
    </div>

    <div class="footer">
        <p>{!! $emailFooterThanks !!}<br>
        Please bring this email or download the ticket files</p>
        <p>
            <a href="{{ $terms }}">Terms & Conditions</a> |
            <a href="{{ $privacy }}">Privacy Policy</a><br>
            &copy; {{ $year }} Ticketoc. All Rights Reserved.
        </p>
    </div>
</div>
</body>
</html>
