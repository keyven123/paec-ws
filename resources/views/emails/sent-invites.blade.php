<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Organization Invite - {{ config('app.name') }}</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      background-color: #0d0d0d;
      font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: #f5f5f5;
    }
    .container {
      max-width: 640px;
      margin: 40px auto;
      background-color: #1a1a1a;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
      border: 1px solid #2d2d2d;
    }
    .header {
      background: linear-gradient(135deg, #000000, #1a1a1a);
      color: #ffd700;
      text-align: center;
      padding: 30px 20px;
      border-bottom: 2px solid #d4af37;
    }
    .header .logo {
      font-size: 28px;
      font-weight: 700;
      letter-spacing: 0.5px;
      color: #ffcc00;
    }
    .content {
      padding: 32px;
      line-height: 1.7;
      color: #f5f5f5;
    }
    .greeting {
      font-size: 20px;
      font-weight: 600;
      color: #ffd700;
      margin-bottom: 16px;
    }
    p {
      margin: 0 0 12px 0;
      color: #d1d1d1;
    }
    .cta {
      text-align: center;
      margin: 32px 0;
    }
    .cta a {
      display: inline-block;
      padding: 14px 32px;
      background: linear-gradient(90deg, #d4af37, #ffd700);
      color: #000000;
      font-weight: 700;
      border-radius: 6px;
      text-decoration: none;
      transition: all 0.25s ease;
      box-shadow: 0 3px 10px rgba(212, 175, 55, 0.3);
    }
    .cta a:hover {
      background: linear-gradient(90deg, #ffcc00, #e6b800);
      transform: scale(1.03);
    }
    .instructions {
      background-color: #262626;
      border-left: 4px solid #d4af37;
      border-radius: 6px;
      padding: 16px 20px;
      margin: 24px 0;
    }
    .instructions strong {
      display: block;
      font-size: 16px;
      margin-bottom: 8px;
      color: #ffcc00;
    }
    .instructions ul {
      margin: 0;
      padding-left: 20px;
      color: #f5f5f5;
    }
    .instructions li {
      margin-bottom: 6px;
    }
    .warning {
      background-color: #332b00;
      border: 1px solid #d4af37;
      color: #ffcc00;
      border-radius: 6px;
      padding: 14px 18px;
      margin: 20px 0;
    }
    .footer {
      background-color: #0d0d0d;
      text-align: center;
      font-size: 14px;
      color: #b3b3b3;
      padding: 24px;
      border-top: 1px solid #2d2d2d;
    }
    .footer p {
      margin: 4px 0;
    }
    @media (max-width: 600px) {
      .content { padding: 24px 18px; }
      .cta a { padding: 12px 24px; font-size: 15px; }
    }
  </style>
</head>
<body>
  <div class="container">
    <!-- Header -->
    <div class="header">
      <div class="logo">{{ config('app.name') }}</div>
    </div>

    <!-- Body -->
    <div class="content">
      <div class="greeting">{{ $data['greeting'] }}</div>

      <p>{{ $data['intro_line_1'] }}</p>
      <p>{{ $data['intro_line_2'] }}</p>

      <!-- CTA -->
      <div class="cta">
        <a href="{{ $data['url'] }}" target="_blank">{{ $data['button'] }}</a>
      </div>

      <p>If the button above doesn’t work, copy and paste this link into your browser:</p>
      <p style="word-break: break-all; color: #ffd700;">{{ $data['url'] }}</p>

      <div class="instructions">
        <strong>How to accept your invite:</strong>
        <ul>
          <li>{{ $data['instructions']['button'] }}</li>
          <li>{{ $data['instructions']['fill_up_details'] }}</li>
          <li>{{ $data['instructions']['submit'] }}</li>
          <li>{{ $data['instructions']['success'] }}</li>
        </ul>
      </div>

      <div class="warning">
        <strong>Important:</strong> {{ $data['important'] }}
      </div>

      <p>{{ $data['outro_line_1'] }}</p>
      <p>{{ $data['outro_line_2'] }}</p>
      <p>{{ $data['outro_line_3'] }}</p>
    </div>

    <!-- Footer -->
    <div class="footer">
      <p>{{ $data['salutation'] }}</p>
      <p><strong style="color: #ffd700;">{{ config('app.name') }} Team</strong></p>
      <p><small>{{ $data['automated_message'] }}</small></p>
    </div>
  </div>
</body>
</html>
