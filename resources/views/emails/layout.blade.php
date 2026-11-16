<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>{{ config('app.name') }}</title></head>
<body style="font-family: Arial, sans-serif; color: #1f2937; max-width: 560px; margin: 0 auto; padding: 24px;">
    <h2 style="margin-top: 0;">@yield('title')</h2>
    @yield('body')
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        @foreach ($order->items as $item)
            <tr>
                <td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb;">{{ $item->quantity }} × {{ $item->product_name }}</td>
                <td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb; text-align: right;">${{ number_format((float) $item->subtotal, 2) }}</td>
            </tr>
        @endforeach
        @if ((float) $order->discount > 0)
            <tr><td style="padding: 6px 0;">Descuento</td><td style="text-align: right;">-${{ number_format((float) $order->discount, 2) }}</td></tr>
        @endif
        <tr><td style="padding: 6px 0;">IVA</td><td style="text-align: right;">${{ number_format((float) $order->tax, 2) }}</td></tr>
        <tr><td style="padding: 6px 0;"><strong>Total</strong></td><td style="text-align: right;"><strong>${{ number_format((float) $order->total, 2) }} {{ strtoupper($order->currency) }}</strong></td></tr>
    </table>
    <p style="color: #6b7280; font-size: 12px;">Orden {{ $order->order_number }} · {{ config('app.name') }}</p>
</body>
</html>
