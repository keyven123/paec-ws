@php
    $data = $data ?? [];
    $event = [
        'name' => $data['event_name'] ?? 'Event',
        'date' => $data['event_date'] ?? null,
        'time' => $data['event_time'] ?? null,
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
            background-color: #f5f5f5;
            color: #222;
            margin: 0;
            padding: 0;
        }
        .container {
            background: #fff;
            max-width: 640px;
            margin: 40px auto;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background-color: #000;
            color: #ffd700;
            text-align: center;
            padding: 24px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            letter-spacing: 0.5px;
        }
        .content {
            padding: 24px;
        }
        .content h2 {
            color: #333;
            font-size: 20px;
            margin-bottom: 8px;
        }
        .section {
            margin-bottom: 24px;
        }
        .ticket {
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 12px;
            padding: 16px;
            background-color: #fafafa;
        }
        .ticket h3 {
            margin-top: 0;
            font-size: 18px;
            color: #000;
        }
        .ticket small {
            color: #555;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        .details-table th, .details-table td {
            padding: 8px 12px;
            border: 1px solid #eee;
        }
        .details-table th {
            background: #f9f9f9;
            text-align: left;
        }
        .footer {
            background-color: #111;
            color: #aaa;
            text-align: center;
            padding: 16px;
            font-size: 13px;
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
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎟️ Payment Successful</h1>
    </div>

    <div class="content">
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

        <div class="section">
            <h3>Order Details</h3>
            @foreach ($orders as $order)
                <div class="ticket">
                    <h3>Order #{{ $loop->iteration }}</h3>
                    <small>Quantity: {{ $order['quantity'] ?? 0 }}</small><br>
                    <small>Price: ₱{{ number_format($order['price'] ?? 0, 2) }}</small><br>
                    <small>Total: ₱{{ number_format($order['total_amount'] ?? 0, 2) }}</small>

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
                        <p style="margin-top:8px;color:#777;font-size:14px;">No seat assigned</p>
                    @endif
                </div>
            @endforeach
        </div>

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
                        <td>₱{{ number_format($transaction['total_amount'] ?? 0, 2) }}</td>
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

    <div class="footer">
        <p>Thank you for your purchase!<br>
        Please present this confirmation and your valid ID upon entry.</p>
    </div>
</div>
</body>
</html>
