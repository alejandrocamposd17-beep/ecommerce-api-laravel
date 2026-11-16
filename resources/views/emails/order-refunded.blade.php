@extends('emails.layout')
@section('title', 'Reembolso procesado')
@section('body')
    <p>Hola {{ $order->user->name }}, reembolsamos el pago de tu orden. El dinero puede tardar de 5 a 10 días hábiles en verse reflejado en tu tarjeta.</p>
@endsection
