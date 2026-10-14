@extends('emails.paec.layout')

@php
    $details = array_filter([
        'Order number' => $data['order_number'] ?? null,
        'Activity' => $data['event_name'] ?? null,
        'Ordered on' => $data['ordered_at'] ?? null,
        'Cancelled on' => $data['cancelled_at'] ?? null,
        'Payment method' => $data['payment_method'] ?? null,
    ], fn ($value) => filled($value));
@endphp

@section('preheader')
    Order {{ $data['order_number'] ?? '' }} for {{ $data['event_name'] ?? 'your booking' }} was cancelled. No payment was collected.
@endsection

@section('content')
    {{-- Hero --}}
    <tr>
        <td align="center" class="px" style="padding:20px 40px 8px 40px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                    <td align="center" valign="middle" width="56" height="56" style="width:56px; height:56px; border-radius:28px; background:#fee2e2; color:#dc2626; font-size:26px; line-height:56px; font-weight:700;">&#10005;</td>
                </tr>
            </table>
            <p style="margin:16px 0 0 0; font-size:12px; letter-spacing:1.5px; text-transform:uppercase; font-weight:700; color:#dc2626;">Order cancelled</p>
            <h1 class="h1" style="margin:6px 0 0 0; font-size:28px; line-height:34px; font-weight:800; color:#1e1b4b;">Your order was cancelled</h1>
            <p style="margin:12px 0 0 0; font-size:15px; line-height:24px; color:#4b5563;">
                Hi {{ $data['customer_name'] ?? 'there' }},<br>{{ $data['reason'] ?? '' }}
            </p>
        </td>
    </tr>

    {{-- No charge --}}
    <tr>
        <td class="px" style="padding:20px 40px 0 40px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4; border-radius:12px;">
                <tr>
                    <td style="padding:14px 18px; font-size:14px; line-height:22px; color:#166534;">
                        <strong>No payment was collected for this order.</strong>
                        No tickets were issued, and the reserved tickets have been released.
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Order details --}}
    <tr>
        <td class="px" style="padding:24px 40px 8px 40px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #ede9fe; border-radius:16px;">
                <tr>
                    <td style="padding:20px 22px 6px 22px;">
                        <p style="margin:0; font-size:11px; letter-spacing:1.2px; text-transform:uppercase; font-weight:700; color:#7c3aed;">Order details</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 22px 6px 22px;">
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
                <tr>
                    <td style="padding:6px 22px 18px 22px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf5ff; border-radius:12px;">
                            @foreach ($data['items'] ?? [] as $item)
                                <tr>
                                    <td style="padding:10px 14px 0 14px; font-size:13px; color:#4b5563;">
                                        {{ $item['name'] }} × {{ $item['quantity'] }}
                                        @if (! empty($item['visit_date']))
                                            <span style="color:#9ca3af;">· {{ $item['visit_date'] }}</span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 14px 0 14px; font-size:13px; color:#4b5563; text-align:right; white-space:nowrap;">{{ $item['total'] }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="padding:10px 14px 12px 14px; font-size:14px; font-weight:700; color:#1e1b4b;">Order total</td>
                                <td style="padding:10px 14px 12px 14px; font-size:14px; font-weight:700; color:#1e1b4b; text-align:right;"><s style="color:#9ca3af; font-weight:600;">{{ $data['total'] ?? '' }}</s> &nbsp;₱0.00 charged</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Next step --}}
    <tr>
        <td align="center" class="px" style="padding:24px 40px 20px 40px;">
            <p style="margin:0 0 16px 0; font-size:14px; line-height:22px; color:#4b5563;">Still want to go? You can place a new order any time.</p>
            <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                    <td align="center" style="border-radius:12px; background:#7c3aed;">
                        <a href="{{ $data['browse_url'] ?? '#' }}" style="display:inline-block; padding:14px 30px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:12px;">Book again</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
@endsection
