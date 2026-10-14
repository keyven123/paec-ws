@extends('emails.paec.layout')

@php
    $tickets = $data['tickets'] ?? [];
    $ticketCount = count($tickets);
    $firstTicket = $tickets[0] ?? [];

    $eventImageSrc = null;
    if (! empty($data['event_portrait_embed']) && isset($message)) {
        $embed = $data['event_portrait_embed'];
        $eventImageSrc = $message->embedData($embed['data'], $embed['cidName'], $embed['mime']);
    } elseif (! empty($data['event_image'])) {
        $eventImageSrc = $data['event_image'];
    }

    $details = array_filter([
        'Order number' => $data['order_number'] ?? null,
        'Date of visit' => $firstTicket['visit_date'] ?? null,
        'Time' => $data['event_time'] ?? null,
        'Location' => $firstTicket['location'] ?? null,
        'Tickets' => $ticketCount > 0 ? $ticketCount . ' ' . \Illuminate\Support\Str::plural('ticket', $ticketCount) : null,
        'Payment method' => $data['payment_method'] ?? null,
        'Payment reference' => $data['payment_reference'] ?? null,
        'Paid on' => $data['paid_at'] ?? null,
    ], fn ($value) => filled($value));
@endphp

@section('preheader')
    {{ $data['headline'] ?? 'Your booking is confirmed' }} — {{ $data['event_name'] ?? '' }}, order {{ $data['order_number'] ?? '' }}. Your tickets are attached.
@endsection

@section('content')
    {{-- Hero --}}
    <tr>
        <td align="center" class="px" style="padding:20px 40px 8px 40px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                    <td align="center" valign="middle" width="56" height="56" style="width:56px; height:56px; border-radius:28px; background:#dcfce7; color:#16a34a; font-size:28px; line-height:56px; font-weight:700;">&#10003;</td>
                </tr>
            </table>
            <p style="margin:16px 0 0 0; font-size:12px; letter-spacing:1.5px; text-transform:uppercase; font-weight:700; color:#16a34a;">Payment successful</p>
            <h1 class="h1" style="margin:6px 0 0 0; font-size:28px; line-height:34px; font-weight:800; color:#1e1b4b;">{{ $data['headline'] ?? 'Your booking is confirmed' }}</h1>
            <p style="margin:12px 0 0 0; font-size:15px; line-height:24px; color:#4b5563;">
                Hi {{ $data['customer_name'] ?? 'there' }},<br>{{ $data['intro'] ?? '' }}
            </p>
        </td>
    </tr>

    {{-- Booking card --}}
    <tr>
        <td class="px" style="padding:24px 40px 8px 40px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #ede9fe; border-radius:16px; overflow:hidden;">
                @if ($eventImageSrc)
                    <tr>
                        <td style="line-height:0; font-size:0;">
                            <img src="{{ $eventImageSrc }}" alt="{{ $data['event_name'] ?? '' }}" width="518" style="width:100%; max-width:518px; height:auto; max-height:240px; object-fit:cover; display:block;">
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:20px 22px 6px 22px;">
                        <p style="margin:0; font-size:11px; letter-spacing:1.2px; text-transform:uppercase; font-weight:700; color:#7c3aed;">Your booking</p>
                        <p style="margin:4px 0 0 0; font-size:20px; line-height:26px; font-weight:800; color:#1e1b4b;">{{ $data['event_name'] ?? '' }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 22px 18px 22px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            @foreach ($details as $label => $value)
                                <tr>
                                    <td style="padding:7px 0; border-top:1px solid #f3f0ff; font-size:13px; color:#6b7280; width:42%;" valign="top">{{ $label }}</td>
                                    <td style="padding:7px 0; border-top:1px solid #f3f0ff; font-size:13px; color:#1e1b4b; font-weight:600; text-align:right; word-break:break-word;" valign="top">{{ $value }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Tickets --}}
    @if ($ticketCount > 0)
        <tr>
            <td class="px" style="padding:24px 40px 0 40px;">
                <p style="margin:0; font-size:18px; font-weight:800; color:#1e1b4b;">Your {{ \Illuminate\Support\Str::plural('ticket', $ticketCount) }}</p>
                <p style="margin:4px 0 0 0; font-size:13px; line-height:20px; color:#6b7280;">Show each ticket's QR code at the entrance.</p>
            </td>
        </tr>
        @foreach ($tickets as $index => $ticket)
            <tr>
                <td class="px" style="padding:12px 40px 0 40px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px dashed #c4b5fd; border-radius:16px; background:#fdfcff;">
                        <tr>
                            @if (! empty($ticket['qr_png']) && isset($message))
                                <td class="stack-cell qr-cell" width="140" valign="middle" align="center" style="width:140px; padding:16px 0 16px 16px;">
                                    <img src="{{ $message->embedData($ticket['qr_png'], $ticket['qr_cid_name'] ?? 'qr.png', 'image/png') }}" alt="Ticket QR code" width="124" height="124" style="width:124px; height:124px; display:block; border-radius:8px; background:#ffffff;">
                                </td>
                            @endif
                            <td class="stack-cell" valign="middle" style="padding:16px 18px;">
                                <p style="margin:0; font-size:11px; letter-spacing:1px; text-transform:uppercase; font-weight:700; color:#f97316;">Ticket {{ $index + 1 }} of {{ $ticketCount }}</p>
                                <p style="margin:4px 0 0 0; font-size:16px; font-weight:800; color:#1e1b4b;">{{ $ticket['type'] ?? 'Ticket' }}</p>
                                @if (! empty($ticket['ticket_number']))
                                    <p style="margin:4px 0 0 0; font-family:Consolas, 'Courier New', monospace; font-size:12px; color:#4b5563;">{{ $ticket['ticket_number'] }}</p>
                                @endif
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:10px;">
                                    @foreach (array_filter([
                                        'Guest' => $ticket['attendee_name'] ?? null,
                                        'Visit' => $ticket['visit_date'] ?? null,
                                        'Seat' => $ticket['seat'] ?? null,
                                    ], fn ($value) => filled($value)) as $label => $value)
                                        <tr>
                                            <td style="padding:2px 12px 2px 0; font-size:12px; color:#6b7280;">{{ $label }}</td>
                                            <td style="padding:2px 0; font-size:12px; color:#1e1b4b; font-weight:600;">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endforeach

        @if (! empty($data['has_ticket_file_attachments']))
            <tr>
                <td class="px" style="padding:14px 40px 0 40px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff7ed; border-radius:12px;">
                        <tr>
                            <td style="padding:12px 16px; font-size:13px; line-height:20px; color:#9a3412;">
                                <strong>&#128206; Your tickets are attached.</strong>
                                Save or print them so you can show them at the entrance, even without internet.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif
    @endif

    {{-- Order summary --}}
    <tr>
        <td class="px" style="padding:28px 40px 8px 40px;">
            <p style="margin:0 0 10px 0; font-size:18px; font-weight:800; color:#1e1b4b;">Order summary</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                @foreach ($data['items'] ?? [] as $item)
                    <tr>
                        <td style="padding:8px 0; border-bottom:1px solid #f3f0ff; font-size:14px; color:#1e1b4b;">
                            {{ $item['name'] }} <span style="color:#6b7280;">× {{ $item['quantity'] }}</span>
                            @if (! empty($item['visit_date']))
                                <br><span style="font-size:12px; color:#6b7280;">{{ $item['visit_date'] }}</span>
                            @endif
                        </td>
                        <td style="padding:8px 0; border-bottom:1px solid #f3f0ff; font-size:14px; color:#1e1b4b; text-align:right; white-space:nowrap;" valign="top">{{ $item['total'] }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td style="padding:10px 0 2px 0; font-size:13px; color:#6b7280;">Subtotal</td>
                    <td style="padding:10px 0 2px 0; font-size:13px; color:#6b7280; text-align:right;">{{ $data['sub_total'] ?? '' }}</td>
                </tr>
                @if (! empty($data['discount']))
                    <tr>
                        <td style="padding:2px 0; font-size:13px; color:#15803d;">Discount</td>
                        <td style="padding:2px 0; font-size:13px; color:#15803d; text-align:right;">−{{ $data['discount'] }}</td>
                    </tr>
                @endif
                @if (! empty($data['tax']))
                    <tr>
                        <td style="padding:2px 0; font-size:13px; color:#6b7280;">Taxes and fees</td>
                        <td style="padding:2px 0; font-size:13px; color:#6b7280; text-align:right;">{{ $data['tax'] }}</td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:10px 0 0 0; font-size:16px; font-weight:800; color:#1e1b4b;">Total paid</td>
                    <td style="padding:10px 0 0 0; font-size:16px; font-weight:800; color:#7c3aed; text-align:right;">{{ $data['total'] ?? '' }}</td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Next step --}}
    <tr>
        <td align="center" class="px" style="padding:24px 40px 20px 40px;">
            @if (empty($data['is_guest']))
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="center" style="border-radius:12px; background:#7c3aed;">
                            <a href="{{ $data['my_tickets_url'] ?? '#' }}" style="display:inline-block; padding:14px 30px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:12px;">View my tickets</a>
                        </td>
                    </tr>
                </table>
            @else
                <p style="margin:0; font-size:13px; line-height:20px; color:#6b7280;">
                    You checked out as a guest, so please keep this email. Sign up with this email address any time to see your tickets in your PAEC account.
                </p>
            @endif
        </td>
    </tr>
@endsection
