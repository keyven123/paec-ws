<html>
<body>
@foreach ($coupons as $coupon)
<div style="page-break-after: always;">
<h1>{{ $eventName }}</h1>
<p>{{ $coupon['name'] }}</p>
<p>{{ $coupon['qrText'] }}</p>
</div>
@endforeach
</body>
</html>
