@extends('emails.paec.layout')

@section('preheader')
    Your PAEC cart still has {{ $data['activity_count_label'] ?? 'tickets' }} waiting. Complete your payment to get your tickets.
@endsection

@section('content')
    {{-- Hero --}}
    <tr>
        <td align="center" class="px" style="padding:20px 40px 8px 40px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                    <td align="center" valign="middle" width="56" height="56" style="width:56px; height:56px; border-radius:28px; background:#ffedd5; color:#f97316; font-size:26px; line-height:56px; font-weight:700;">&#127915;</td>
                </tr>
            </table>
            <p style="margin:16px 0 0 0; font-size:12px; letter-spacing:1.5px; text-transform:uppercase; font-weight:700; color:#f97316;">Waiting for payment</p>
            <h1 class="h1" style="margin:6px 0 0 0; font-size:28px; line-height:34px; font-weight:800; color:#1e1b4b;">Your tickets are still in your cart</h1>
            <p style="margin:12px 0 0 0; font-size:15px; line-height:24px; color:#4b5563;">
                Hi {{ $data['customer_name'] ?? 'there' }},<br>
                You left {{ $data['activity_count_label'] ?? 'tickets' }} in your cart. Complete your payment to confirm your booking.
            </p>
        </td>
    </tr>

    {{-- Not reserved yet --}}
    <tr>
        <td class="px" style="padding:20px 40px 0 40px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff7ed; border-radius:12px;">
                <tr>
                    <td style="padding:14px 18px; font-size:14px; line-height:22px; color:#9a3412;">
                        <strong>Your tickets are not reserved yet.</strong>
                        They are only confirmed once payment goes through, so popular dates may sell out.
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Cart items --}}
    <tr>
        <td class="px" style="padding:24px 40px 8px 40px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #ede9fe; border-radius:16px;">
                <tr>
                    <td style="padding:20px 22px 6px 22px;">
                        <p style="margin:0; font-size:11px; letter-spacing:1.2px; text-transform:uppercase; font-weight:700; color:#7c3aed;">Your cart</p>
                    </td>
                </tr>
                @foreach ($data['items'] ?? [] as $item)
                    <tr>
                        <td style="padding:12px 22px; {{ $loop->first ? '' : 'border-top:1px solid #f3f0ff;' }}">
                            <p style="margin:0; font-size:15px; line-height:22px; font-weight:700; color:#1e1b4b;">{{ $item['name'] }}</p>
                            @if (! empty($item['location']))
                                <p style="margin:2px 0 0 0; font-size:13px; line-height:20px; color:#6b7280;">{{ $item['location'] }}</p>
                            @endif
                            <p style="margin:6px 0 0 0; font-size:13px; line-height:20px; color:#4b5563;">
                                <span style="color:#7c3aed; font-weight:600;">{{ $item['visit_date'] }}</span>
                                &nbsp;·&nbsp; {{ $item['tickets'] }}
                            </p>
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td style="padding:0 0 10px 0; font-size:0; line-height:0;">&nbsp;</td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Next step --}}
    <tr>
        <td align="center" class="px" style="padding:24px 40px 20px 40px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                    <td align="center" style="border-radius:12px; background:#f97316;">
                        <a href="{{ $data['checkout_url'] ?? '#' }}" style="display:inline-block; padding:14px 30px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:12px;">Complete my booking</a>
                    </td>
                </tr>
            </table>
            <p style="margin:14px 0 0 0; font-size:12px; line-height:18px; color:#6b7280;">Sign in with {{ $data['email'] ?? 'your account' }} to see your cart.</p>
        </td>
    </tr>
@endsection
