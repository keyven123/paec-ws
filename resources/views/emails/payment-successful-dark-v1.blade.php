@php
    $data = $data ?? [];
    $event = [
        'name' => $data['event_name'] ?? 'Event',
        'date' => $data['event_date'] ?? null,
        'time' => $data['event_time'] ?? null,
        'image' => data_get($data, 'event_image.url'),
    ];
    $orders = $data['orders'] ?? [];
    $transaction = $data['transaction'] ?? [];
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Successful</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #0d0d0d;
            color: #f3f3f3;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 680px;
            margin: 40px auto;
            background-color: #111;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            border: 1px solid #222;
        }
        .header {
            text-align: center;
            padding: 24px;
            background-color: #000;
            border-bottom: 1px solid #222;
        }
        .header img.logo {
            max-width: 220px;
            margin-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            color: #FFD700;
            letter-spacing: 0.5px;
        }
        .hero {
            background: #0f0f0f;
            border-bottom: 1px solid #222;
        }
        .hero img.event-img {
            display: block;
            width: 100%;
            height: auto;
            max-height: 340px;
            object-fit: cover;
        }
        .content {
            padding: 24px;
        }
        .section {
            margin-bottom: 28px;
        }
        h2, h3 {
            color: #FFD700;
            margin-bottom: 8px;
        }
        p { color: #ddd; margin: 6px 0; }
        .ticket {
            border: 1px solid #333;
            border-radius: 8px;
            margin-bottom: 14px;
            padding: 16px;
            background-color: #1a1a1a;
        }
        .ticket h3 {
            margin-top: 0;
            color: #fff;
            font-size: 17px;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 14px;
        }
        .details-table th, .details-table td {
            padding: 8px 12px;
            border: 1px solid #2a2a2a;
        }
        .details-table th {
            background: #181818;
            color: #FFD700;
            text-align: left;
        }
        .details-table td { color: #e6e6e6; }
        .footer {
            background-color: #000;
            color: #aaa;
            text-align: center;
            padding: 16px;
            font-size: 13px;
            border-top: 1px solid #222;
        }
        .status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            color: #fff;
            font-size: 12px;
            text-transform: uppercase;
        }
        .status.paid { background-color: #16a34a; }
        .status.confirmed { background-color: #0284c7; }
        .status.inactive { background-color: #dc2626; }
        .currency {
            color: #FFD700;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Header with Logo -->
    <div class="header">
        <img class="logo" src="{{ asset('images/logo/ticketoc.png') }}" alt="Ticketoc Logo">
        <h1>🎟️ Payment Successful</h1>
    </div>

    <!-- Event Banner Image (optional) -->
    @if(!empty($event['image']))
        <div class="hero">
            <img class="event-img" src="{{ $event['image'] }}" alt="{{ $event['name'] }} banner">
        </div>
    @endif

    <!-- Content -->
    <div class="content">
        <!-- Event Info -->
        <div class="section">
            <h2>{{ $event['name'] }}</h2>
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
        </div>

        <!-- Orders -->
        <div class="section">
            <h3>Order Details</h3>
            @forelse ($orders as $order)
                <div class="ticket">
                    <h3>Order #{{ $loop->iteration }}</h3>
                    <p>Quantity: {{ $order['quantity'] ?? 0 }}</p>
                    <p>Price: <span class="currency">₱{{ number_format($order['price'] ?? 0, 2) }}</span></p>
                    <p>Total: <span class="currency">₱{{ number_format($order['total_amount'] ?? 0, 2) }}</span></p>

                    @if(!empty($order['seats']))
                        <table class="details-table">
                            <thead>
                                <tr>
                                    <th>Row</th>
                                    <th>Seat No</th>
                                    <th>Category</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order['seats'] as $seat)
                                    <tr>
                                        <td>{{ $seat['row'] ?? '-' }}</td>
                                        <td>{{ $seat['seat_no'] ?? '-' }}</td>
                                        <td>{{ strtoupper($seat['category'] ?? '-') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p style="margin-top:8px;color:#999;font-size:14px;">No seat assigned</p>
                    @endif
                </div>
            @empty
                <p>No orders found.</p>
            @endforelse
        </div>

        <!-- Transaction Summary -->
        <div class="section">
            <h3>Transaction Summary</h3>
            <table class="details-table">
                <tbody>
                    <tr>
                        <th>Order Number</th>
                        <td>{{ $transaction['order_number'] ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <th>Total Amount</th>
                        <td><span class="currency">₱{{ number_format($transaction['total_amount'] ?? 0, 2) }}</span></td>
                    </tr>
                    <tr>
                        <th>Payment Status</th>
                        <td>
                            <span class="status paid">{{ ucfirst($transaction['payment_status'] ?? 'unknown') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Order Status</th>
                        <td>
                            <span class="status confirmed">{{ ucfirst($transaction['order_status'] ?? 'pending') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Paid At</th>
                        <td>{{ $transaction['paid_at'] ?? 'N/A' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>Thank you for your purchase with <strong style="color:#FFD700;">Ticketoc</strong>!<br>
        Please present this confirmation email and a valid ID upon entry.</p>
    </div>
</div>
</body>
</html>
