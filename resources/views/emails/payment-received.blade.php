@extends('emails.layout')
@section('title', 'Pago confirmado')
@section('body')
    <p>Hola {{ $order->user->name }}, recibimos el pago de tu orden. ¡Gracias por tu compra!</p>
@endsection
