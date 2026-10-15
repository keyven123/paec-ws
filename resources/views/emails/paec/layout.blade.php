@php
    $data = $data ?? [];
    // CID inline images: data: URIs are stripped by many providers (e.g. Gmail).
    $logoPath = resource_path('views/emails/asset/paec-logo.png');
    $logoSrc = isset($message) ? $message->embed($logoPath) : asset('images/logo/paec-logo.png');
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $data['email_page_title'] ?? 'PAEC' }}</title>
    <style type="text/css">
        body { margin: 0; padding: 0; width: 100% !important; background: #f5f3ff; -webkit-text-size-adjust: 100%; }
        table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a { color: #7c3aed; }
        @media only screen and (max-width: 620px) {
            .container { width: 100% !important; }
            .px { padding-left: 20px !important; padding-right: 20px !important; }
            .stack-cell { display: block !important; width: 100% !important; box-sizing: border-box; }
            .qr-cell { padding: 16px 18px 0 18px !important; text-align: left !important; }
            .h1 { font-size: 24px !important; line-height: 30px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#f5f3ff; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1e1b4b;">
<div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">@yield('preheader')</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ff;">
    <tr>
        <td align="center" style="padding:32px 12px;">
            <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px;">
                <tr>
                    <td style="background:#ffffff; border-radius:20px; overflow:hidden; border:1px solid #ede9fe;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td height="6" style="height:6px; line-height:6px; font-size:0; background:#7c3aed; background-image:linear-gradient(90deg,#7c3aed 0%,#c026d3 55%,#f97316 100%);">&nbsp;</td>
                            </tr>
                            <tr>
                                <td align="center" class="px" style="padding:28px 40px 8px 40px;">
                                    <img src="{{ $logoSrc }}" alt="PAEC – Philippine Amusement and Entertainment Corporation" width="150" style="width:150px; max-width:150px; height:auto; display:block;">
                                </td>
                            </tr>

                            @yield('content')

                            <tr>
                                <td class="px" style="padding:8px 40px 32px 40px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf5ff; border-radius:14px;">
                                        <tr>
                                            <td style="padding:18px 20px; font-size:13px; line-height:20px; color:#4c1d95;">
                                                <strong style="display:block; font-size:14px; margin-bottom:2px;">Need help with your order?</strong>
                                                Reply to this email or write to
                                                <a href="mailto:{{ $data['support_email'] ?? '' }}" style="color:#7c3aed; font-weight:600; text-decoration:none;">{{ $data['support_email'] ?? '' }}</a>@if (! empty($data['order_number']))
                                                and include your order number <strong>{{ $data['order_number'] }}</strong>@endif.
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" class="px" style="padding:24px 40px 8px 40px; font-size:12px; line-height:18px; color:#6b7280;">
                        <strong style="color:#4c1d95;">Philippine Amusement and Entertainment Corporation</strong><br>
                        {{ $data['footer_reason'] ?? 'You received this email because an order was placed with this email address.' }}<br>
                        <a href="{{ $data['privacy_url'] ?? '#' }}" style="color:#7c3aed; text-decoration:none;">Privacy Policy</a>
                        &nbsp;·&nbsp;
                        <a href="{{ $data['terms_url'] ?? '#' }}" style="color:#7c3aed; text-decoration:none;">Terms &amp; Conditions</a>
                        <br>&copy; {{ $data['year'] ?? date('Y') }} PAEC. All rights reserved.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
