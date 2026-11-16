@extends('emails.layout')
@section('title', 'Recibimos tu orden')
@section('body')
    <p>Hola {{ $order->user->name }}, tu orden quedó registrada y está pendiente de pago.</p>
@endsection
