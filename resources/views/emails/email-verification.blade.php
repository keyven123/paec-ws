@php
    $year = date('Y');
    $privacy = $privacy_policy_link ?? '#';
    $terms = $tc_link ?? '#';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $copy['subject_title'] }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #0b0b0b;
            color: #f3f3f3;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .container {
            max-width: 720px;
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
        .header h2 {
            color: #FFD700;
            margin: 16px 0 0;
            font-size: 1.35rem;
            font-weight: 600;
        }
        .content {
            padding: 24px;
            color: #fff;
        }
        .content p {
            color: #e5e5e5;
            margin: 0 0 14px;
            font-size: 15px;
        }
        .greeting {
            font-size: 18px;
            color: #fff;
            margin: 0 0 18px;
            font-weight: 600;
        }
        .verification-code {
            text-align: center;
            margin: 28px 0;
            padding: 24px 20px;
            background: #1a1a1a;
            border-radius: 12px;
            border: 1px solid #2a2a2a;
        }
        .verification-code .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #888;
            margin-bottom: 10px;
        }
        .code {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: 8px;
            color: #FFD700;
            font-family: 'Courier New', Consolas, monospace;
        }
        .instructions {
            background: #1a1a1a;
            padding: 18px 20px;
            border-radius: 12px;
            border: 1px solid #2a2a2a;
            margin: 22px 0;
        }
        .instructions strong {
            color: #FFD700;
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .instructions ul {
            margin: 0;
            padding-left: 20px;
            color: #ccc;
            font-size: 14px;
        }
        .instructions li {
            margin-bottom: 6px;
        }
        .notice {
            background: rgba(234, 179, 8, 0.12);
            border: 1px solid rgba(234, 179, 8, 0.35);
            color: #fde68a;
            padding: 14px 16px;
            border-radius: 10px;
            margin: 20px 0 0;
            font-size: 14px;
        }
        .notice strong {
            color: #fcd34d;
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
        .footer .salutation {
            color: #aaa;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="{{ asset('images/logo/ticketoc.png') }}" alt="{{ config('app.name') }}">
        <h2>Verify your email</h2>
    </div>

    <div class="content">
        <p class="greeting">
            {{ $copy['greeting'] }}
        </p>

        <p>{{ $copy['intro_line_1'] }}</p>
        <p>{{ $copy['intro_line_2'] }}</p>

        <div class="verification-code">
            <div class="label">Your verification code</div>
            <div class="code">{{ $confirmationToken->getPlainToken() }}</div>
        </div>

        <div class="instructions">
            <strong>How to verify</strong>
            <ul>
                <li>{{ $copy['instruction_copy'] }}</li>
                <li>{{ $copy['instruction_go'] }}</li>
                <li>{{ $copy['instruction_enter'] }}</li>
                <li>{{ $copy['instruction_click'] }}</li>
            </ul>
        </div>

        <div class="notice">
            {{ $copy['important'] }}
        </div>
    </div>

    <div class="footer">
        <p class="salutation">{!! $copy['salutation'] !!}</p>
        <p><small>{{ $copy['automated_message'] }}</small></p>
        <p style="margin-top:14px;">
            <a href="{{ $terms }}">Terms &amp; Conditions</a>
            &nbsp;|&nbsp;
            <a href="{{ $privacy }}">Privacy Policy</a><br>
            &copy; {{ $year }} {{ config('app.name') }}. All Rights Reserved.
        </p>
    </div>
</div>
</body>
</html>
